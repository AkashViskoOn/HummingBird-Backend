<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectExternalMessageModel;
use App\Models\Connect\ConnectServiceIdentityModel;
use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use Throwable;

/**
 * Humming Bird Connect — background message processor (Phase 5 worker).
 *
 * Claims durably-queued outbox records and turns them into real Humming Bird
 * messages through the EXISTING message pipeline:
 *
 *   claim outbox row (atomic, short txn)
 *     → re-resolve tenant / environment / mapping / service identity / room
 *       from the PERSISTED tracking record (never from external input)
 *     → create the existing Humming Bird message (idempotent via
 *       connect_external_messages.message_id)
 *     → update Connect tracking + delivery
 *     → mark outbox completed
 *
 * Reuses the existing Socket.IO + FCM flows. Socket/FCM failures never fail
 * the message (the DB row is already persisted; the existing history sync will
 * deliver on reconnect). Retryable failures (transient DB, deadlock, timeout)
 * are backed off exponentially; permanent failures (invalid mapping, inactive
 * user, missing payload) fail fast after the configured max attempts.
 */
class ConnectMessageProcessor
{
    private ConnectMessageService    $service;
    private ConnectOutboxService     $outbox;
    private ConnectExternalMessageModel $messages;
    private ConnectServiceIdentityModel $serviceIdentities;
    private UserModel                $users;
    private ConnectMessageStatusService $statusService;

    public function __construct(
        ?ConnectMessageService $service = null,
        ?ConnectOutboxService $outbox = null,
        ?ConnectExternalMessageModel $messages = null,
        ?ConnectServiceIdentityModel $serviceIdentities = null,
        ?UserModel $users = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->service            = $service ?? new ConnectMessageService();
        $this->outbox             = $outbox ?? new ConnectOutboxService();
        $this->messages           = $messages ?? new ConnectExternalMessageModel($db);
        $this->serviceIdentities  = $serviceIdentities ?? new ConnectServiceIdentityModel($db);
        $this->users              = $users ?? new UserModel($db);
        $this->statusService      = new ConnectMessageStatusService();
    }

    /**
     * Run one worker pass: recover stale jobs, then process up to `$batchSize`
     * due jobs. Returns number of jobs processed in this pass.
     */
    public function run(int $batchSize): int
    {
        $workerId     = $this->workerId();
        $staleTimeout = $this->config()->workerStaleTimeout;

        $recovered = $this->outbox->recoverStale($workerId, $staleTimeout);
        if ($recovered > 0) {
            log_message('info', "Connect worker recovered {$recovered} stale outbox jobs");
        }

        $claimed = $this->outbox->claimBatch($batchSize, $workerId);
        $processed = 0;

        foreach ($claimed as $row) {
            try {
                $this->processClaimed($row);
                $processed++;
            } catch (Throwable $e) {
                // Claimed rows are handled robustly inside processClaimed; a
                // genuinely unexpected exception must not kill the pass — roll
                // the job back to retrying so it is not stuck in 'processing'.
                try {
                    $this->outbox->markRetrying(
                        (int) $row['id'],
                        ConnectConfig::OUTBOX_ERR_PROCESSING_FAILED,
                        'Worker exception: ' . $e->getMessage(),
                    );
                } catch (Throwable $ignored) {
                }
            }
        }

        return $processed;
    }

    /**
     * Process a single already-claimed outbox record.
     *
     * Idempotent: if the tracking record already carries a Humming Bird
     * message id, the worker does NOT create a second message — it just
     * completes the operation (covers a crash between message-create and the
     * tracking/outbox update).
     */
    public function processClaimed(array $outboxRow): void
    {
        $outboxId   = (int) $outboxRow['id'];
        $trackingId = (int) $outboxRow['connect_external_message_id'];
        $tenantId   = (int) $outboxRow['tenant_id'];
        $env        = (string) ($outboxRow['environment'] ?? '');
        $attempt    = (int) ($outboxRow['attempts'] ?? 1);

        $this->service->writeWorkerAudit(
            $tenantId,
            ConnectConfig::AUDIT_ACTION_MESSAGE_PROCESSING,
            null,
            null,
            (string) ($outboxRow['external_message_id'] ?? ''),
            $outboxId,
            $attempt,
        );

        // Re-read the tracking record scoped to the persisted tenant +
        // environment. Worker never trusts external input for tenancy.
        $tracking = $this->messages
            ->where('id', $trackingId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($tracking === null || $tracking === []) {
            $this->permanentFail($outboxRow, ConnectConfig::OUTBOX_ERR_RECORD_LOST, 'Tracking record not found for tenant scoped job.');
            return;
        }

        $hbUserId = (int) ($tracking['humming_bird_user_id'] ?? 0);

        try {
            $this->statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_PROCESSING, 'worker claiming message');
        } catch (Throwable $ignored) {
        }

        // ------------------------------------------------------------------
        // Idempotency: message already created (prior crash / timeout).
        // ------------------------------------------------------------------
        if (! empty(self::normalizeNullString($tracking['message_id'] ?? null))) {
            $this->service->writeWorkerAudit(
                $tenantId,
                ConnectConfig::AUDIT_ACTION_MESSAGE_SENT,
                (string) ($tracking['external_user_id'] ?? ''),
                (string) $hbUserId,
                (string) ($tracking['external_message_id'] ?? ''),
                $outboxId,
                $attempt,
            );
            $this->service->updateDelivery($trackingId, $tenantId, $hbUserId, ConnectConfig::DELIVERY_STATUS_SENT, $attempt, null);
            try {
                $this->statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_SENT, 'message already sent (idempotent completion)');
            } catch (Throwable $ignored) {
            }
            $this->outbox->markCompleted($outboxId);

            return;
        }

        // ------------------------------------------------------------------
        // Re-resolve the HB user + service identity from persisted facts.
        // ------------------------------------------------------------------
        $hbUser = $this->users->find($hbUserId);
        if ($hbUser === null || $hbUser === [] || (int) ($hbUser['u_is_deleted'] ?? 0) === 1) {
            $this->permanentFail($outboxRow, ConnectConfig::ERROR_HUMMING_BIRD_USER_INACTIVE, 'Mapped Humming Bird user is not available.');
            return;
        }

        $identity = $this->serviceIdentities
            ->where('tenant_id', $tenantId)
            ->where('id', (int) ($tracking['service_identity_id'] ?? 0))
            ->where('status', ConnectConfig::IDENTITY_STATUS_ACTIVE)
            ->first();
        if ($identity === null || $identity === []) {
            $this->permanentFail($outboxRow, ConnectConfig::ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND, 'Service identity is missing or inactive.');
            return;
        }

        $botUser = $this->service->ensureBotUser($identity, $tenantId);

        $room = $this->service->findOrCreateRoom(
            $botUser,
            $hbUser,
            $identity,
        );

        $encryptedBody = (string) ($tracking['body'] ?? '');
        if ($encryptedBody === '') {
            $this->permanentFail($outboxRow, ConnectConfig::ERROR_INVALID_MESSAGE_BODY, 'Persisted message body is missing.');
            return;
        }

        // ------------------------------------------------------------------
        // Create the existing Humming Bird message + dispatch.
        // ------------------------------------------------------------------
        try {
            $messageRow = $this->service->createHbMessage(
                $botUser,
                $hbUser,
                $room,
                $encryptedBody,
            );

            if (empty($messageRow['m_id'])) {
                $this->permanentFail($outboxRow, ConnectConfig::OUTBOX_ERR_PROCESSING_FAILED, 'Humming Bird message row was not created.');
                return;
            }

            // Link tracking → HB message (the correlation that makes retries
            // idempotent).
            $this->messages->update($trackingId, [
                'message_id'  => (int) $messageRow['m_id'],
                'status'      => ConnectConfig::MESSAGE_STATUS_SENT,
                'error_code'  => null,
                'error_message' => null,
            ]);

            // Plaintext for FCM (stored in payload.plaintext at queue time).
            $payload = json_decode((string) ($tracking['payload'] ?? 'null'), true);
            $plaintext = (string) ($payload['plaintext'] ?? '');

            // Socket + FCM are fire-and-forget; failures never fail the message.
            $this->service->broadcastMessage($messageRow, [(string) $hbUserId]);
            $this->service->sendNotification($hbUser, $botUser, $plaintext, $room, (string) $messageRow['m_id'], (string) $hbUserId);

            $this->service->updateDelivery($trackingId, $tenantId, $hbUserId, ConnectConfig::DELIVERY_STATUS_SENT, $attempt, null);
            try {
                $this->statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_SENT, 'message created in Humming Bird');
            } catch (Throwable $ignored) {
            }
        } catch (ConnectWorkerRetryableException $e) {
            $this->retryOrFail($outboxRow, $e->errorCode(), $e->getMessage(), $attempt);
            return;
        } catch (Throwable $e) {
            // Deadlock / transient DB / timeout → retryable.
            if ($this->isRetryableError($e)) {
                $this->retryOrFail($outboxRow, ConnectConfig::OUTBOX_ERR_PROCESSING_FAILED, $e->getMessage(), $attempt);
                return;
            }

            $this->permanentFail($outboxRow, ConnectConfig::OUTBOX_ERR_PROCESSING_FAILED, $e->getMessage());
            return;
        }

        $this->service->writeWorkerAudit(
            $tenantId,
            ConnectConfig::AUDIT_ACTION_MESSAGE_SENT,
            (string) ($tracking['external_user_id'] ?? ''),
            (string) $hbUserId,
            (string) ($tracking['external_message_id'] ?? ''),
            $outboxId,
            $attempt,
        );
        $this->outbox->markCompleted($outboxId);
    }

    /**
     * Retry a temporary failure, or fail permanently once attempts are spent.
     */
    private function retryOrFail(array $outboxRow, string $errorCode, string $errorMessage, int $attempt): void
    {
        $outboxId     = (int) $outboxRow['id'];
        $tenantId     = (int) $outboxRow['tenant_id'];
        $maxAttempts  = $this->config()->workerMaxAttempts;

        // Record the error on the tracking record (best-effort) and delivery.
        try {
            $tracking = $this->messages->find((int) $outboxRow['connect_external_message_id']);
            $hbUserId = (int) ($tracking['humming_bird_user_id'] ?? 0);

            $this->messages->update((int) $outboxRow['connect_external_message_id'], [
                'error_code'    => substr($errorCode, 0, 50),
                'error_message' => substr($errorMessage, 0, 1000),
            ]);
            $this->service->updateDelivery(
                (int) $outboxRow['connect_external_message_id'],
                $tenantId,
                $hbUserId,
                ConnectConfig::DELIVERY_STATUS_RETRYING,
                $attempt,
                $errorMessage,
            );
            if ($tracking !== null && $tracking !== []) {
                try {
                    $this->statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_RETRYING, $errorMessage);
                } catch (Throwable $ignored) {
                }
            }
        } catch (Throwable $ignored) {
        }

        if ($attempt >= $maxAttempts) {
            $this->permanentFail($outboxRow, $errorCode, $errorMessage);

            return;
        }

        $this->outbox->markRetrying($outboxId, $errorCode, $errorMessage);
        $this->service->writeWorkerAudit(
            $tenantId,
            ConnectConfig::AUDIT_ACTION_MESSAGE_RETRYING,
            null,
            null,
            (string) ($outboxRow['external_message_id'] ?? ''),
            $outboxId,
            $attempt,
            $errorCode,
        );
    }

    /**
     * Fail a job permanently and record the failure.
     */
    private function permanentFail(array $outboxRow, string $errorCode, string $errorMessage): void
    {
        $outboxId   = (int) $outboxRow['id'];
        $tenantId   = (int) $outboxRow['tenant_id'];
        $trackingId = (int) $outboxRow['connect_external_message_id'];

        try {
            $this->messages->update($trackingId, [
                'status'        => ConnectConfig::MESSAGE_STATUS_FAILED,
                'error_code'    => substr($errorCode, 0, 50),
                'error_message' => substr($errorMessage, 0, 1000),
            ]);

            $tracking = $this->messages->find($trackingId);
            $hbUserId = (int) ($tracking['humming_bird_user_id'] ?? 0);
            $this->service->updateDelivery(
                $trackingId,
                $tenantId,
                $hbUserId,
                ConnectConfig::DELIVERY_STATUS_FAILED,
                (int) ($outboxRow['attempts'] ?? 1),
                $errorMessage,
            );
            if ($tracking !== null && $tracking !== []) {
                try {
                    $this->statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_FAILED, $errorMessage);
                } catch (Throwable $ignored) {
                }
            }
        } catch (Throwable $ignored) {
        }

        $this->outbox->markFailed($outboxId, $errorCode, $errorMessage);
        $this->service->writeWorkerAudit(
            $tenantId,
            ConnectConfig::AUDIT_ACTION_MESSAGE_FAILED,
            null,
            null,
            (string) ($outboxRow['external_message_id'] ?? ''),
            $outboxId,
            (int) ($outboxRow['attempts'] ?? 1),
            $errorCode,
        );
    }

    private function isRetryableError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        $code = $e->getCode();

        if ($e instanceof ConnectWorkerRetryableException) {
            return true;
        }

        // Known transient failure signatures.
        if (stripos($msg, 'deadlock') !== false
            || stripos($msg, 'lock wait timeout') !== false
            || stripos($msg, 'connection timed out') !== false
            || stripos($msg, 'too many connections') !== false
            || stripos($msg, 'server has gone away') !== false) {
            return true;
        }

        // DatabaseException with common transient codes.
        if ($e instanceof \CodeIgniter\Database\Exceptions\DatabaseException) {
            return in_array((int) $code, [1205, 1213, 2006, 1040, 2013], true);
        }

        return false;
    }

    private function config(): ConnectConfig
    {
        return config(ConnectConfig::class);
    }

    /**
     * Normalize SQLite3 Forge's DEFAULT 'NULL' string quirk to a real null.
     */
    private static function normalizeNullString($value)
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $stripped = ltrim(trim($value, "'"), "'");
            if (in_array($stripped, ['NULL', 'null', ''], true)) {
                return null;
            }
        }

        return $value;
    }

    private function workerId(): string
    {
        return $this->config()->workerIdPrefix . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}

<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Models\Connect\ConnectExternalMessageModel;
use App\Models\Connect\ConnectMessageStatusModel;
use App\Models\Connect\ConnectWebhookDeliveryModel;
use App\Models\Connect\ConnectWebhookModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use DateTime;
use Throwable;

/**
 * Humming Bird Connect — message status ledger + webhook event dispatch (Phase 7).
 *
 * The single integration point that records a Connect message's lifecycle
 * transition (accepted → queued → processing → sent → delivered → read |
 * failed/retrying) into the append-only `connect_message_status` ledger, keeps
 * the tracking record's current `status` in sync, and fans the transition out
 * to the tenant's subscribed webhooks as durable delivery rows.
 *
 * Design rules:
 *   - a transition is recorded at most once per message (UNIQUE per
 *     tenant+environment+external_message_id+status); re-recording is a no-op
 *   - the worker path (statusFromTracking) never needs an HTTP context — it is
 *     called from ConnectMessageProcessor and the consumer read hook
 *   - webhook dispatch is best-effort: a failure to enqueue a webhook NEVER
 *     fails the status transition (the message already exists in the HB system)
 */
class ConnectMessageStatusService
{
    private ConnectMessageStatusModel     $statuses;
    private ConnectExternalMessageModel   $messages;
    private ConnectWebhookModel           $webhooks;
    private ConnectWebhookDeliveryService $webhookDeliveries;
    private ConnectApiAuditLogModel       $audit;

    public function __construct(
        ?ConnectMessageStatusModel $statuses = null,
        ?ConnectExternalMessageModel $messages = null,
        ?ConnectWebhookModel $webhooks = null,
        ?ConnectWebhookDeliveryService $webhookDeliveries = null,
        ?ConnectApiAuditLogModel $audit = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->statuses         = $statuses ?? new ConnectMessageStatusModel($db);
        $this->messages         = $messages ?? new ConnectExternalMessageModel($db);
        $this->webhooks         = $webhooks ?? new ConnectWebhookModel($db);
        $this->webhookDeliveries = $webhookDeliveries ?? new ConnectWebhookDeliveryService();
        $this->audit            = $audit ?? new ConnectApiAuditLogModel($db);
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    /**
     * Record a status transition based on a persisted tracking row.
     *
     * Used by the worker (sent/failed/retrying/processing) and by the consumer
     * read-receipt hook (delivered/read). No HTTP context required.
     *
     * @return int 1 = transition newly recorded + dispatched, 0 = already present
     */
    public function recordFromTracking(array $trackingRow, string $status, ?string $reason = null): int
    {
        $tenantId     = (int) ($trackingRow['tenant_id'] ?? 0);
        $environment  = (string) ($trackingRow['environment'] ?? '');
        $externalId   = $this->normalizeNullString($trackingRow['external_message_id'] ?? null);
        $trackingId   = (int) ($trackingRow['id'] ?? 0);
        $hbMessageId  = $this->normalizeNullString($trackingRow['message_id'] ?? null);

        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        try {
            $this->statuses->insert([
                'uuid'                    => $this->uuid(),
                'tenant_id'               => $tenantId,
                'environment'             => $environment,
                'external_message_id'     => $externalId,
                'connect_message_id'      => $trackingId ?: null,
                'humming_bird_message_id' => $hbMessageId !== null ? (int) $hbMessageId : null,
                'status'                  => $status,
                'status_reason'           => $reason,
                'occurred_at'             => $nowStr,
            ], true);
        } catch (DatabaseException $e) {
            // Unique constraint → transition already recorded. Idempotent.
            return 0;
        } catch (Throwable $e) {
            log_message('error', 'Connect status ledger insert failed: ' . $e->getMessage());

            return 0;
        }

        // Keep the tracking record's current status in sync (business value).
        // Forward-only: a late-arriving lower-ranked transition must never
        // clobber a newer status written by the worker (e.g. queue()'s
        // 'queued' racing the worker's 'sent').
        try {
            $current = $this->messages->find($trackingId);
            $currentStatus = (string) ($current['status'] ?? ConnectConfig::MESSAGE_STATUS_ACCEPTED);
            if ($this->rank($status) > $this->rank($currentStatus)) {
                $this->messages->update($trackingId, [
                    'status' => $status,
                ]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Connect status tracking update failed: ' . $e->getMessage());
        }

        $this->dispatchToWebhooks($trackingRow, $status, $nowStr);

        return 1;
    }

    /**
     * Record a read-receipt-driven transition (delivered / read) from the
     * consumer update-message path. The recipient's direct evidence
     * (messages.m_read_status: 2 = delivered-to-device, 3 = read) is the ONLY
     * signal that may produce these states.
     *
     * Safe to call on any message update; it no-ops when the message is not a
     * Connect-originated message or the transition is already recorded.
     */
    public function recordFromMessageId(int $hummingBirdMessageId, string $status, ?string $reason = null): int
    {
        if ($status !== ConnectConfig::MESSAGE_STATUS_DELIVERED
            && $status !== ConnectConfig::MESSAGE_STATUS_READ) {
            return 0;
        }

        $tracking = $this->messages
            ->where('message_id', $hummingBirdMessageId)
            ->orderBy('id', 'DESC')
            ->first();

        if ($tracking === null || $tracking === []) {
            return 0;
        }

        return $this->recordFromTracking($tracking, $status, $reason);
    }

    // ------------------------------------------------------------------
    // Querying (HTTP surface — tenant-scoped)
    // ------------------------------------------------------------------

    /**
     * Current user-visible status for a message (from the tracking record,
     * falling back to the newest ledger row). Envelope-safe.
     *
     * @throws ConnectStatusException
     */
    public function getStatus(ConnectTenantContext $context, string $externalMessageId): array
    {
        $tracking = $this->messages
            ->where('tenant_id', $context->tenantId())
            ->where('environment', $context->environment())
            ->where('external_message_id', $externalMessageId)
            ->first();

        if ($tracking === null || $tracking === []) {
            throw new ConnectStatusException(ConnectConfig::ERROR_STATUS_NOT_FOUND, 'Message status not found.', 404);
        }

        $status  = $this->effectiveStatus($tracking);
        $history = $this->ledger($context->tenantId(), $context->environment(), $externalMessageId);

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_STATUS_LOOKUP, $externalMessageId, $status, null);

        return [
            'tenant_id'             => (string) $context->tenantId(),
            'environment'           => $context->environment(),
            'external_message_id'   => $externalMessageId,
            'humming_bird_message_id' => $this->normalizeNullString($tracking['message_id'] ?? null),
            'status'                => $status,
            'current_status'        => $status,
            'updated_at'            => $tracking['updated_at'] ?? '',
            'events'                => $history,
        ];
    }

    /**
     * Full transition history (ledger) for a message.
     *
     * @throws ConnectStatusException
     */
    public function getHistory(ConnectTenantContext $context, string $externalMessageId): array
    {
        $exists = $this->messages
            ->where('tenant_id', $context->tenantId())
            ->where('environment', $context->environment())
            ->where('external_message_id', $externalMessageId)
            ->get()
            ->getRowArray();

        if ($exists === null || $exists === false) {
            throw new ConnectStatusException(ConnectConfig::ERROR_STATUS_EVENT_NOT_FOUND, 'Message status history not found.', 404);
        }

        $history = $this->ledger($context->tenantId(), $context->environment(), $externalMessageId);

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_STATUS_HISTORY, $externalMessageId, (string) count($history), null);

        return [
            'tenant_id'           => (string) $context->tenantId(),
            'environment'         => $context->environment(),
            'external_message_id' => $externalMessageId,
            'events'              => $history,
        ];
    }

    // ------------------------------------------------------------------
    // Webhook dispatch
    // ------------------------------------------------------------------

    /**
     * Find active webhooks for the tenant+environment whose subscribed events
     * contain the event for `$status`, and enqueue a durable delivery row.
     * Best-effort — never throws.
     */
    public function dispatchToWebhooks(array $trackingRow, string $status, string $occurredAt): void
    {
        $tenantId    = (int) ($trackingRow['tenant_id'] ?? 0);
        $environment = (string) ($trackingRow['environment'] ?? '');
        $eventType   = self::eventForStatus($status);

        if ($eventType === null) {
            return;
        }

        try {
            $webhooks = $this->webhooks
                ->where('tenant_id', $tenantId)
                ->where('environment', $environment)
                ->where('status', ConnectConfig::WEBHOOK_STATUS_ACTIVE)
                ->findAll();
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook resolve failed: ' . $e->getMessage());

            return;
        }

        if ($webhooks === []) {
            return;
        }

        $eventUuid  = $this->uuid();
        $payload    = $this->buildEventPayload($trackingRow, $status, $eventType, $eventUuid, $occurredAt);

        foreach ($webhooks as $webhook) {
            $events = (array) json_decode((string) ($webhook['events'] ?? '[]'), true);
            if (! in_array($eventType, $events, true)) {
                continue;
            }

            try {
                $this->webhookDeliveries->enqueue(
                    (int) $webhook['id'],
                    $webhook,
                    $eventType,
                    $eventUuid,
                    $payload,
                    $occurredAt,
                );
            } catch (Throwable $e) {
                log_message('error', 'Connect webhook enqueue failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Build the exact JSON payload a downstream consumer receives for an event.
     */
    public function buildEventPayload(array $trackingRow, string $status, string $eventType, string $eventUuid, string $occurredAt): array
    {
        $hbMessageId = $this->normalizeNullString($trackingRow['message_id'] ?? null);

        return [
            'id'         => $eventUuid,
            'type'       => $eventType,
            'created_at' => $occurredAt,
            'data'       => array_merge([
                'tenant_id'               => (int) ($trackingRow['tenant_id'] ?? 0),
                'environment'             => (string) ($trackingRow['environment'] ?? ''),
                'external_user_id'        => (string) ($trackingRow['external_user_id'] ?? ''),
                'external_message_id'     => $this->normalizeNullString($trackingRow['external_message_id'] ?? null),
                'humming_bird_user_id'    => (string) ($trackingRow['humming_bird_user_id'] ?? ''),
                'humming_bird_message_id' => $hbMessageId,
                'status'                  => $status,
            ]),
        ];
    }

    public static function eventForStatus(string $status): ?string
    {
        return match ($status) {
            ConnectConfig::MESSAGE_STATUS_ACCEPTED   => ConnectConfig::WEBHOOK_EVENT_MESSAGE_ACCEPTED,
            ConnectConfig::MESSAGE_STATUS_QUEUED     => ConnectConfig::WEBHOOK_EVENT_MESSAGE_QUEUED,
            ConnectConfig::MESSAGE_STATUS_PROCESSING => ConnectConfig::WEBHOOK_EVENT_MESSAGE_PROCESSING,
            ConnectConfig::MESSAGE_STATUS_SENT       => ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
            ConnectConfig::MESSAGE_STATUS_DELIVERED  => ConnectConfig::WEBHOOK_EVENT_MESSAGE_DELIVERED,
            ConnectConfig::MESSAGE_STATUS_READ       => ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ,
            ConnectConfig::MESSAGE_STATUS_FAILED     => ConnectConfig::WEBHOOK_EVENT_MESSAGE_FAILED,
            ConnectConfig::MESSAGE_STATUS_RETRYING   => ConnectConfig::WEBHOOK_EVENT_MESSAGE_RETRYING,
            default                                  => null,
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function effectiveStatus(array $trackingRow): string
    {
        $status = $this->normalizeNullString($trackingRow['status'] ?? null) ?? ConnectConfig::MESSAGE_STATUS_ACCEPTED;

        if ($status === ConnectConfig::MESSAGE_STATUS_PROCESSING) {
            return ConnectConfig::MESSAGE_STATUS_QUEUED;
        }
        if ($status === ConnectConfig::MESSAGE_STATUS_RETRYING) {
            return ConnectConfig::MESSAGE_STATUS_QUEUED;
        }

        return in_array($status, ConnectConfig::STATUS_API_VISIBLE, true)
            ? $status
            : ConnectConfig::MESSAGE_STATUS_QUEUED;
    }

    private function ledger(int $tenantId, string $environment, string $externalMessageId): array
    {
        $rows = $this->statuses
            ->where('tenant_id', $tenantId)
            ->where('environment', $environment)
            ->where('external_message_id', $externalMessageId)
            ->orderBy('occurred_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        return array_map(static function ($row) {
            return [
                'status'       => $row['status'] ?? '',
                'event'        => $row['status'] ?? '',
                'occurred_at'  => $row['occurred_at'] ?? '',
                'reason'       => $row['status_reason'] ?? null,
            ];
        }, $rows);
    }

    private function writeAudit(ConnectTenantContext $context, string $action, string $externalMessageId, $detail, ?string $errorCode): void
    {
        try {
            $request = Services::request();
            $method  = strtoupper($request->getMethod(true));
            $path    = '/' . ltrim($request->getPath(), '/');

            $this->audit->insert([
                'tenant_id'            => $context->tenantId(),
                'credential_id'        => $context->credentialDbId(),
                'request_id'           => $context->requestId(),
                'method'               => $method,
                'endpoint'             => $path,
                'ip_address'           => substr((string) $request->getIPAddress(), 0, 45),
                'user_agent'           => substr(trim((string) $request->getUserAgent()), 0, 255),
                'request_hash'         => hash('sha256', $method . '|' . $path . '|' . $context->requestId()),
                'response_code'        => $errorCode ? 500 : 200,
                'status'               => $errorCode ? 'error' : 'success',
                'error_code'           => $errorCode,
                'action'               => $action,
                'external_user_id'     => $externalMessageId,
                'humming_bird_user_id' => is_scalar($detail) ? (string) $detail : null,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Connect status audit write failed: ' . $e->getMessage());
        }
    }

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

    /**
     * Forward-ordinal for the tracking-record status sync guard. Higher value
     * = further in the lifecycle. retrying sits between processing and sent so
     * a resume (retrying → sent) and a burn-out (retrying → failed) both move
     * forward.
     */
    private function rank(string $status): int
    {
        return match ($status) {
            ConnectConfig::MESSAGE_STATUS_ACCEPTED   => 0,
            ConnectConfig::MESSAGE_STATUS_QUEUED     => 1,
            ConnectConfig::MESSAGE_STATUS_PROCESSING => 2,
            ConnectConfig::MESSAGE_STATUS_RETRYING   => 3,
            ConnectConfig::MESSAGE_STATUS_SENT       => 4,
            ConnectConfig::MESSAGE_STATUS_DELIVERED  => 5,
            ConnectConfig::MESSAGE_STATUS_READ       => 6,
            ConnectConfig::MESSAGE_STATUS_FAILED     => 7,
            default                                  => 0,
        };
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}
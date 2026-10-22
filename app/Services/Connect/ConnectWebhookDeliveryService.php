<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectWebhookDeliveryModel;
use App\Models\Connect\ConnectWebhookModel;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use DateTime;

/**
 * Humming Bird Connect — webhook delivery outbox (Phase 7).
 *
 * The other side of the reliable-delivery story. Every webhook event is first
 * persisted as a DURABLE delivery row (status pending, attempt 0) inside the
 * same transaction that fires it; a background worker (ConnectWebhookProcessor)
 * then claims due rows and POSTs them with a signature.
 *
 * Concurrency uses the exact same atomic CAS claim shape as the Phase 5
 * message outbox: claim one due row inside a short transaction, guarded by
 * `WHERE status IN (pending,retrying) AND next_retry_at <= NOW()`, so two
 * workers can never POST the same webhook twice.
 *
 * Retries use the shared WASM-free exponential backoff ladder
 * (Connect::$WEBHOOK_RETRY_DELAYS) and honor a downstream `Retry-After` hint
 * capped by `Connect::$webhookRetryAfterCap`. Failed events record the error
 * code + message; the delivery row keeps the exact payload so retries always
 * send the byte-identical body (signature verification stays valid).
 */
class ConnectWebhookDeliveryService
{
    private ConnectWebhookDeliveryModel $deliveries;
    private ConnectWebhookModel         $webhooks;
    private ConnectWebhookSigner        $signer;

    public function __construct(
        ?ConnectWebhookDeliveryModel $deliveries = null,
        ?ConnectWebhookModel $webhooks = null,
        ?ConnectWebhookSigner $signer = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->deliveries = $deliveries ?? new ConnectWebhookDeliveryModel($db);
        $this->webhooks   = $webhooks ?? new ConnectWebhookModel($db);
        $this->signer     = $signer ?? new ConnectWebhookSigner();
    }

    /**
     * Persist a durable delivery row for a webhook event. Idempotent on
     * (webhook_id, event_id): a second call is a no-op returning false.
     *
     * @param array $payload array (will be encoded to the exact JSON string
     *                       stored + signed; consumers see exactly this body)
     */
    public function enqueue(int $webhookId, array $webhookRow, string $eventType, string $eventUuid, array $payload, string $dueNow): bool
    {
        $db = $this->db();
        $existing = $db->table('connect_webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->where('event_id', $eventUuid)
            ->where('tenant_id', $webhookRow['tenant_id'])
            ->get()
            ->getRowArray();

        if ($existing !== null && $existing !== false) {
            return false;
        }

        $rawBody   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nowStr    = (new DateTime())->format('Y-m-d H:i:s');
        $maxBytes  = (int) (config(ConnectConfig::class)->webhookMaxStoredPayload);
        $storedBody = strlen($rawBody) > $maxBytes ? substr($rawBody, 0, $maxBytes) : $rawBody;

        $this->deliveries->insert([
            'webhook_id'    => (int) $webhookId,
            'webhook_uuid'  => $webhookRow['uuid'] ?? null,
            'tenant_id'     => (int) $webhookRow['tenant_id'],
            'environment'   => $webhookRow['environment'] ?? null,
            'uuid'          => $this->uuid(),
            'event_id'      => $eventUuid,
            'event_type'    => $eventType,
            'payload_hash'  => hash('sha256', $storedBody),
            'payload'       => $storedBody,
            'status'        => ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,
            'attempt'       => 0,
            'http_status'   => null,
            'response_time_ms' => null,
            'next_retry_at' => $dueNow,
            'delivered_at'  => null,
            'last_error'    => null,
            'last_error_code' => null,
            'created_at'    => $nowStr,
            'updated_at'    => $nowStr,
        ]);

        return true;
    }

    /**
     * Claim a single due delivery row (atomic CAS, mirrors the message outbox).
     */
    public function claimOne(string $workerId): ?array
    {
        $db = $this->db();
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $db->transBegin();

        $row = $this->deliveries
            ->whereIn('status', [
                ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,
                ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING,
            ])
            ->where('next_retry_at <=', $nowStr)
            ->orderBy('next_retry_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->first();

        if ($row === null || $row === []) {
            $db->transRollback();

            return null;
        }

        $db->table('connect_webhook_deliveries')
            ->where('id', (int) $row['id'])
            ->whereIn('status', [
                ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,
                ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING,
            ])
            ->where('next_retry_at <=', $nowStr)
            ->update([
                'status'     => ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING,
                'attempt'    => (int) $row['attempt'] + 1,
                'locked_at'  => $nowStr,
                'locked_by'  => substr($workerId, 0, 64),
                'updated_at' => $nowStr,
            ]);

        $affected = (int) $db->affectedRows();

        if ($affected === 0) {
            $db->transRollback();

            return null;
        }

        $db->transCommit();

        return $this->deliveries->find($row['id']);
    }

    public function claimBatch(int $batchSize, string $workerId): array
    {
        $claimed = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $row = $this->claimOne($workerId);
            if ($row === null) {
                break;
            }
            $claimed[] = $row;
        }

        return $claimed;
    }

    /**
     * Recover deliveries stuck in 'processing' past the stale timeout.
     */
    public function recoverStale(string $workerId, int $staleTimeout): int
    {
        $db = $this->db();
        $cutoff = (new DateTime())->modify("-{$staleTimeout} seconds")->format('Y-m-d H:i:s');
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $db->table('connect_webhook_deliveries')
            ->where('status', ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING)
            ->where('locked_at IS NOT NULL')
            ->where('locked_at <', $cutoff)
            ->set('status', ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING)
            ->set('next_retry_at', $nowStr)
            ->set('locked_at', null)
            ->set('locked_by', null)
            ->set('last_error_code', 'CONNECT_WEBHOOK_STALE_JOB')
            ->set('last_error', 'Delivery recovered after stale timeout')
            ->set('updated_at', $nowStr)
            ->update();

        return (int) max(0, (int) $db->affectedRows());
    }

    /**
     * Mark a delivery retrying with exponential backoff (optionally honoring a
     * downstream Retry-After hint, capped by the config).
     */
    public function markRetrying(int $deliveryId, string $errorCode, string $errorMessage, ?int $retryAfterHint = null): void
    {
        $row = (array) $this->deliveries->find($deliveryId);
        $delay = $this->retryDelayForAttempt($row);

        if ($retryAfterHint !== null && $retryAfterHint > 0) {
            $cap = (int) (config(ConnectConfig::class)->webhookRetryAfterCap);
            $delay = min($delay, min($retryAfterHint, $cap));
        }

        $nowStr = (new DateTime())->format('Y-m-d H:i:s');
        $availableAt = (new DateTime())->modify("+{$delay} seconds")->format('Y-m-d H:i:s');

        $this->deliveries->update($deliveryId, [
            'status'          => ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING,
            'next_retry_at'   => $availableAt,
            'locked_at'       => null,
            'locked_by'       => null,
            'last_error'      => substr($errorMessage, 0, 1000),
            'last_error_code' => substr($errorCode, 0, 64),
            'updated_at'      => $nowStr,
        ]);
    }

    /**
     * Mark a delivery permanently failed (retry policy exhausted).
     */
    public function markFailed(int $deliveryId, string $errorCode, string $errorMessage): void
    {
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $this->deliveries->update($deliveryId, [
            'status'          => ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,
            'next_retry_at'   => null,
            'locked_at'       => null,
            'locked_by'       => null,
            'last_error'      => substr($errorMessage, 0, 1000),
            'last_error_code' => substr($errorCode, 0, 64),
            'updated_at'      => $nowStr,
        ]);
    }

    /**
     * Mark a delivery delivered — record response details + the webhook's
     * last_success_at.
     */
    public function markDelivered(int $deliveryId, int $httpStatus, int $responseTimeMs, ?int $webhookId = null, ?int $tenantId = null): void
    {
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $this->deliveries->update($deliveryId, [
            'status'          => ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED,
            'http_status'     => $httpStatus,
            'response_time_ms'=> $responseTimeMs,
            'delivered_at'    => $nowStr,
            'locked_at'       => null,
            'locked_by'       => null,
            'last_error'      => null,
            'last_error_code' => null,
            'updated_at'      => $nowStr,
        ]);

        if ($webhookId !== null && $tenantId !== null) {
            $this->db()->table('connect_webhooks')
                ->where('id', $webhookId)
                ->where('tenant_id', $tenantId)
                ->set('last_success_at', $nowStr)
                ->set('updated_at', $nowStr)
                ->update();
        }
    }

    private function retryDelayForAttempt(array $row): int
    {
        $attempt = (int) ($row['attempt'] ?? 1);
        $delays  = ConnectConfig::WEBHOOK_RETRY_DELAYS;

        if ($attempt <= 1) {
            return (int) $delays[0];
        }

        $idx = $attempt - 1;

        return (int) ($delays[$idx] ?? $delays[count($delays) - 1]);
    }

    public function signer(): ConnectWebhookSigner
    {
        return $this->signer;
    }

    /**
     * Queue clean-up: purge terminal (delivered/failed) delivery rows that
     * were last updated more than `$retentionDays` ago. Returns the number of
     * rows removed. Safety-fenced — never touches pending/processing/retrying
     * rows, respecting the 4-state lock contract.
     */
    public function purgeOlderThan(int $retentionDays): int
    {
        if ($retentionDays < 1) {
            return 0;
        }

        $db     = $this->db();
        $cutoff = (new DateTime())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

        $db->table('connect_webhook_deliveries')
            ->whereIn('status', [
                ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED,
                ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,
            ])
            ->where('updated_at <', $cutoff)
            ->delete();

        return (int) max(0, (int) $db->affectedRows());
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
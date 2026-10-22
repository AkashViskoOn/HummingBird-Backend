<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Models\Connect\ConnectWebhookDeliveryModel;
use App\Models\Connect\ConnectWebhookModel;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use Throwable;

/**
 * Humming Bird Connect — background webhook delivery processor (Phase 7).
 *
 * Claims durable connect_webhook_deliveries rows and POSTs them to the tenant
 * endpoint with an HMAC signature, exactly mirroring the Phase 5 message
 * worker:
 *
 *   claim (atomic CAS, short txn)
 *     → re-resolve the webhook from the persisted row (tenant-scoped)
 *     → open the signing secret from the vault
 *     → POST the byte-identical stored payload with signed headers
 *     → 2xx → markDelivered  |  3xx/4xx/5xx/timeout → retry or fail
 *
 * Socket-style semantics: a delivery attempt NEVER touches the message system.
 * Failures here only move the delivery row. Max attempts come from
 * Connect::$webhookWorkerMaxAttempts; backoff honors a downstream Retry-After
 * capped by Connect::$webhookRetryAfterCap.
 */
class ConnectWebhookProcessor
{
    private ConnectWebhookDeliveryService $deliveries;
    private ConnectWebhookService         $webhookService;
    private ConnectWebhookModel           $webhooks;
    private ConnectApiAuditLogModel       $audit;

    public function __construct(
        ?ConnectWebhookDeliveryService $deliveries = null,
        ?ConnectWebhookService $webhookService = null,
        ?ConnectWebhookModel $webhooks = null,
        ?ConnectApiAuditLogModel $audit = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->deliveries    = $deliveries ?? new ConnectWebhookDeliveryService();
        $this->webhookService = $webhookService ?? new ConnectWebhookService();
        $this->webhooks      = $webhooks ?? new ConnectWebhookModel($db);
        $this->audit         = $audit ?? new ConnectApiAuditLogModel($db);
    }

    /**
     * Run one worker pass. Returns number of events delivered/failed/retried.
     */
    public function run(int $batchSize): int
    {
        $config      = config(ConnectConfig::class);
        $workerId    = $this->workerId();
        $staleTimeout = $config->webhookWorkerStaleTimeout;

        $recovered = $this->deliveries->recoverStale($workerId, $staleTimeout);
        if ($recovered > 0) {
            log_message('info', "Connect webhook worker recovered {$recovered} stale deliveries");
        }

        $claimed  = $this->deliveries->claimBatch($batchSize, $workerId);
        $processed = 0;

        foreach ($claimed as $row) {
            try {
                $this->processClaimed($row);
                $processed++;
            } catch (Throwable $e) {
                try {
                    $this->deliveries->markRetrying(
                        (int) $row['id'],
                        'CONNECT_WEBHOOK_PROCESS_FAILED',
                        'Worker exception: ' . $e->getMessage(),
                    );
                } catch (Throwable $ignored) {
                }
            }
        }

        return $processed;
    }

    /**
     * Deliver a single claimed delivery row. Never throws.
     */
    public function processClaimed(array $deliveryRow): void
    {
        $deliveryId = (int) $deliveryRow['id'];
        $tenantId   = (int) $deliveryRow['tenant_id'];
        $attempt    = (int) ($deliveryRow['attempt'] ?? 1);

        $this->writeAudit(
            $tenantId,
            ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_STARTED,
            (string) ($deliveryRow['event_id'] ?? ''),
            $attempt,
            null,
        );

        // Re-resolve the webhook scoped to the persisted tenant.
        $webhook = $this->webhooks
            ->where('id', (int) ($deliveryRow['webhook_id'] ?? 0))
            ->where('tenant_id', $tenantId)
            ->first();

        if ($webhook === null || $webhook === []
            || ($webhook['status'] ?? '') !== ConnectConfig::WEBHOOK_STATUS_ACTIVE) {
            // Registration gone/disabled → permanent (no point retrying).
            $this->deliveries->markFailed(
                $deliveryId,
                ConnectConfig::ERROR_WEBHOOK_NOT_FOUND,
                'Webhook is missing or disabled',
            );
            $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, (string) ($deliveryRow['event_id'] ?? ''), $attempt, 'CONNECT_WEBHOOK_NOT_FOUND');

            return;
        }

        $payload   = (string) ($deliveryRow['payload'] ?? '');
        $eventType = (string) ($deliveryRow['event_type'] ?? '');
        $eventId   = (string) ($deliveryRow['event_id'] ?? '');

        if ($payload === '') {
            $this->deliveries->markFailed(
                $deliveryId,
                ConnectConfig::ERROR_DATABASE_ERROR,
                'Persisted webhook payload is empty',
            );
            $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, $eventId, $attempt, 'CONNECT_WEBHOOK_PAYLOAD_MISSING');

            return;
        }

        try {
            $resolveHost = (string) parse_url((string) $webhook['url'], PHP_URL_HOST);
            $resolveNote = (new ConnectSsrfGuard())->checkResolved($resolveHost);
            if ($resolveNote !== null) {
                $this->deliveries->markFailed($deliveryId, ConnectConfig::ERROR_WEBHOOK_URL_NOT_ALLOWED, 'Webhook URL rejected at delivery: ' . $resolveNote);
                $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, $eventId, $attempt, 'CONNECT_WEBHOOK_SSRF_BLOCKED');

                return;
            }
        } catch (Throwable $ignored) {
        }

        try {
            $secret = $this->webhookService->secretFor($webhook);
        } catch (Throwable $e) {
            $this->deliveries->markFailed($deliveryId, ConnectConfig::ERROR_DATABASE_ERROR, 'Webhook secret unavailable: ' . $e->getMessage());
            $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, $eventId, $attempt, 'CONNECT_WEBHOOK_SECRET_UNAVAILABLE');

            return;
        }

        [$httpStatus, $responseTimeMs, $retryAfter] = $this->post(
            (string) $webhook['url'],
            $secret,
            $payload,
            $eventType,
            $eventId,
        );

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $this->deliveries->markDelivered($deliveryId, $httpStatus, $responseTimeMs, (int) $webhook['id'], $tenantId);
            $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_SUCCEEDED, $eventId, $attempt, null);

            $this->db()->table('connect_webhooks')
                ->where('id', (int) $webhook['id'])
                ->where('tenant_id', $tenantId)
                ->set('last_failure_at', null)
                ->update();

            return;
        }

        $this->retryOrFail($deliveryRow, $httpStatus, $responseTimeMs, $retryAfter, $eventId, $attempt);
    }

    /**
     * POST a signed webhook. Returns [httpStatus, responseTimeMs, retryAfter].
     * Never throws — network/transport errors map to status 0.
     */
    public function post(string $url, string $secret, string $rawBody, string $eventType, string $eventId): array
    {
        $started = microtime(true);
        $config  = config(ConnectConfig::class);

        $timestamp = (string) time();
        $signature = (new ConnectWebhookSigner())->sign($secret, $timestamp, $rawBody);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'X-HummingBird-Event: ' . $eventType,
            'X-HummingBird-Event-Id: ' . $eventId,
            'X-HummingBird-Timestamp: ' . $timestamp,
            'X-HummingBird-Signature: ' . $signature,
            'User-Agent: HummingBird-Connect/1.0',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $config->webhookTimeout,
            CURLOPT_CONNECTTIMEOUT => $config->webhookConnectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $raw = curl_exec($ch);

        $responseTimeMs = (int) round((microtime(true) - $started) * 1000);
        $errno          = (int) curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $raw === '') {
            log_message('error', "Connect webhook delivery failed: curl errno={$errno}");

            return [0, $responseTimeMs, null];
        }

        $headerSize = (int) strpos($raw, "\r\n\r\n");
        $headerText = $headerSize === 0 ? '' : substr($raw, 0, $headerSize);
        preg_match('/\bHTTP\/\d(?:\.\d)?\s+(\d{3})/', $headerText, $m);
        $httpStatus = (int) ($m[1] ?? 0);

        $retryAfter = null;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $headerText, $m2)) {
            $retryAfter = (int) $m2[1];
        }

        return [$httpStatus, $responseTimeMs, $retryAfter];
    }

    private function retryOrFail(
        array $deliveryRow,
        int $httpStatus,
        int $responseTimeMs,
        ?int $retryAfter,
        string $eventId,
        int $attempt,
    ): void {
        $deliveryId = (int) $deliveryRow['id'];
        $tenantId   = (int) $deliveryRow['tenant_id'];
        $maxAttempts = (int) (config(ConnectConfig::class)->webhookWorkerMaxAttempts);

        $errorMessage = 'HTTP ' . $httpStatus . ' from webhook endpoint';

        // Permanent 4xx (anything but 408/429) — retrying cannot change the
        // outcome (bad signature, unknown event, ACL). Fail immediately.
        if ($httpStatus >= 400 && $httpStatus < 500 && $httpStatus !== 408 && $httpStatus !== 429) {
            $this->deliveries->markFailed($deliveryId, 'CONNECT_WEBHOOK_HTTP_' . $httpStatus, $errorMessage);
            $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, $eventId, $attempt, 'CONNECT_WEBHOOK_HTTP_' . $httpStatus);

            return;
        }

        if ($attempt >= $maxAttempts) {
            $this->deliveries->markFailed($deliveryId, 'CONNECT_WEBHOOK_HTTP_' . $httpStatus, $errorMessage);
            $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, $eventId, $attempt, 'CONNECT_WEBHOOK_EXHAUSTED');

            return;
        }

        $this->deliveries->markRetrying($deliveryId, 'CONNECT_WEBHOOK_HTTP_' . $httpStatus, $errorMessage, $retryAfter);
        $this->dbSafeUpdateWebhookFailure($tenantId, (int) ($deliveryRow['webhook_id'] ?? 0));
        $this->writeAudit($tenantId, ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED, $eventId, $attempt, 'CONNECT_WEBHOOK_HTTP_' . $httpStatus);
    }

    private function dbSafeUpdateWebhookFailure(int $tenantId, int $webhookId): void
    {
        try {
            $this->db()->table('connect_webhooks')
                ->where('id', $webhookId)
                ->where('tenant_id', $tenantId)
                ->set('last_failure_at', (new \DateTime())->format('Y-m-d H:i:s'))
                ->update();
        } catch (Throwable $ignored) {
        }
    }

    private function writeAudit(int $tenantId, string $action, string $eventId, int $attempt, ?string $errorCode): void
    {
        try {
            $this->audit->insert([
                'tenant_id'            => $tenantId,
                'credential_id'        => null,
                'request_id'           => null,
                'method'               => 'WORKER',
                'endpoint'             => 'connect-webhook-worker',
                'ip_address'           => null,
                'user_agent'           => null,
                'request_hash'         => null,
                'response_code'        => $errorCode ? 500 : 200,
                'status'               => $errorCode ? 'error' : 'success',
                'error_code'           => $errorCode,
                'action'               => $action,
                'external_user_id'     => $eventId,
                'humming_bird_user_id' => null,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook worker audit write failed: ' . $e->getMessage());
        }
    }

    private function workerId(): string
    {
        return config(ConnectConfig::class)->workerIdPrefix . '-webhook-' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}
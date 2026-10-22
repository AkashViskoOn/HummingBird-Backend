<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Models\Connect\ConnectWebhookModel;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — webhook registration management (Phase 7).
 *
 * Control-plane CRUD for tenant webhook subscriptions:
 *
 *   - secret is sealed at rest with ConnectSecretVault (same blob format as API
 *     credentials) and returned to the caller exactly once at creation
 *   - URL is validated against the SSRF guard both at create and update
 *   - events must be a non-empty subset of Connect::$WEBHOOK_SUBSCRIBABLE_EVENTS
 *   - every row is tenant-scoped (ConnectScopeGuard semantics)
 *
 * The domain event-fire side lives in ConnectWebhookDeliveryService/
 * ConnectWebhookProcessor — this service only manages subscriptions.
 */
class ConnectWebhookService
{
    private ConnectWebhookModel          $webhooks;
    private ConnectApiAuditLogModel      $audit;
    private ConnectSecretVault           $vault;
    private ConnectSsrfGuard             $ssrf;

    public function __construct(
        ?ConnectWebhookModel $webhooks = null,
        ?ConnectApiAuditLogModel $audit = null,
        ?ConnectSecretVault $vault = null,
        ?ConnectSsrfGuard $ssrf = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->webhooks = $webhooks ?? new ConnectWebhookModel($db);
        $this->audit    = $audit ?? new ConnectApiAuditLogModel($db);
        $this->vault    = $vault ?? new ConnectSecretVault();
        $this->ssrf     = $ssrf ?? new ConnectSsrfGuard();
    }

    /**
     * Create a webhook subscription.
     *
     * @return array{webhook: array, secret: string} — secret is only ever
     *                                                   returned here
     *
     * @throws ConnectStatusException
     */
    public function create(ConnectTenantContext $context, array $data): array
    {
        $tenantId = $context->tenantId();

        $url      = trim((string) ($data['url'] ?? ''));
        $events   = $this->normalizeEvents($data['events'] ?? null);
        $secret   = isset($data['secret']) && $data['secret'] !== '' ? (string) $data['secret'] : $this->generateSecret();
        $status   = $data['status'] ?? ConnectConfig::WEBHOOK_STATUS_ACTIVE;
        $name     = isset($data['name']) ? trim((string) $data['name']) : '';
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        if (strlen($url) === 0) {
            throw new ConnectStatusException(ConnectConfig::ERROR_INVALID_WEBHOOK_URL, 'Webhook url is required.', 400);
        }

        $ssrfReason = $this->ssrf->check($url);
        if ($ssrfReason !== null) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_URL_NOT_ALLOWED, $ssrfReason, 422);
        }

        if (strlen($secret) < 16) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_SECRET_TOO_SHORT, 'Webhook secret must be at least 16 characters.', 400);
        }

        if (strlen($name) > 100) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NAME_TOO_LONG, 'Webhook name must be 100 characters or fewer.', 400);
        }

        if ($status !== ConnectConfig::WEBHOOK_STATUS_ACTIVE && $status !== ConnectConfig::WEBHOOK_STATUS_DISABLED) {
            throw new ConnectStatusException(ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Webhook status must be active or disabled.', 400);
        }

        $nowStr = (new \DateTime())->format('Y-m-d H:i:s');

        $id = $this->webhooks->insert([
            'tenant_id'    => $tenantId,
            'uuid'         => $this->uuid(),
            'environment'  => $context->environment(),
            'url'          => $url,
            'secret_hash'  => $this->vault->seal($secret),
            'events'       => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'       => $status,
            'description'  => $description,
            'name'         => $name ?: null,
            'created_at'   => $nowStr,
            'updated_at'   => $nowStr,
        ], true);

        $row = $this->webhooks->find($id);
        $row = $row === null || $row === [] ? [] : $row;

        $this->writeAudit(
            $context,
            ConnectConfig::AUDIT_ACTION_WEBHOOK_CREATED,
            implode(',', $events),
            'created webhook ' . ($row['uuid'] ?? ''),
            null,
        );

        return [
            'webhook' => $this->safeRow($row),
            'secret'  => $secret,
        ];
    }

    /**
     * Update a webhook subscription (url / events / status / secret / description).
     */
    public function update(ConnectTenantContext $context, int $webhookId, array $data): array
    {
        $row = ConnectScopeGuard::owned($this->webhooks, $context->tenantId(), $webhookId);

        if ($row === null) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NOT_FOUND, 'Webhook not found.', 404);
        }

        $update = [];

        if (array_key_exists('url', $data)) {
            $url = trim((string) $data['url']);
            if ($url === '') {
                throw new ConnectStatusException(ConnectConfig::ERROR_INVALID_WEBHOOK_URL, 'Webhook url is required.', 400);
            }
            $ssrfReason = $this->ssrf->check($url);
            if ($ssrfReason !== null) {
                throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_URL_NOT_ALLOWED, $ssrfReason, 422);
            }
            $update['url'] = $url;
        }

        if (array_key_exists('events', $data)) {
            $update['events'] = json_encode($this->normalizeEvents($data['events']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if ($status !== ConnectConfig::WEBHOOK_STATUS_ACTIVE && $status !== ConnectConfig::WEBHOOK_STATUS_DISABLED) {
                throw new ConnectStatusException(ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Webhook status must be active or disabled.', 400);
            }
            $update['status'] = $status;
        }

        if (array_key_exists('secret', $data)) {
            $secret = (string) $data['secret'];
            if (strlen($secret) < 16) {
                throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_SECRET_TOO_SHORT, 'Webhook secret must be at least 16 characters.', 400);
            }
            $update['secret_hash'] = $this->vault->seal($secret);
        }

        if (array_key_exists('description', $data)) {
            $update['description'] = $data['description'] === null ? null : trim((string) $data['description']);
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if (strlen($name) > 100) {
                throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NAME_TOO_LONG, 'Webhook name must be 100 characters or fewer.', 400);
            }
            $update['name'] = $name === '' ? null : $name;
        }

        if ($update === []) {
            throw new ConnectStatusException(ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Nothing to update.', 400);
        }

        $this->webhooks->update($webhookId, $update);

        $this->writeAudit(
            $context,
            ConnectConfig::AUDIT_ACTION_WEBHOOK_UPDATED,
            implode(',', $data['events'] ?? []),
            'updated webhook ' . ($row['uuid'] ?? ''),
            null,
        );

        $updated = $this->webhooks->find($webhookId);

        return ['webhook' => $this->safeRow($updated === null || $updated === [] ? [] : $updated)];
    }

    /**
     * Delete a webhook subscription (hard delete of the registration row).
     */
    public function delete(ConnectTenantContext $context, int $webhookId): void
    {
        $row = ConnectScopeGuard::owned($this->webhooks, $context->tenantId(), $webhookId);

        if ($row === null) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NOT_FOUND, 'Webhook not found.', 404);
        }

        $this->webhooks->delete($webhookId);

        $this->writeAudit(
            $context,
            ConnectConfig::AUDIT_ACTION_WEBHOOK_DELETED,
            implode(',', (array) json_decode((string) ($row['events'] ?? '[]'), true)),
            'deleted webhook ' . ($row['uuid'] ?? ''),
            null,
        );
    }

    /**
     * Get one webhook (tenant-scoped) or null.
     */
    public function get(ConnectTenantContext $context, int $webhookId): ?array
    {
        $row = ConnectScopeGuard::owned($this->webhooks, $context->tenantId(), $webhookId);

        if ($row === null) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NOT_FOUND, 'Webhook not found.', 404);
        }

        return $this->safeRow($row);
    }

    /**
     * List webhooks for the current tenant.
     */
    public function list(ConnectTenantContext $context): array
    {
        $rows = ConnectScopeGuard::ownedList(
            $this->webhooks,
            $context->tenantId(),
            ['environment' => $context->environment()],
        );

        $this->writeAudit(
            $context,
            ConnectConfig::AUDIT_ACTION_WEBHOOK_LISTED,
            count($rows),
            'listed webhooks',
            null,
        );

        return array_map(fn ($r) => $this->safeRow($r), $rows);
    }

    /**
     * Decrypt the signing secret for a webhook row (worker internal only —
     * never expose this to HTTP surfaces).
     */
    public function secretFor(array $webhookRow): string
    {
        $blob = $webhookRow['secret_hash'] ?? '';
        if ($blob === '') {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NOT_FOUND, 'Webhook secret is not configured.', 404);
        }

        return $this->vault->open($blob);
    }

    /**
     * Enqueue a `webhook.test` delivery for a webhook (used to validate the
     * endpoint + signature plumbing without firing a real message event).
     */
    public function test(ConnectTenantContext $context, int $webhookId): array
    {
        $row = ConnectScopeGuard::owned($this->webhooks, $context->tenantId(), $webhookId);

        if ($row === null) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_NOT_FOUND, 'Webhook not found.', 404);
        }

        if (($row['status'] ?? '') !== ConnectConfig::WEBHOOK_STATUS_ACTIVE) {
            throw new ConnectStatusException(ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Webhook is not active.', 422);
        }

        $nowStr = (new \DateTime())->format('Y-m-d H:i:s');
        $eventUuid = $this->uuid();
        $payload = [
            'id'         => $eventUuid,
            'type'       => ConnectConfig::WEBHOOK_EVENT_WEBHOOK_TEST,
            'created_at' => $nowStr,
            'data'       => [
                'webhook_uuid' => $row['uuid'] ?? null,
                'tenant_id'    => (int) $context->tenantId(),
                'environment'  => $context->environment(),
                'message'      => 'This is a test webhook from Humming Bird Connect.',
            ],
        ];

        $delivery = new ConnectWebhookDeliveryService();
        $delivery->enqueue($webhookId, $row, ConnectConfig::WEBHOOK_EVENT_WEBHOOK_TEST, $eventUuid, $payload, $nowStr);

        $this->writeAudit(
            $context,
            ConnectConfig::AUDIT_ACTION_WEBHOOK_TEST,
            $eventUuid,
            'queued test webhook ' . ($row['uuid'] ?? ''),
            null,
        );

        return [
            'webhook'       => $this->safeRow($row),
            'test_event'    => ConnectConfig::WEBHOOK_EVENT_WEBHOOK_TEST,
            'test_event_id' => $eventUuid,
            'status'        => 'queued',
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function normalizeEvents($events): array
    {
        if (! is_array($events) || $events === []) {
            throw new ConnectStatusException(ConnectConfig::ERROR_WEBHOOK_INVALID_EVENTS, 'At least one webhook event is required.', 400);
        }

        $allowed = ConnectConfig::WEBHOOK_SUBSCRIBABLE_EVENTS;
        $out     = [];

        foreach ($events as $event) {
            $event = trim((string) $event);
            if (! in_array($event, $allowed, true)) {
                throw new ConnectStatusException(
                    ConnectConfig::ERROR_WEBHOOK_INVALID_EVENTS,
                    'Unsupported webhook event: ' . $event,
                    400,
                );
            }
            if (! in_array($event, $out, true)) {
                $out[] = $event;
            }
        }

        return $out;
    }

    private function safeRow(array $row): array
    {
        unset($row['secret_hash']);

        return $row;
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(24));
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

    private function writeAudit(
        ConnectTenantContext $context,
        string $action,
        $detail,
        string $note,
        ?string $errorCode,
    ): void {
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
                'external_user_id'     => is_scalar($detail) ? (string) $detail : null,
                'humming_bird_user_id' => null,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook audit write failed: ' . $e->getMessage());
        }
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}
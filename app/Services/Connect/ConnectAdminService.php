<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Models\Connect\ConnectServiceIdentityModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use DateTime;
use Throwable;

/**
 * Humming Bird Connect — admin authorization + operations (Phase 9).
 *
 * There is no separate admin user system: an admin is a `connect_api_credentials`
 * row with role 'admin' whose `allowed_tenant_ids` (null = all tenants) bounds
 * which tenants an admin may read/mutate. The connect-auth filter already
 * verified the HMAC signature, credential environment and per-route `admin:*`
 * scope; this service adds the ADMIN-SCOPE decision layer:
 *
 *   - `canAccessTenant()` / `applyTenantScope()` — every admin query is filtered
 *     server-side with `WHERE tenant_id IN (allowed)` (or the requested tenant
 *     when it is inside the allowlist). The frontend-supplied tenant id is never
 *     trusted on its own.
 *   - `overview()` — dashboard metric cards derived entirely from existing
 *     operational tables (no fabricated numbers).
 *   - mutations (`setIdentityStatus`, `setWebhookStatus`, `updateWebhook`) —
 *     safe toggles/edits that REUSE the existing service layer
 *     (ConnectWebhookService) via a delegated tenant context so validation,
 *     safe-row masking and the domain audit row all stay intact, and record a
 *     Phase 9 admin audit row for the ones without a domain write path.
 *
 * Secret hygiene: this service never selects or echoes api_key / secret_hash /
 * webhook signing secrets. List builders in ConnectAdminListService select only
 * masked fields to begin with.
 */
class ConnectAdminService
{
    private BaseConnection $db;

    private ConnectServiceIdentityModel $identities;
    private ConnectApiAuditLogModel     $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db         = $db ?? Database::connect(config(Database::class)->defaultGroup);
        $this->identities = new ConnectServiceIdentityModel($this->db);
        $this->audit      = new ConnectApiAuditLogModel($this->db);
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    /**
     * Role gate: admin endpoints require credential role 'admin' (the per-route
     * scope gate lives in the filter). Throws 403 for plain credentials.
     *
     * @throws ConnectStatusException
     */
    public function assertAdmin(ConnectTenantContext $context): void
    {
        if (! $context->isAdmin()) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_INSUFFICIENT_SCOPE,
                'Insufficient API scope.',
                403,
            );
        }
    }

    /**
     * May this admin reach the given tenant? null allowlist = every tenant.
     */
    public function canAccessTenant(ConnectTenantContext $context, int $tenantId): bool
    {
        if (! $context->isAdmin()) {
            return false;
        }

        $allowed = $context->allowedTenantIds();

        return $allowed === null || in_array($tenantId, $allowed, true);
    }

    /**
     * The admin's tenant allowlist, or null = all tenants.
     *
     * @return int[]|null
     */
    public function allowedTenants(ConnectTenantContext $context): ?array
    {
        return $context->allowedTenantIds();
    }

    /**
     * AND the admin's scope onto a query builder.
     *
     * - `$requestedTenantId` (from the request) is only honoured when it is
     *   inside the admin allowlist; otherwise a 403 is thrown so a cross-tenant
     *   filter can never silently "work".
     * - with no requested tenant and a finite allowlist, the builder is filtered
     *   to the allowlist.
     *
     * `$column` defaults to 'tenant_id'; pass 'id' for connect_tenants.
     *
     * @throws ConnectStatusException
     */
    public function applyTenantScope(BaseBuilder $builder, ConnectTenantContext $context, ?int $requestedTenantId, string $column = 'tenant_id'): void
    {
        if ($requestedTenantId !== null) {
            if (! $this->canAccessTenant($context, $requestedTenantId)) {
                throw new ConnectStatusException(
                    ConnectConfig::ERROR_INSUFFICIENT_SCOPE,
                    'Requested tenant is outside this admin scope.',
                    403,
                );
            }

            $builder->where($column, $requestedTenantId);

            return;
        }

        if ($context->isAdmin() && $context->allowedTenantIds() !== null) {
            $builder->whereIn($column, $context->allowedTenantIds());
        }
    }

    /**
     * Fetch one row from a connect_* table bounded by the admin scope, or null.
     * An out-of-scope id returns null → the caller surfaces a 404 (no leakage).
     */
    public function findScoped(ConnectTenantContext $context, string $table, int $id): ?array
    {
        $builder = $this->db->table($table);
        $this->applyTenantScope($builder, $context, null);

        $row = $builder->where('id', $id)->get()->getRowArray();

        return ($row === null || $row === []) ? null : $row;
    }

    /**
     * A delegated tenant context that keeps the ADMIN's credential + request id
     * but swaps the scoped tenant to the target row's tenant. Used to hand a
     * mutation to the existing ConnectWebhookService — ownership checks then run
     * against the target tenant while the resulting audit row attributes the
     * action to this admin.
     */
    public function delegateContext(ConnectTenantContext $admin, int $tenantId): ConnectTenantContext
    {
        return new ConnectTenantContext(
            tenantId: $tenantId,
            credentialDbId: $admin->credentialDbId(),
            credentialId: $admin->credentialId(),
            environment: $admin->environment(),
            requestId: $admin->requestId(),
            scopes: $admin->scopes(),
            name: $admin->name(),
            expiresAt: $admin->expiresAt(),
            role: $admin->role(),
            allowedTenantIds: $admin->allowedTenantIds(),
        );
    }

    // ------------------------------------------------------------------
    // Dashboard overview — aggregates over existing operational tables only
    // ------------------------------------------------------------------

    /**
     * Metric cards for the admin dashboard. Every number is counted from the
     * live connect_* tables within the admin scope (no fabricated metrics).
     *
     * `$requestedTenantId` (from `?tenant_id=`) is validated against the admin
     * allowlist — a tenant outside the allowlist throws 403 rather than being
     * silently filtered.
     *
     * @param int|null $requestedTenantId optional query tenant filter
     *
     * @throws ConnectStatusException on invalid date bounds or out-of-scope tenant
     */
    public function overview(ConnectTenantContext $context, ?string $dateFrom, ?string $dateTo, ?int $requestedTenantId = null): array
    {
        $this->assertAdmin($context);

        return [
            'tenants'             => $this->statusBreakdown('connect_tenants', $context, ['active', 'suspended', 'disabled'], 'id', $requestedTenantId),
            'environments'        => $this->environmentList($context, $requestedTenantId),
            'service_identities'  => $this->statusBreakdown('connect_service_identities', $context, [ConnectConfig::IDENTITY_STATUS_ACTIVE, ConnectConfig::IDENTITY_STATUS_DISABLED], 'tenant_id', $requestedTenantId),
            'credentials'         => $this->statusBreakdown('connect_api_credentials', $context, [ConnectConfig::CREDENTIAL_STATUS_ACTIVE, ConnectConfig::CREDENTIAL_STATUS_REVOKED, ConnectConfig::CREDENTIAL_STATUS_EXPIRED, ConnectConfig::CREDENTIAL_STATUS_ROTATED], 'tenant_id', $requestedTenantId),
            'messages'            => $this->messageCounts($context, $dateFrom, $dateTo, $requestedTenantId),
            'webhooks'            => $this->statusBreakdown('connect_webhooks', $context, [ConnectConfig::WEBHOOK_STATUS_ACTIVE, ConnectConfig::WEBHOOK_STATUS_DISABLED], 'tenant_id', $requestedTenantId),
            'webhook_deliveries'  => $this->webhookDeliveryCounts($context, $dateFrom, $dateTo, $requestedTenantId),
            'usage_quota'         => $this->quotaAggregate($context, $requestedTenantId),
        ];
    }

    private function statusBreakdown(string $table, ConnectTenantContext $context, array $statuses, string $column = 'tenant_id', ?int $requestedTenantId = null): array
    {
        $builder = $this->db->table($table)->select('status, COUNT(*) AS cnt');
        $this->applyTenantScope($builder, $context, $requestedTenantId, $column);

        $map   = ['total' => 0];
        $total = 0;

        foreach ($statuses as $status) {
            $map[$status] = 0;
        }

        foreach ($builder->groupBy('status')->get()->getResultArray() ?? [] as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $map)) {
                $map[$status] = (int) $row['cnt'];
            }
            $total += (int) $row['cnt'];
        }

        $map['total'] = $total;

        return $map;
    }

    private function environmentList(ConnectTenantContext $context, ?int $requestedTenantId = null): array
    {
        $builder = $this->db->table('connect_tenants')->select('environment');
        $this->applyTenantScope($builder, $context, $requestedTenantId, 'id');

        $list = [];
        foreach ($builder->get()->getResultArray() ?? [] as $row) {
            $env = (string) ($row['environment'] ?? '');
            if ($env !== '' && ! in_array($env, $list, true)) {
                $list[] = $env;
            }
        }
        sort($list);

        return ['count' => count($list), 'list' => $list];
    }

    private function messageCounts(ConnectTenantContext $context, ?string $dateFrom, ?string $dateTo, ?int $requestedTenantId = null): array
    {
        $builder = $this->db->table('connect_external_messages')->select('status, COUNT(*) AS cnt');
        $this->applyTenantScope($builder, $context, $requestedTenantId);
        $this->applyDateRange($builder, 'created_at', $dateFrom, $dateTo);

        $map   = [];
        $total = 0;

        foreach (ConnectConfig::MESSAGE_STATUS_FLOW as $status) {
            $map[$status] = 0;
        }

        foreach ($builder->groupBy('status')->get()->getResultArray() ?? [] as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $map)) {
                $map[$status] = (int) $row['cnt'];
            }
            $total += (int) $row['cnt'];
        }

        $map['total']         = $total;
        $map['in_progress']   = ($map[ConnectConfig::MESSAGE_STATUS_QUEUED] ?? 0)
            + ($map[ConnectConfig::MESSAGE_STATUS_PROCESSING] ?? 0)
            + ($map[ConnectConfig::MESSAGE_STATUS_RETRYING] ?? 0);
        $map['delivered_read'] = ($map[ConnectConfig::MESSAGE_STATUS_DELIVERED] ?? 0)
            + ($map[ConnectConfig::MESSAGE_STATUS_READ] ?? 0);

        return $map;
    }

    private function webhookDeliveryCounts(ConnectTenantContext $context, ?string $dateFrom, ?string $dateTo, ?int $requestedTenantId = null): array
    {
        $builder = $this->db->table('connect_webhook_deliveries')->select('status, COUNT(*) AS cnt');
        $this->applyTenantScope($builder, $context, $requestedTenantId);
        $this->applyDateRange($builder, 'created_at', $dateFrom, $dateTo);

        $statuses = [
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING,
        ];

        $map   = ['total' => 0];
        $total = 0;
        foreach ($statuses as $status) {
            $map[$status] = 0;
        }

        foreach ($builder->groupBy('status')->get()->getResultArray() ?? [] as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $map)) {
                $map[$status] = (int) $row['cnt'];
            }
            $total += (int) $row['cnt'];
        }

        $map['total'] = $total;

        return $map;
    }

    /**
     * Whole-scope quota usage for the overview card: current-month buckets only,
     * aggregated per scope_category.
     */
    private function quotaAggregate(ConnectTenantContext $context, ?int $requestedTenantId = null): array
    {
        if (! $this->db->tableExists('connect_rate_limit_counters')) {
            return [];
        }

        $builder = $this->db->table('connect_rate_limit_counters')
            ->select('scope_category, SUM(usage_count) AS used, MIN(limit_value) AS limit_value')
            ->where('kind', 'quota')
            ->where('bucket_start >=', (new DateTime())->format('Y-m-01 00:00:00'));
        $this->applyTenantScope($builder, $context, $requestedTenantId);

        $result = [];
        foreach ($builder->groupBy('scope_category')->get()->getResultArray() ?? [] as $row) {
            $result[(string) $row['scope_category']] = [
                'used'  => (int) $row['used'],
                'limit' => (int) $row['limit_value'],
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Mutations (audited)
    // ------------------------------------------------------------------

    /**
     * Enable/disable a tenant's service identity within the admin scope.
     * Records CONNECT_ADMIN_IDENTITY_STATUS_CHANGED against the target tenant.
     *
     * @throws ConnectStatusException
     */
    public function setIdentityStatus(ConnectTenantContext $context, int $identityId, string $status): array
    {
        $this->assertAdmin($context);

        if (! in_array($status, [ConnectConfig::IDENTITY_STATUS_ACTIVE, ConnectConfig::IDENTITY_STATUS_DISABLED], true)) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER,
                'Identity status must be active or disabled.',
                400,
            );
        }

        $row = $this->findScoped($context, 'connect_service_identities', $identityId);
        if ($row === null) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND,
                'Service identity not found.',
                404,
            );
        }

        $this->identities->update($identityId, ['status' => $status]);

        $this->writeAdminAudit(
            $context,
            ConnectConfig::AUDIT_ACTION_ADMIN_IDENTITY_STATUS_CHANGED,
            'identity ' . $identityId . ' → ' . $status,
            (int) $row['tenant_id'],
            'PATCH',
            '/api/v1/connect/admin/integrations/' . $identityId . '/status',
        );

        $updated = $this->identities->find($identityId);

        return ['identity' => $this->safeIdentityRow($updated === null || $updated === [] ? [] : $updated)];
    }

    /**
     * Enable/disable a webhook within the admin scope. Delegates to the existing
     * ConnectWebhookService (validation + masked safe-row + domain audit) via a
     * target-tenant context so ownership is respected attribute is the admin.
     */
    public function setWebhookStatus(ConnectTenantContext $context, int $webhookId, string $status): array
    {
        $this->assertAdmin($context);

        if (! in_array($status, [ConnectConfig::WEBHOOK_STATUS_ACTIVE, ConnectConfig::WEBHOOK_STATUS_DISABLED], true)) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER,
                'Webhook status must be active or disabled.',
                400,
            );
        }

        $row = $this->findScoped($context, 'connect_webhooks', $webhookId);
        if ($row === null) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_WEBHOOK_NOT_FOUND,
                'Webhook not found.',
                404,
            );
        }

        return Services::connectwebhook()->update(
            $this->delegateContext($context, (int) $row['tenant_id']),
            $webhookId,
            ['status' => $status],
        );
    }

    /**
     * Safe webhook edit (name / description / status / events / url) within the
     * admin scope. Reuses ConnectWebhookService::update() for validation, SSRF
     * URL checks, event normalization, masking and auditing. Never touches the
     * signing secret.
     */
    public function updateWebhook(ConnectTenantContext $context, int $webhookId, array $data): array
    {
        $this->assertAdmin($context);

        $row = $this->findScoped($context, 'connect_webhooks', $webhookId);
        if ($row === null) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_WEBHOOK_NOT_FOUND,
                'Webhook not found.',
                404,
            );
        }

        $payload = [];
        foreach (['name', 'description', 'status', 'events', 'url'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }

        return Services::connectwebhook()->update(
            $this->delegateContext($context, (int) $row['tenant_id']),
            $webhookId,
            $payload,
        );
    }

    // ------------------------------------------------------------------
    // Audit + serialization helpers
    // ------------------------------------------------------------------

    /**
     * Secret-free admin audit row. tenant_id = the tenant that was touched so
     * the admin audit viewer (scoped to the same tenants) can see it; the
     * credential_id + request_id attribute the action to the acting admin.
     */
    public function writeAdminAudit(
        ConnectTenantContext $context,
        string $action,
        string $detail,
        int $targetTenantId,
        string $httpMethod,
        string $endpoint,
    ): void {
        try {
            $this->audit->insert([
                'tenant_id'        => $targetTenantId,
                'credential_id'    => $context->credentialDbId(),
                'request_id'       => $context->requestId(),
                'method'           => strtoupper($httpMethod),
                'endpoint'         => substr($endpoint, 0, 255),
                'response_code'    => 200,
                'status'           => 'success',
                'action'           => $action,
                'external_user_id' => substr($detail, 0, 150),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the API response.
            log_message('error', 'Connect admin audit write failed: ' . $e->getMessage());
        }
    }

    private function safeIdentityRow(array $row): array
    {
        $row['metadata'] = json_decode((string) ($row['metadata'] ?? 'null'), true);

        return $row;
    }

    // ------------------------------------------------------------------
    // Shared param validation
    // ------------------------------------------------------------------

    private function applyDateRange(BaseBuilder $builder, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $parsed = $this->parseDate($from);
            if ($parsed === null) {
                throw new ConnectStatusException(ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER, 'date_from must be a valid date (Y-m-d or Y-m-d H:i:s).', 400);
            }
            $builder->where($column . ' >=', $parsed);
        }

        if ($to !== null) {
            $parsed = $this->parseDate($to);
            if ($parsed === null) {
                throw new ConnectStatusException(ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER, 'date_to must be a valid date (Y-m-d or Y-m-d H:i:s).', 400);
            }
            $builder->where($column . ' <=', $parsed);
        }
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = DateTime::createFromFormat('Y-m-d', $value);

            return $date ? $date->format('Y-m-d 00:00:00') : null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);

            return $date ? $date->format('Y-m-d H:i:s') : null;
        }

        return null;
    }
}
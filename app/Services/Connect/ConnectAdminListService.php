<?php

namespace App\Services\Connect;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use DateTime;

/**
 * Humming Bird Connect — admin dashboard list builders (Phase 9).
 *
 * Every list is:
 *   - ADMIN-TENANT SCOPED: built through ConnectAdminService::applyTenantScope(),
 *     so a finite allowlist always ANDs `WHERE tenant_id IN (...)` and a request
 *     tenant filter outside the allowlist throws 403 server-side.
 *   - SECRET-MASKED BY CONSTRUCTION: only safe columns are ever selected. API
 *     keys, secret hashes, signatures, message bodies/payloads and webhook
 *     signing secrets are not part of any select() and can never reach a
 *     response.
 *   - PAGINATED + SORT-WHITELISTED + RANGE-BOUNDED: page/per_page clamps,
 *     orderBy comes from a fixed column map, date filters validate the format.
 *
 * All numbers aggregate the same operational tables the phases before wrote —
 * there is no synthetic metric.
 */
class ConnectAdminListService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect(config(Database::class)->defaultGroup);
    }

    // ------------------------------------------------------------------
    // Integrations (service identities)
    // ------------------------------------------------------------------

    public function integrations(ConnectTenantContext $context, array $get): array
    {
        $tenantId = $this->optInt($get, 'tenant_id');
        $env      = $this->optEnv($get);
        $status   = $this->optEnum($get, 'status', [ConnectConfig::IDENTITY_STATUS_ACTIVE, ConnectConfig::IDENTITY_STATUS_DISABLED]);

        $active = $this->db->escape(ConnectConfig::WEBHOOK_STATUS_ACTIVE);

        $builder = $this->db->table('connect_service_identities AS i')
            ->select('i.id, i.tenant_id, i.name, i.display_name, i.description, i.verification_status, i.status, i.created_at, i.updated_at', false)
            ->select('t.name AS tenant_name, t.environment AS environment', false)
            ->select('(SELECT MAX(em.created_at) FROM ' . $this->rawTable('connect_external_messages') . ' em WHERE em.service_identity_id = i.id) AS last_message_at', false)
            ->select('(SELECT COUNT(*) FROM ' . $this->rawTable('connect_webhooks') . ' w WHERE w.tenant_id = i.tenant_id AND w.status = ' . $active . ') AS active_webhooks', false)
            ->join('connect_tenants AS t', 't.id = i.tenant_id', 'left');

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 't.environment', $env);
        $this->whereColumn($builder, 'i.status', $status);

        $result = $this->paginate($builder, $get, [
            'id'         => 'i.id',
            'name'       => 'i.name',
            'status'     => 'i.status',
            'created_at' => 'i.created_at',
            'updated_at' => 'i.updated_at',
        ]);

        return $this->wrap($result);
    }

    // ------------------------------------------------------------------
    // API credentials (masked)
    // ------------------------------------------------------------------

    public function credentials(ConnectTenantContext $context, array $get): array
    {
        $tenantId = $this->optInt($get, 'tenant_id');
        $env      = $this->optEnv($get);
        $status   = $this->optEnum($get, 'status', [ConnectConfig::CREDENTIAL_STATUS_ACTIVE, ConnectConfig::CREDENTIAL_STATUS_REVOKED, ConnectConfig::CREDENTIAL_STATUS_EXPIRED, ConnectConfig::CREDENTIAL_STATUS_ROTATED]);

        $builder = $this->db->table('connect_api_credentials AS c')
            ->select('c.id, c.tenant_id, c.credential_id, c.key_prefix, c.name, c.environment, c.status, c.role, c.scopes, c.last_used_at, c.expires_at, c.revoked_at, c.created_at, c.updated_at', false)
            ->select('t.name AS tenant_name', false)
            ->join('connect_tenants AS t', 't.id = c.tenant_id', 'left');

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 'c.environment', $env);
        $this->whereColumn($builder, 'c.status', $status);

        $result = $this->paginate($builder, $get, [
            'id'           => 'c.id',
            'credential_id' => 'c.credential_id',
            'name'         => 'c.name',
            'status'       => 'c.status',
            'role'         => 'c.role',
            'created_at'   => 'c.created_at',
            'last_used_at' => 'c.last_used_at',
            'expires_at'   => 'c.expires_at',
        ]);

        foreach ($result['items'] as &$item) {
            $item['scopes'] = json_decode((string) ($item['scopes'] ?? '[]'), true) ?: [];
        }
        unset($item);

        return $this->wrap($result);
    }

    // ------------------------------------------------------------------
    // Messages (metadata only — bodies/payloads never selected)
    // ------------------------------------------------------------------

    public function messages(ConnectTenantContext $context, array $get): array
    {
        $tenantId    = $this->optInt($get, 'tenant_id');
        $env         = $this->optEnv($get);
        $status      = $this->optEnum($get, 'status', ConnectConfig::MESSAGE_STATUS_FLOW);
        $messageType = $this->optEnum($get, 'message_type', [
            ConnectConfig::MESSAGE_TYPE_NOTIFICATION,
            ConnectConfig::MESSAGE_TYPE_TRANSACTIONAL,
            ConnectConfig::MESSAGE_TYPE_REMINDER,
            ConnectConfig::MESSAGE_TYPE_ALERT,
        ]);
        $search  = $this->optString($get, 'search');
        $dateFrom = $this->optString($get, 'date_from');
        $dateTo   = $this->optString($get, 'date_to');

        $builder = $this->db->table('connect_external_messages AS m')
            ->select('m.id, m.uuid, m.message_uuid, m.external_message_id, m.request_id, m.tenant_id, m.environment, m.service_identity_id, m.external_user_id, m.humming_bird_user_id, m.message_type, m.status, m.room_id, m.message_id, m.error_code, m.error_message, m.created_at, m.updated_at', false)
            ->select('(SELECT s.name FROM ' . $this->rawTable('connect_service_identities') . ' s WHERE s.id = m.service_identity_id) AS service_name', false);

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 'm.environment', $env);
        $this->whereColumn($builder, 'm.status', $status);
        $this->whereColumn($builder, 'm.message_type', $messageType);
        $this->applyDateRange($builder, 'm.created_at', $dateFrom, $dateTo);
        $this->applySearch($builder, ['m.external_message_id', 'm.uuid', 'm.message_uuid', 'm.request_id'], $search);

        $result = $this->paginate($builder, $get, [
            'id'                => 'm.id',
            'external_message_id' => 'm.external_message_id',
            'status'            => 'm.status',
            'message_type'      => 'm.message_type',
            'created_at'        => 'm.created_at',
            'updated_at'        => 'm.updated_at',
        ]);

        return $this->wrap($result);
    }

    /**
     * One message's safe metadata. Returns [] when out of scope / not found.
     */
    public function messageDetail(ConnectTenantContext $context, int $id): array
    {
        $row = Services::connectadmin()->findScoped($context, 'connect_external_messages', $id);
        if ($row === null || $row === []) {
            return [];
        }

        $allowed = [
            'id', 'uuid', 'message_uuid', 'external_message_id', 'request_id',
            'tenant_id', 'environment', 'service_identity_id', 'external_user_id',
            'humming_bird_user_id', 'message_type', 'status', 'room_id',
            'message_id', 'error_code', 'error_message', 'created_at', 'updated_at',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Delivery events (connect_message_status ledger)
    // ------------------------------------------------------------------

    public function deliveryEvents(ConnectTenantContext $context, array $get): array
    {
        $tenantId = $this->optInt($get, 'tenant_id');
        $env      = $this->optEnv($get);
        $status   = $this->optEnum($get, 'status', ConnectConfig::MESSAGE_STATUS_FLOW);
        $externalMessageId = $this->optString($get, 'external_message_id');
        $dateFrom = $this->optString($get, 'date_from');
        $dateTo   = $this->optString($get, 'date_to');

        $builder = $this->db->table('connect_message_status AS s')
            ->select('s.id, s.uuid, s.tenant_id, s.environment, s.external_message_id, s.connect_message_id, s.humming_bird_message_id, s.status, s.status_reason, s.occurred_at, s.created_at');

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 's.environment', $env);
        $this->whereColumn($builder, 's.status', $status);
        $this->whereColumn($builder, 's.external_message_id', $externalMessageId);
        $this->applyDateRange($builder, 's.occurred_at', $dateFrom, $dateTo);

        $result = $this->paginate($builder, $get, [
            'id'           => 's.id',
            'status'       => 's.status',
            'occurred_at'  => 's.occurred_at',
            'created_at'   => 's.created_at',
        ]);

        return $this->wrap($result);
    }

    // ------------------------------------------------------------------
    // Webhooks (secret_hash never selected)
    // ------------------------------------------------------------------

    public function webhooks(ConnectTenantContext $context, array $get): array
    {
        $tenantId = $this->optInt($get, 'tenant_id');
        $env      = $this->optEnv($get);
        $status   = $this->optEnum($get, 'status', [ConnectConfig::WEBHOOK_STATUS_ACTIVE, ConnectConfig::WEBHOOK_STATUS_DISABLED]);

        $delivered = $this->db->escape(ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED);
        $failed    = $this->db->escape(ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED);

        $builder = $this->db->table('connect_webhooks AS w')
            ->select('w.id, w.uuid, w.tenant_id, w.environment, w.name, w.url, w.events, w.status, w.description, w.last_success_at, w.last_failure_at, w.created_at, w.updated_at', false)
            ->select('t.name AS tenant_name', false)
            ->select('(SELECT COUNT(*) FROM ' . $this->rawTable('connect_webhook_deliveries') . ' d WHERE d.webhook_id = w.id AND d.status = ' . $delivered . ') AS delivered_count', false)
            ->select('(SELECT COUNT(*) FROM ' . $this->rawTable('connect_webhook_deliveries') . ' d WHERE d.webhook_id = w.id AND d.status = ' . $failed . ') AS failed_count', false)
            ->join('connect_tenants AS t', 't.id = w.tenant_id', 'left');

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 'w.environment', $env);
        $this->whereColumn($builder, 'w.status', $status);

        $result = $this->paginate($builder, $get, [
            'id'              => 'w.id',
            'status'          => 'w.status',
            'created_at'      => 'w.created_at',
            'updated_at'      => 'w.updated_at',
            'last_success_at' => 'w.last_success_at',
            'last_failure_at' => 'w.last_failure_at',
        ]);

        foreach ($result['items'] as &$item) {
            $item['events'] = json_decode((string) ($item['events'] ?? '[]'), true) ?: [];
        }
        unset($item);

        return $this->wrap($result);
    }

    public function webhookDeliveries(ConnectTenantContext $context, array $get): array
    {
        $tenantId = $this->optInt($get, 'tenant_id');
        $env      = $this->optEnv($get);
        $status   = $this->optEnum($get, 'status', [
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,
            ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING,
        ]);
        $webhookId = $this->optInt($get, 'webhook_id');
        $eventType = $this->optString($get, 'event_type');
        $dateFrom  = $this->optString($get, 'date_from');
        $dateTo    = $this->optString($get, 'date_to');

        $builder = $this->db->table('connect_webhook_deliveries AS d')
            ->select('d.id, d.uuid, d.webhook_id, d.webhook_uuid, d.tenant_id, d.environment, d.event_id, d.event_type, d.payload_hash, d.status, d.attempt, d.http_status, d.response_time_ms, d.next_retry_at, d.delivered_at, d.last_error, d.last_error_code, d.created_at, d.updated_at');

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 'd.environment', $env);
        $this->whereColumn($builder, 'd.status', $status);
        $this->whereColumn($builder, 'd.webhook_id', $webhookId);
        $this->whereColumn($builder, 'd.event_type', $eventType);
        $this->applyDateRange($builder, 'd.created_at', $dateFrom, $dateTo);

        $result = $this->paginate($builder, $get, [
            'id'           => 'd.id',
            'status'       => 'd.status',
            'attempt'      => 'd.attempt',
            'created_at'   => 'd.created_at',
            'delivered_at' => 'd.delivered_at',
        ]);

        return $this->wrap($result);
    }

    // ------------------------------------------------------------------
    // Usage (rate + quota counters, current windows)
    // ------------------------------------------------------------------

    public function usage(ConnectTenantContext $context, array $get): array
    {
        $tenantId = $this->optInt($get, 'tenant_id');
        $env      = $this->optEnv($get);

        $admin = Services::connectadmin();

        // Tenants in scope (id/name/environment) — the rows we report against.
        $tenantQuery = $this->db->table('connect_tenants')->select('id, name, environment');
        $admin->applyTenantScope($tenantQuery, $context, $tenantId, 'id');
        $this->whereColumn($tenantQuery, 'environment', $env);
        $tenants = $tenantQuery->get()->getResultArray() ?? [];

        // Quota buckets: current month (the live window; old months are irrelevant).
        $monthStart = (new DateTime())->format('Y-m-01 00:00:00');
        $quotaQuery = $this->db->table('connect_rate_limit_counters')
            ->select('tenant_id, environment, scope_category, bucket_start, usage_count, limit_value')
            ->where('kind', 'quota')
            ->where('bucket_start >=', $monthStart);
        $admin->applyTenantScope($quotaQuery, $context, $tenantId);
        $this->whereColumn($quotaQuery, 'environment', $env);
        $quotaBy = $this->latestByKey($quotaQuery->orderBy('bucket_start', 'ASC')->get()->getResultArray() ?? []);

        // Rate buckets: anything still inside its window from the last hour.
        $hourStart = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $rateQuery = $this->db->table('connect_rate_limit_counters')
            ->select('tenant_id, environment, scope_category, bucket_start, usage_count, limit_value')
            ->where('kind', 'rate')
            ->where('bucket_start >=', $hourStart);
        $admin->applyTenantScope($rateQuery, $context, $tenantId);
        $this->whereColumn($rateQuery, 'environment', $env);
        $rateBy = $this->latestByKey($rateQuery->orderBy('bucket_start', 'ASC')->get()->getResultArray() ?? []);

        $out = ['tenants' => [], 'totals' => ['quota_used' => 0, 'rate_current' => 0]];

        foreach ($tenants as $tenant) {
            $tid   = (int) $tenant['id'];
            $tenv  = (string) $tenant['environment'];
            $entry = [
                'tenant_id'   => $tid,
                'tenant_name' => $tenant['name'],
                'environment' => $tenv,
                'quota'       => [],
                'rate'        => [],
            ];

            foreach ([
                'messages.month' => $monthStart,
                'messages.day'   => (new DateTime())->format('Y-m-d 00:00:00'),
            ] as $category => $periodStart) {
                $entry['quota'][$category] = $this->windowSummary($quotaBy, $tid, $tenv, $category, $periodStart);
                $out['totals']['quota_used'] += $entry['quota'][$category]['used'] ?? 0;
            }

            foreach ($rateBy as $key => $row) {
                [$rtid, $rtenv, $category] = explode(':', $key, 3);
                if ((int) $rtid !== $tid || $rtenv !== $tenv) {
                    continue;
                }
                $entry['rate'][$category] = [
                    'used'           => (int) $row['usage_count'],
                    'limit'          => (int) $row['limit_value'],
                    'remaining'      => max(0, (int) $row['limit_value'] - (int) $row['usage_count']),
                    'window_start'   => $row['bucket_start'],
                    'window_seconds' => $this->windowSeconds($category),
                ];
                $out['totals']['rate_current'] += (int) $row['usage_count'];
            }

            if ($entry['quota'] !== [] || $entry['rate'] !== []) {
                $out['tenants'][] = $entry;
            }
        }

        return $this->wrap($out);
    }

    private function windowSummary(array $rowsByKey, int $tenantId, string $env, string $category, string $periodStart): array
    {
        $row = $rowsByKey[$tenantId . ':' . $env . ':' . $category] ?? null;

        if ($row === null || ($row['bucket_start'] ?? '') < $periodStart) {
            return ['used' => 0, 'limit' => 0, 'remaining' => 0, 'period_start' => null];
        }

        $used  = (int) $row['usage_count'];
        $limit = (int) $row['limit_value'];

        return [
            'used'          => $used,
            'limit'         => $limit,
            'remaining'     => max(0, $limit - $used),
            'period_start'  => $row['bucket_start'],
        ];
    }

    /**
     * Group rows by (tenant:env:category) keeping the LATEST bucket only.
     */
    private function latestByKey(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(int) $row['tenant_id'] . ':' . (string) $row['environment'] . ':' . (string) $row['scope_category']] = $row;
        }

        return $byKey;
    }

    // ------------------------------------------------------------------
    // Audit logs
    // ------------------------------------------------------------------

    public function auditLogs(ConnectTenantContext $context, array $get): array
    {
        $tenantId     = $this->optInt($get, 'tenant_id');
        $status       = $this->optEnum($get, 'status', ['success', 'denied', 'error']);
        $errorCode    = $this->optString($get, 'error_code');
        $action       = $this->optString($get, 'action');
        $responseCode = $this->optInt($get, 'response_code');
        $search       = $this->optString($get, 'search');
        $dateFrom     = $this->optString($get, 'date_from');
        $dateTo       = $this->optString($get, 'date_to');

        $builder = $this->db->table('connect_api_audit_logs AS a')
            ->select('a.id, a.tenant_id, a.credential_id, a.request_id, a.method, a.endpoint, a.ip_address, a.user_agent, a.response_code, a.status, a.error_code, a.action, a.external_user_id, a.humming_bird_user_id, a.created_at');

        $this->tenantScope($builder, $context, $tenantId);
        $this->whereColumn($builder, 'a.status', $status);
        $this->whereColumn($builder, 'a.error_code', $errorCode);
        $this->whereColumn($builder, 'a.action', $action);
        $this->whereColumn($builder, 'a.response_code', $responseCode);
        $this->applySearch($builder, ['a.request_id', 'a.endpoint'], $search);
        $this->applyDateRange($builder, 'a.created_at', $dateFrom, $dateTo);

        $result = $this->paginate($builder, $get, [
            'id'            => 'a.id',
            'response_code' => 'a.response_code',
            'status'        => 'a.status',
            'created_at'    => 'a.created_at',
        ]);

        return $this->wrap($result);
    }

    // ------------------------------------------------------------------
    // Shared plumbing
    // ------------------------------------------------------------------

    private function wrap(array $result): array
    {
        return $result;
    }

    /**
     * Admin tenant scope + optional request tenant filter (403 outside allowlist).
     */
    private function tenantScope(BaseBuilder $builder, ConnectTenantContext $context, ?int $tenantId): void
    {
        Services::connectadmin()->applyTenantScope($builder, $context, $tenantId);
    }

    private function whereColumn(BaseBuilder $builder, string $column, $value): void
    {
        if ($value !== null && $value !== '') {
            $builder->where($column, $value);
        }
    }

    private function applySearch(BaseBuilder $builder, array $columns, ?string $query): void
    {
        if ($query === null || $query === '' || $columns === []) {
            return;
        }

        $builder->groupStart();
        $first = true;
        foreach ($columns as $column) {
            if ($first) {
                $builder->like($column, $query);
                $first = false;
            } else {
                $builder->orLike($column, $query);
            }
        }
        $builder->groupEnd();
    }

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

    /**
     * Qualifies a physical table name for use inside a raw select() fragment.
     *
     * The correlated subqueries below are passed to select(..., false), which
     * turns off CodeIgniter's identifier protection, so the table name has to be
     * prefixed by hand. It is empty on RDS but `db_` in the SQLite tests group,
     * so hardcoding the bare name only works in production.
     */
    private function rawTable(string $table): string
    {
        return $this->db->getPrefix() . $table;
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

    private function paginate(BaseBuilder $builder, array $get, array $sortMap): array
    {
        $page    = max(1, (int) ($get['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($get['per_page'] ?? 25)));

        // countAllResults(false) is mandatory here. The default ($reset = true)
        // calls BaseBuilder::resetSelect(), which runs setAliasedTables([]) on
        // the SHARED connection object. Because the clone shares that same
        // connection, the alias registry is wiped for this builder too, and the
        // orderBy()/select() below then compile `a.id` as `db_a`.`id`, which
        // SQLite rejects. Skipping the reset keeps the alias intact; the clone
        // is discarded immediately so it needs no cleanup.
        $total = (int) (clone $builder)->countAllResults(false);

        $sort  = $sortMap[$get['sort'] ?? ''] ?? reset($sortMap);
        $order = strtolower((string) ($get['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $items = $builder->orderBy($sort, $order)->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'      => ($items === null || $items === []) ? [] : $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    private function windowSeconds(string $category): int
    {
        if (preg_match('/:(\d+)$/', $category, $m)) {
            return (int) $m[1];
        }

        return 60;
    }

    private function optInt(array $get, string $key): ?int
    {
        if (! array_key_exists($key, $get) || $get[$key] === null || $get[$key] === '') {
            return null;
        }

        if (! preg_match('/^\d+$/', (string) $get[$key])) {
            throw new ConnectStatusException(ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER, $key . ' must be a positive integer.', 400);
        }

        return (int) $get[$key];
    }

    private function optString(array $get, string $key): ?string
    {
        if (! array_key_exists($key, $get)) {
            return null;
        }

        $value = trim((string) $get[$key]);

        return $value === '' ? null : $value;
    }

    private function optEnum(array $get, string $key, array $allowed): ?string
    {
        $value = $this->optString($get, $key);

        if ($value === null) {
            return null;
        }

        if (! in_array($value, $allowed, true)) {
            throw new ConnectStatusException(ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER, 'Invalid ' . $key . '.', 400);
        }

        return $value;
    }

    private function optEnv(array $get): ?string
    {
        return $this->optEnum($get, 'environment', [
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::TENANT_ENVIRONMENT_PRODUCTION,
        ]);
    }
}
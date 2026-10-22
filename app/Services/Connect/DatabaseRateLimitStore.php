<?php

namespace App\Services\Connect;

use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * Humming Bird Connect — atomic rate-limit / quota counter store.
 *
 * Backs ConnectRateLimiter (rate windows) and ConnectQuotaService (usage
 * quotas) with the `connect_rate_limit_counters` table created by the Phase 8
 * migration. Atomicity comes from a compare-and-set UPDATE:
 *
 *   UPDATE ... SET usage_count = usage_count + 1
 *   WHERE <bucket key> AND usage_count < <limit>
 *
 * guarded by affectedRows(). On MySQL the UPDATE takes a row lock so two
 * racing requests serialize; on SQLite the single writer serializes them.
 * Lost row-creation races (a fresh bucket inserted concurrently) are handled
 * with a unique-key INSERT + guarded retry — never a double count, never a
 * false deny/allow.
 *
 * Graceful degradation: when the counter table is missing (a deployment that
 * shipped the code before MIGRATE=1 created the table), rate windows fall back
 * to the Phase-2 counting of `connect_api_audit_logs` rows (non-atomic but
 * identical to the previous behaviour), and quota peeks always report 0 so the
 * quota path fails open and never rejects a message because of missing schema.
 */
class DatabaseRateLimitStore implements ConnectRateLimitStoreInterface
{
    public const TABLE = 'connect_rate_limit_counters';

    private ?bool $availableMemo = null;

    public function __construct()
    {
        $this->availableMemo = null;
    }

    public function available(): bool
    {
        return $this->tableAvailable();
    }

    public function peek(
        int $tenantId,
        int $credentialId,
        string $environment,
        string $kind,
        string $scopeCategory,
        int $windowSeconds,
        string $bucketStart,
    ): int {
        if (! $this->tableAvailable()) {
            // Legacy path — Phase 2 behaviour (request-count approximation).
            return $this->countRecent($tenantId, $credentialId, $windowSeconds);
        }

        $db = Database::connect(config(Database::class)->defaultGroup);

        $row = $db->table(self::TABLE)
            ->select('usage_count')
            ->where('kind', $kind)
            ->where('scope_category', $scopeCategory)
            ->where('tenant_id', $tenantId)
            ->where('environment', $environment)
            ->where('credential_id', $credentialId)
            ->where('bucket_start', $bucketStart)
            ->get()
            ->getRowArray();

        return (int) ($row['usage_count'] ?? 0);
    }

    public function reserve(
        int $tenantId,
        int $credentialId,
        string $environment,
        string $kind,
        string $scopeCategory,
        int $windowSeconds,
        string $bucketStart,
        int $limit,
    ): int {
        if (! $this->tableAvailable()) {
            // Legacy approximation: count audit rows, do not consume.
            return $this->countRecent($tenantId, $credentialId, $windowSeconds);
        }

        if ($limit <= 0) {
            return $this->peek(
                $tenantId,
                $credentialId,
                $environment,
                $kind,
                $scopeCategory,
                $windowSeconds,
                $bucketStart,
            );
        }

        $db = Database::connect(config(Database::class)->defaultGroup);
        $now = date('Y-m-d H:i:s');

        $count = $this->peek($tenantId, $credentialId, $environment, $kind, $scopeCategory, $windowSeconds, $bucketStart);

        // Fast-path: capacity already reached — nothing to claim, no write.
        if ($count >= $limit) {
            return $count;
        }

        $where = [
            'kind'           => $kind,
            'scope_category' => $scopeCategory,
            'tenant_id'      => $tenantId,
            'environment'    => $environment,
            'credential_id'  => $credentialId,
            'bucket_start'   => $bucketStart,
        ];

        // Try the atomic guarded increment on an existing bucket row.
        $db->table(self::TABLE)
            ->where($where)
            ->where('usage_count <', $limit)
            ->set('usage_count', 'usage_count + 1', false)
            ->set('updated_at', $now)
            ->update();

        if ((int) $db->affectedRows() === 1) {
            return $count + 1;
        }

        // No row yet → first unit of a fresh bucket (count was 0).
        if ($count === 0) {
            try {
                $db->table(self::TABLE)->insert([
                    'kind'           => $kind,
                    'scope_category' => $scopeCategory,
                    'tenant_id'      => $tenantId,
                    'environment'    => $environment,
                    'credential_id'  => $credentialId,
                    'bucket_start'   => $bucketStart,
                    'usage_count'    => 1,
                    'limit_value'    => $limit,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ], true);

                // Opportunistic clean-up of expired rate buckets (never quota).
                $this->purgeExpiredRateBuckets(
                    $this->retentionSeconds(),
                );

                return 1;
            } catch (DatabaseException $e) {
                // Lost the bucket-creation race — a concurrent request inserted
                // the row; fall through to the guarded retry on the existing row.
            }
        }

        // Either the bucket row now exists (lost race) or it was already at
        // capacity. Retry the guarded increment once; 0 affected rows means
        // the bucket hit its limit and this reserve is denied.
        $db->table(self::TABLE)
            ->where($where)
            ->where('usage_count <', $limit)
            ->set('usage_count', 'usage_count + 1', false)
            ->set('updated_at', $now)
            ->update();

        return (int) $db->affectedRows() === 1 ? $count + 1 : $count;
    }

    /**
     * Purge expired 'rate' buckets last touched more than $olderThanSeconds
     * ago. Bounded by the (kind, bucket_start) index. Purging on `updated_at`
     * (the wall-clock moment of the last claim) — NOT on `bucket_start` — so a
     * bucket whose start instant lies behind the server clock (injected/tampered
     * clocks, or long-aligned windows on a fresh deploy) is never deleted the
     * instant it is created. quota counters are never purged here.
     */
    public function purgeExpiredRateBuckets(int $olderThanSeconds): int
    {
        if (! $this->tableAvailable() || $olderThanSeconds <= 0) {
            return 0;
        }

        $db = Database::connect(config(Database::class)->defaultGroup);
        $cutoff = date('Y-m-d H:i:s', time() - $olderThanSeconds);

        $db->table(self::TABLE)
            ->where('kind', 'rate')
            ->where('updated_at <', $cutoff)
            ->delete();

        return (int) $db->affectedRows();
    }

    /**
     * Phase-2 fixed-window count over connect_api_audit_logs. Kept as the
     * graceful fallback for schemas that predate the Phase 8 counter table.
     */
    public function countRecent(int $tenantId, int $credentialDbId, int $windowSeconds): int
    {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $since = date('Y-m-d H:i:s', time() - $windowSeconds);

        return (int) $db->table('connect_api_audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('credential_id', $credentialDbId)
            ->where('created_at >=', $since)
            ->countAllResults();
    }

    private function retentionSeconds(): int
    {
        $config = config(\Config\Connect::class);

        return (int) $config->rateLimitBucketRetentionSeconds;
    }

    private function tableAvailable(): bool
    {
        if ($this->availableMemo === null) {
            $db = Database::connect(config(Database::class)->defaultGroup);

            // Probe with a real query, not $db->tableExists(): CI4 caches the
            // connection's listTables() result, so a just-dropped (or just-
            // migrated) table is reported stale until the cache resets. A
            // guarded 1-row probe reflects the actual schema.
            try {
                $result = $db->table(self::TABLE)->get(1);
                $this->availableMemo = $result !== false;
            } catch (DatabaseException $e) {
                $this->availableMemo = false;
            }
        }

        return (bool) $this->availableMemo;
    }
}
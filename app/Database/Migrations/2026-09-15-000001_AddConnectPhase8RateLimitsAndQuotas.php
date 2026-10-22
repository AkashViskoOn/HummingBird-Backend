<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Humming Bird Connect — Phase 8 rate-limit buckets + usage-quota counters.
 *
 * Phase 2 shipped a fixed-window rate limiter that counted rows in
 * `connect_api_audit_logs` (reads, non-atomic — the counter lags by one
 * request and concurrent bursts can overshoot). Phase 8 replaces that backing
 * store with a dedicated, atomic counter table while keeping the same limiter
 * surface, and adds durable usage quotas for accepted Connect messages.
 *
 * A single `connect_rate_limit_counters` row is the unit of accounting:
 *
 *   kind          'rate'  → rate-limit fixed window; 'quota' → usage period
 *   scope_category 'all:60' (general per-minute), 'cat:messages.send:60'
 *                 (per-category), 'messages.month' / 'messages.day' (quota)
 *   bucket_start  window/period start (UTC-free, server-local like the rest
 *                 of the app; period keys use calendar bounds YYYY-MM/YYYY-MM-DD)
 *   usage_count   units already consumed inside the bucket
 *   limit_value   the cap that was in force when the bucket was created
 *
 * Enforcement is a compare-and-set: `UPDATE ... SET usage_count = usage_count + 1
 * WHERE ... AND usage_count < limit` guarded by affectedRows, mirroring the
 * Phase 5 outbox claim pattern. On both MySQL (row lock) and SQLite
 * (serialized writer) this is atomic without `SELECT ... FOR UPDATE`.
 *
 * Everything is guarded (tableExists) and additive — legacy schemas upgrade in
 * place, Phase-8 schemas are a no-op. Reversible via down().
 */
class AddConnectPhase8RateLimitsAndQuotas extends Migration
{
    public function up()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_rate_limit_counters')) {
            return;
        }

        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'kind'           => ['type' => 'VARCHAR', 'constraint' => 16], // 'rate' | 'quota'
            'scope_category' => ['type' => 'VARCHAR', 'constraint' => 64],
            'tenant_id'      => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'environment'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'credential_id'  => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0], // 0 = tenant+env wide
            'bucket_start'   => ['type' => 'DATETIME'],
            'usage_count'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'limit_value'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME'],
            'updated_at'     => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('kind');
        $this->forge->addKey('tenant_id');
        $this->forge->addKey('environment');
        $this->forge->addKey('bucket_start');
        $this->forge->addUniqueKey(
            ['kind', 'scope_category', 'tenant_id', 'environment', 'credential_id', 'bucket_start'],
            'uq_connect_rl_kind_scope_tn_env_cred_ws',
        );
        // Clean-up index so opportunistic expired-bucket purges stay indexed.
        $this->forge->addKey(['kind', 'bucket_start'], false, false, 'idx_connect_rl_kind_ws');
        $this->forge->createTable('connect_rate_limit_counters');
    }

    public function down()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_rate_limit_counters')) {
            $this->forge->dropTable('connect_rate_limit_counters');
        }
    }
}
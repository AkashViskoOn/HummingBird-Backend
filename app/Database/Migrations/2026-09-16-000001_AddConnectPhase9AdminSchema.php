<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Humming Bird Connect — Phase 9 admin dashboard schema.
 *
 * The Connect admin dashboard reuses the existing connect-credential + HMAC
 * authentication (no separate admin user system). Phase 9 only adds:
 *
 *   1. `connect_api_credentials.role`            — 'api' (default) | 'admin'.
 *      The connect-auth filter refuses any `admin:connect:*` scope unless the
 *      credential role is 'admin' (defence against scope mis-granting).
 *
 *   2. `connect_api_credentials.allowed_tenant_ids` — optional JSON array of
 *      tenant ids an admin may operate on. NULL (the default) = all tenants.
 *      Enforcement is server-side in ConnectAdminService — the frontend value
 *      is never trusted.
 *
 *   3. Justified composite indexes for the common admin list/metric queries
 *      (tenant → environment → status → time). These mirror how the worker and
 *      the Phase 7/8 paths already query, and keep dashboard pages bounded on
 *      production row counts. Only added when absent (indexExists guard).
 *
 * Everything is guarded (fieldExists / indexExists) — legacy schemas upgrade
 * in place, Phase-9 schemas are a no-op. Reversible via down().
 */
class AddConnectPhase9AdminSchema extends Migration
{
    public function up()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_api_credentials')) {
            $fields = $db->getFieldNames('connect_api_credentials');

            if (! in_array('role', $fields, true)) {
                $this->forge->addColumn('connect_api_credentials', [
                    'role' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 20,
                        'default'    => 'api',
                        'null'       => false,
                    ],
                ]);
            }

            if (! in_array('allowed_tenant_ids', $fields, true)) {
                $this->forge->addColumn('connect_api_credentials', [
                    'allowed_tenant_ids' => [
                        'type'    => 'JSON',
                        'null'    => true,
                        'default' => null,
                    ],
                ]);
            }
        }

        // Admin list/metric composite indexes (justified, additive).
        $this->addKeyIfMissing(
            $db,
            'connect_external_messages',
            'idx_cnx_msg_tn_env_status_created',
            ['tenant_id', 'environment', 'status', 'created_at'],
        );
        $this->addKeyIfMissing(
            $db,
            'connect_message_status',
            'idx_cnx_msgst_tn_env_status_occurred',
            ['tenant_id', 'environment', 'status', 'occurred_at'],
        );
        $this->addKeyIfMissing(
            $db,
            'connect_api_audit_logs',
            'idx_cnx_audit_tn_created',
            ['tenant_id', 'created_at'],
        );
        $this->addKeyIfMissing(
            $db,
            'connect_webhook_deliveries',
            'idx_cnx_wbd_tn_status',
            ['tenant_id', 'status'],
        );
    }

    public function down()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_api_credentials')) {
            $fields = $db->getFieldNames('connect_api_credentials');

            if (in_array('role', $fields, true)) {
                $this->forge->dropColumn('connect_api_credentials', ['role']);
            }
            if (in_array('allowed_tenant_ids', $fields, true)) {
                $this->forge->dropColumn('connect_api_credentials', ['allowed_tenant_ids']);
            }
        }

        foreach ([
            ['connect_external_messages', 'idx_cnx_msg_tn_env_status_created'],
            ['connect_message_status', 'idx_cnx_msgst_tn_env_status_occurred'],
            ['connect_api_audit_logs', 'idx_cnx_audit_tn_created'],
            ['connect_webhook_deliveries', 'idx_cnx_wbd_tn_status'],
        ] as [$table, $index]) {
            if ($this->indexExists($db, $table, $index)) {
                try {
                    $this->forge->dropKey($table, $index);
                } catch (\Throwable $e) {
                    // Column may be absent on a partially-applied schema.
                }
            }
        }
    }

    private function addKeyIfMissing($db, string $table, string $indexName, array $columns): void
    {
        if (! $db->tableExists($table) || $this->indexExists($db, $table, $indexName)) {
            return;
        }

        $this->forge->addKey($columns, false, false, $indexName);
        $this->forge->processIndexes($table);
    }

    private function indexExists($db, string $table, string $indexName): bool
    {
        if (! $db->tableExists($table)) {
            return false;
        }

        foreach ((array) $db->getIndexData($table) as $key => $index) {
            $candidate = is_object($index) ? ($index->name ?? null) : ($key ?? null);
            if ($candidate === $indexName) {
                return true;
            }
        }

        return false;
    }
}
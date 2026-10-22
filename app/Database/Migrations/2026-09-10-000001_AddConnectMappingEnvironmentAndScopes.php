<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Humming Bird Connect — Phase 3 user mapping schema.
 *
 * Adapts the Phase 1 foundation to fully tenant/environment-scoped external
 * user mapping:
 *
 *  1. connect_user_mappings.external_user_id is a platform-supplied string
 *     (e.g. "remark_user_123"). Phase 1 created it as BIGINT — MySQL would
 *     reject alphanumeric external ids. Converted to VARCHAR(150) to match
 *     connect_external_users.external_user_id.
 *  2. `environment` (test|production) added to connect_external_users and
 *     connect_user_mappings, backfilled from the owning connect_tenants row,
 *     then the two Phase-3 uniqueness rules are enforced at the database:
 *       UNIQUE(tenant_id, environment, external_user_id)
 *       UNIQUE(tenant_id, environment, humming_bird_user_id)
 *  3. connect_api_audit_logs gains action/external_user_id/
 *     humming_bird_user_id for domain event tracing.
 *
 * Every change is guarded (fieldExists / indexExists / data-type checks) so
 * this migration is a safe no-op on the Phase-3-shaped schema that
 * CreateHummingBirdConnectFoundation now emits, and it upgrades a legacy
 * (already-applied) MySQL schema in place. Existing rows are backfilled and
 * preserved — nothing is dropped or truncated. If legacy data already maps the
 * sAME Humming Bird user twice within one tenant/environment, the second
 * unique index cannot be created: the duplicates are reported via log_message
 * (they are NOT deleted) and a plain index is created instead.
 */
class AddConnectMappingEnvironmentAndScopes extends Migration
{
    public function up()
    {
        $db = Database::connect();

        // ------------------------------------------------------------------
        // 1. connect_user_mappings.external_user_id → VARCHAR(150)
        // ------------------------------------------------------------------
        if ($db->tableExists('connect_user_mappings')
            && $this->columnIsNumeric($db, 'connect_user_mappings', 'external_user_id')) {
            // MySQL: unwind the unique index before the type change, then
            // recreate it below with the full column set.
            if ($this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_tenant')) {
                $this->forge->dropKey('connect_user_mappings', 'uq_connect_mapping_tenant');
            }
            $this->forge->modifyColumn('connect_user_mappings', [
                'external_user_id' => ['name' => 'external_user_id', 'type' => 'VARCHAR', 'constraint' => 150],
            ]);
        }

        // ------------------------------------------------------------------
        // 2. environment column on both mapping tables + backfill
        // ------------------------------------------------------------------
        foreach (['connect_external_users', 'connect_user_mappings'] as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }
            if (!$db->fieldExists('environment', $table)) {
                $this->forge->addColumn($table, [
                    'environment' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                ]);
            }

            $prefixed = $db->DBPrefix . $table;
            $db->query("UPDATE {$prefixed} "
                . 'SET environment = (SELECT environment FROM ' . $db->DBPrefix . 'connect_tenants t WHERE t.id = ' . $prefixed . '.tenant_id) '
                . 'WHERE environment IS NULL OR environment = \'\'');
        }

        // ------------------------------------------------------------------
        // 3. Unique + index enforcement (guarded)
        // ------------------------------------------------------------------
        if (!$this->indexExists($db, 'connect_external_users', 'uq_connect_ext_user_tenant_env')) {
            $this->forge->addUniqueKey(
                ['tenant_id', 'environment', 'external_user_id'],
                'uq_connect_ext_user_tenant_env',
            );
            $this->forge->processIndexes('connect_external_users');
        }

        if ($db->fieldExists('environment', 'connect_external_users') && ! $this->indexExists($db, 'connect_external_users', 'connect_external_users_env')) {
            $this->forge->addKey(['environment'], false, false, 'connect_external_users_env');
            $this->forge->processIndexes('connect_external_users');
        }

        // Phase 1 shipped UNIQUE(tenant_id, external_user_id). That key is
        // strictly weaker than the environment-scoped key below, and keeping
        // it makes environment isolation impossible: the same external user
        // can never be mapped in both `test` and `production` within one
        // tenant, so the second mapping silently failed to insert while the
        // API still answered 201. The scoped key is created first, and only
        // then is the legacy key dropped, so there is never a window with no
        // uniqueness constraint at all.
        if ($db->tableExists('connect_user_mappings') && ! $this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_tenant_env')) {
            $this->forge->addUniqueKey(
                ['tenant_id', 'environment', 'external_user_id'],
                'uq_connect_mapping_tenant_env',
            );
            $this->forge->processIndexes('connect_user_mappings');
        }

        if ($db->tableExists('connect_user_mappings')
            && $this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_tenant')
            && $this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_tenant_env')) {
            try {
                $this->forge->dropKey('connect_user_mappings', 'uq_connect_mapping_tenant');
                $this->forge->processIndexes('connect_user_mappings');
            } catch (\Throwable $e) {
                log_message(
                    'error',
                    'Connect mapping migration: could not drop the legacy index '
                    . 'uq_connect_mapping_tenant. Environment isolation for the same '
                    . 'external_user_id will keep failing until it is removed: ' . $e->getMessage(),
                );
            }
        }

        if ($db->tableExists('connect_user_mappings') && ! $this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_hb_user_env')) {
            $duplicates = $this->duplicateHbUsers($db);
            if ($duplicates > 0) {
                log_message(
                    'critical',
                    'Connect mapping migration: found ' . $duplicates
                    . ' legacy row(s) mapping the same Humming Bird user twice within one '
                    . 'tenant/environment. The unique index uq_connect_mapping_hb_user_env was '
                    . 'NOT created; a plain index was used instead. Review and deduplicate '
                    . 'before enforcing the constraint.',
                );
                $this->forge->addKey('humming_bird_user_id', false, false, 'idx_connect_mapping_hb_user_env');
            } else {
                $this->forge->addUniqueKey(
                    ['tenant_id', 'environment', 'humming_bird_user_id'],
                    'uq_connect_mapping_hb_user_env',
                );
            }
            $this->forge->processIndexes('connect_user_mappings');
        }

        if ($db->tableExists('connect_user_mappings') && ! $this->indexExists($db, 'connect_user_mappings', 'connect_user_mappings_env')) {
            $this->forge->addKey(['environment'], false, false, 'connect_user_mappings_env');
            $this->forge->processIndexes('connect_user_mappings');
        }

        // ------------------------------------------------------------------
        // 4. Audit domain columns
        // ------------------------------------------------------------------
        $auditColumns = [
            'action'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'external_user_id'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'humming_bird_user_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
        ];
        if ($db->tableExists('connect_api_audit_logs')) {
            foreach ($auditColumns as $name => $def) {
                if (!$db->fieldExists($name, 'connect_api_audit_logs')) {
                    $this->forge->addColumn('connect_api_audit_logs', [$name => $def]);
                }
            }
        }
    }

    public function down()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_user_mappings')) {
            foreach (['uq_connect_mapping_hb_user_env', 'uq_connect_mapping_tenant_env', 'idx_connect_mapping_hb_user_env'] as $index) {
                if ($this->indexExists($db, 'connect_user_mappings', $index)) {
                    $this->forge->dropKey('connect_user_mappings', $index);
                }
            }
            if ($db->fieldExists('environment', 'connect_user_mappings')) {
                $this->forge->dropColumn('connect_user_mappings', ['environment']);
            }
        }

        if ($db->tableExists('connect_external_users')) {
            foreach (['uq_connect_ext_user_tenant_env', 'connect_external_users_env'] as $index) {
                if ($this->indexExists($db, 'connect_external_users', $index)) {
                    $this->forge->dropKey('connect_external_users', $index);
                }
            }
            if ($db->fieldExists('environment', 'connect_external_users')) {
                $this->forge->dropColumn('connect_external_users', ['environment']);
            }
        }

        if ($db->tableExists('connect_api_audit_logs')) {
            foreach (['action', 'external_user_id', 'humming_bird_user_id'] as $column) {
                if ($db->fieldExists($column, 'connect_api_audit_logs')) {
                    $this->forge->dropColumn('connect_api_audit_logs', [$column]);
                }
            }
        }
    }

    private function columnIsNumeric($db, string $table, string $column): bool
    {
        foreach ((array) $db->getFieldData($table) as $field) {
            if (($field->name ?? '') === $column) {
                $type = strtolower((string) ($field->type ?? ''));

                return strpos($type, 'int') !== false
                    || strpos($type, 'bigint') !== false;
            }
        }

        return false;
    }

    private function indexExists($db, string $table, string $indexName): bool
    {
        foreach ((array) $db->getIndexData($table) as $key => $index) {
            $candidate = is_object($index) ? ($index->name ?? null) : ($key ?? null);
            if ($candidate === $indexName) {
                return true;
            }
        }

        return false;
    }

    private function duplicateHbUsers($db): int
    {
        $prefixed = $db->DBPrefix . 'connect_user_mappings';
        $row = $db->query(
            'SELECT COUNT(*) AS c FROM ('
            . 'SELECT tenant_id, environment, humming_bird_user_id, COUNT(*) AS n '
            . 'FROM ' . $prefixed . ' '
            . 'GROUP BY tenant_id, environment, humming_bird_user_id HAVING n > 1'
            . ') d',
        )->getRow();

        return (int) ($row->c ?? 0);
    }
}
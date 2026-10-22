<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Drops the legacy Phase-1 UNIQUE(tenant_id, external_user_id) index on
 * connect_user_mappings.
 *
 * 2026-09-10-000001 created that key as a fallback for databases that did not
 * yet have the `environment` column, and kept it afterwards. It is strictly
 * weaker than the environment-scoped key
 * UNIQUE(tenant_id, environment, external_user_id) that the same migration
 * also creates, and while both exist the scoped key can never be honoured:
 * mapping the same external_user_id twice in one tenant (once for `test`, once
 * for `production`) always violated the legacy key.
 *
 * The failure was silent rather than loud. Under CI4's `testing` environment
 * Model::insert() returns false instead of throwing, so
 * ConnectUserMappingService::map() ignored the return value, committed an
 * empty transaction and answered `201 created` for a mapping that was never
 * written. Clients then read the mapping back and got 404.
 *
 * This migration is the forward fix for databases that already recorded
 * 2026-09-10-000001. The scoped key is (re)created first, so uniqueness is
 * never left unenforced, and the legacy key is only dropped afterwards.
 */
class DropLegacyConnectMappingTenantUniqueIndex extends Migration
{
    public function up()
    {
        $db = $this->db;

        if (! $db->tableExists('connect_user_mappings')) {
            return;
        }

        if (! $db->fieldExists('environment', 'connect_user_mappings')) {
            // Without the scoping column the legacy key is the only constraint
            // available, so it must stay.
            return;
        }

        if (! $this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_tenant_env')) {
            $this->forge->addUniqueKey(
                ['tenant_id', 'environment', 'external_user_id'],
                'uq_connect_mapping_tenant_env',
            );
            $this->forge->processIndexes('connect_user_mappings');
        }

        if (! $this->indexExists($db, 'connect_user_mappings', 'uq_connect_mapping_tenant')) {
            return;
        }

        try {
            $this->dropIndex($db, 'connect_user_mappings', 'uq_connect_mapping_tenant');

            log_message(
                'info',
                'Connect mapping migration: dropped the legacy index '
                . 'uq_connect_mapping_tenant so the same external_user_id can be '
                . 'mapped independently in the test and production environments.',
            );
        } catch (\Throwable $e) {
            log_message(
                'error',
                'Connect mapping migration: could not drop the legacy index '
                . 'uq_connect_mapping_tenant. Mapping one external_user_id in both '
                . 'the test and production environment will keep failing until it is '
                . 'removed: ' . $e->getMessage(),
            );
        }
    }

    public function down()
    {
        $db = $this->db;

        if (! $db->tableExists('connect_user_mappings')
            || ! $db->fieldExists('environment', 'connect_user_mappings')
        ) {
            return;
        }

        // Re-creating this key is what caused the bug, so it is deliberately
        // NOT restored. Rolling back leaves the scoped key in place.
    }

    /**
     * Drops a named index across drivers.
     *
     * `Forge::dropKey()` always prefixes the index name, which is correct for
     * MySQL (`db_uq_...`) but wrong for SQLite: SQLite index names live in the
     * database namespace and the forge created them WITHOUT the prefix, so
     * dropKey() fails with "no such index: db_uq_connect_mapping_tenant".
     * The drop is verified either way, so a driver that disagrees is reported
     * rather than silently skipped.
     */
    private function dropIndex($db, string $table, string $indexName): void
    {
        $prefixed = $db->DBPrefix . $table;

        if (strtolower((string) $db->DBDriver) === 'sqlite3') {
            foreach ([$indexName, $db->DBPrefix . $indexName] as $candidate) {
                $db->query('DROP INDEX IF EXISTS ' . $this->escapeIdentifier($candidate));
            }
        } else {
            $this->forge->dropKey($table, $indexName);
            $this->forge->processIndexes($table);
        }

        if ($this->indexExists($db, $table, $indexName)) {
            throw new \RuntimeException(sprintf(
                'index %s still present on %s after the drop was attempted',
                $indexName,
                $prefixed,
            ));
        }
    }

    private function escapeIdentifier(string $name): string
    {
        return $name[0] === '`' ? $name : '`' . $name . '`';
    }

    private function indexExists($db, string $table, string $indexName): bool
    {
        foreach ((array) $db->getIndexData($table) as $key => $index) {
            $candidate = is_object($index) ? ($index->name ?? null) : ($key ?? null);
            if ($candidate === $indexName || $candidate === $db->DBPrefix . $indexName) {
                return true;
            }
        }

        return false;
    }
}

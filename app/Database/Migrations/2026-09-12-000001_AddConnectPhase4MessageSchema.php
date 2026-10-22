<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Humming Bird Connect — Phase 4 message-inbound schema.
 *
 * Adapts the Phase 1 `connect_external_messages` table to carry a platform
 * string external message id and to be fully tenant + environment scoped:
 *
 *  1. `environment`        VARCHAR(20)  — test|production, backfilled from the
 *     owning connect_tenants row (mirrors Phase 3 connect_external_users).
 *  2. `external_message_id` VARCHAR(150) — the platform-supplied string id
 *     (e.g. "remark_msg_123"). Phase 1 had only `external_event_id` and
 *     `message_uuid`; the idempotency contract for POST /messages/send is keyed
 *     on UNIQUE(tenant_id, environment, external_message_id).
 *  3. `request_id`          VARCHAR(64)  — the authenticated request id that
 *     accepted this message (audit convenience).
 *  4. `external_user_id`    BIGINT → VARCHAR(150) — external user ids are
 *     platform strings ("remark_user_123"), so a BIGINT column would reject
 *     them. Converted to match connect_external_users/external_user_mappings.
 *  5. `message_id` stays as the Humming Bird `messages.m_id` (existing row
 *     already means humming_bird_message_id).
 *
 *  Indexes: UNIQUE(tenant_id, environment, external_message_id) enforces
 *  idempotency at the database; a lookup index on
 *  (tenant_id, environment, external_user_id) and one on
 *  (tenant_id, environment, message_id) support the send/read queries.
 *
 *  Every change is guarded (fieldExists / indexExists / type checks) so this is
 *  a safe no-op on the Phase-4-shaped schema that
 *  CreateHummingBirdConnectFoundation now emits, and it upgrades a legacy
 *  (already-applied) schema in place. Nothing is dropped or truncated.
 */
class AddConnectPhase4MessageSchema extends Migration
{
    public function up()
    {
        $db = Database::connect();

        if (!$db->tableExists('connect_external_messages')) {
            return;
        }

        $fields = $db->getFieldNames('connect_external_messages');

        // ------------------------------------------------------------------
        // 1. external_user_id → VARCHAR(150)
        // ------------------------------------------------------------------
        if (in_array('external_user_id', $fields, true) && $this->columnIsNumeric($db, 'external_user_id')) {
            $this->forge->modifyColumn('connect_external_messages', [
                'external_user_id' => ['name' => 'external_user_id', 'type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            ]);
        }

        // ------------------------------------------------------------------
        // 2. environment / external_message_id / request_id columns
        // ------------------------------------------------------------------
        $addedColumns = [
            'environment'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'external_message_id'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'request_id'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        ];
        foreach ($addedColumns as $name => $def) {
            if (!$db->fieldExists($name, 'connect_external_messages')) {
                $this->forge->addColumn('connect_external_messages', [$name => $def]);
            }
        }

        // Backfill environment from the owning tenant (mirrors Phase 3).
        $prefixed = $db->DBPrefix . 'connect_external_messages';
        if ($db->tableExists('connect_tenants')) {
            $db->query("UPDATE {$prefixed} "
                . 'SET environment = (SELECT environment FROM ' . $db->DBPrefix . 'connect_tenants t WHERE t.id = ' . $prefixed . '.tenant_id) '
                . 'WHERE environment IS NULL OR environment = \'\'');
        }

        // ------------------------------------------------------------------
        // 3. Idempotency + lookup indexes (guarded)
        // ------------------------------------------------------------------
        if (! $this->indexExists($db, 'uq_connect_msg_tenant_env_external')) {
            $this->forge->addUniqueKey(
                ['tenant_id', 'environment', 'external_message_id'],
                'uq_connect_msg_tenant_env_external',
            );
            $this->forge->processIndexes('connect_external_messages');
        }

        if (! $this->indexExists($db, 'idx_connect_msg_tenant_env_user')) {
            $this->forge->addKey(
                ['tenant_id', 'environment', 'external_user_id'],
                false,
                false,
                'idx_connect_msg_tenant_env_user',
            );
            $this->forge->processIndexes('connect_external_messages');
        }

        if (! $this->indexExists($db, 'idx_connect_msg_tenant_env_hbmsg')) {
            $this->forge->addKey(
                ['tenant_id', 'environment', 'message_id'],
                false,
                false,
                'idx_connect_msg_tenant_env_hbmsg',
            );
            $this->forge->processIndexes('connect_external_messages');
        }
    }

    public function down()
    {
        $db = Database::connect();

        if (!$db->tableExists('connect_external_messages')) {
            return;
        }

        foreach (['uq_connect_msg_tenant_env_external', 'idx_connect_msg_tenant_env_user', 'idx_connect_msg_tenant_env_hbmsg'] as $index) {
            if ($this->indexExists($db, $index)) {
                $this->forge->dropKey('connect_external_messages', $index);
            }
        }

        foreach (['environment', 'external_message_id', 'request_id'] as $column) {
            if ($db->fieldExists($column, 'connect_external_messages')) {
                $this->forge->dropColumn('connect_external_messages', [$column]);
            }
        }
    }

    private function columnIsNumeric($db, string $column): bool
    {
        foreach ((array) $db->getFieldData('connect_external_messages') as $field) {
            if (($field->name ?? '') === $column) {
                $type = strtolower((string) ($field->type ?? ''));

                return strpos($type, 'int') !== false
                    || strpos($type, 'bigint') !== false;
            }
        }

        return false;
    }

    private function indexExists($db, string $indexName): bool
    {
        foreach ((array) $db->getIndexData('connect_external_messages') as $key => $index) {
            $candidate = is_object($index) ? ($index->name ?? null) : ($key ?? null);
            if ($candidate === $indexName) {
                return true;
            }
        }

        return false;
    }
}
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Humming Bird Connect — Phase 7 message status ledger + webhook delivery.
 *
 * Phase 1 scaffolded `connect_webhooks` and `connect_webhook_deliveries` but
 * no webhook system was implemented. Phase 7 adds:
 *
 *   1. `connect_message_status` — a normalized, append-only ledger of every
 *      status transition a Connect message goes through
 *      (accepted → queued → processing → sent → delivered → read | failed).
 *      One row per transition; a given (tenant, environment, external_message_id,
 *      status) is unique so re-recording a transition is idempotent.
 *
 *   2. Additive columns on `connect_webhooks` (uuid, environment, description,
 *      last_success_at, last_failure_at) and
 *
 *   3. Additive columns on `connect_webhook_deliveries` (uuid, environment,
 *      payload, locked_at, locked_by, last_error_code) plus a unique
 *      (webhook_id, event_id) constraint so a delivery cannot be queued twice.
 *
 * Everything is guarded (tableExists / fieldExists / indexExists) and only ever
 * adds — never drops — so legacy schemas upgrade in place and Phase-7 schemas
 * stay a no-op. Reversible via down().
 */
class AddConnectPhase7StatusAndWebhooks extends Migration
{
    public function up()
    {
        $db = Database::connect();

        // ---------------------------------------------------------------
        // 1. connect_message_status — append-only status ledger
        // ---------------------------------------------------------------
        if (! $db->tableExists('connect_message_status')) {
            $this->forge->addField([
                'id'                       => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'uuid'                     => ['type' => 'VARCHAR', 'constraint' => 64],
                'tenant_id'                => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'environment'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'external_message_id'      => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'connect_message_id'       => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
                'humming_bird_message_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'status'                   => ['type' => 'VARCHAR', 'constraint' => 20],
                'status_reason'            => ['type' => 'TEXT', 'null' => true],
                'occurred_at'              => ['type' => 'DATETIME'],
                'created_at'               => ['type' => 'DATETIME'],
                'updated_at'               => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('environment');
            $this->forge->addKey('external_message_id');
            $this->forge->addKey('connect_message_id');
            $this->forge->addKey('status');
            $this->forge->addKey('occurred_at');
            $this->forge->addUniqueKey(
                ['tenant_id', 'environment', 'external_message_id', 'status'],
                'uq_connect_msg_status_tn_env_ext_status',
            );
            $this->forge->createTable('connect_message_status');
        }

        // ---------------------------------------------------------------
        // 2. connect_webhooks — additive columns
        // ---------------------------------------------------------------
        if (! $db->tableExists('connect_webhooks')) {
            return;
        }

        $fields = $db->getFieldNames('connect_webhooks');

        if (! in_array('uuid', $fields, true)) {
            $this->forge->addColumn('connect_webhooks', [
                'uuid' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('environment', $fields, true)) {
            $this->forge->addColumn('connect_webhooks', [
                'environment' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('description', $fields, true)) {
            $this->forge->addColumn('connect_webhooks', [
                'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('name', $fields, true)) {
            $this->forge->addColumn('connect_webhooks', [
                'name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('last_success_at', $fields, true)) {
            $this->forge->addColumn('connect_webhooks', [
                'last_success_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('last_failure_at', $fields, true)) {
            $this->forge->addColumn('connect_webhooks', [
                'last_failure_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
        }

        // ---------------------------------------------------------------
        // 3. connect_webhook_deliveries — additive columns + idempotency key
        // ---------------------------------------------------------------
        if (! $db->tableExists('connect_webhook_deliveries')) {
            return;
        }

        $dFields = $db->getFieldNames('connect_webhook_deliveries');

        if (! in_array('uuid', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'uuid' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('environment', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'environment' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('webhook_uuid', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'webhook_uuid' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('payload', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'payload' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('locked_at', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'locked_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('locked_by', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'locked_by' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            ]);
        }
        if (! in_array('last_error_code', $dFields, true)) {
            $this->forge->addColumn('connect_webhook_deliveries', [
                'last_error_code' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            ]);
        }

        // Idempotency key: one delivery per (webhook, event) — prevents a
        // duplicate enqueue from double-firing a consumer endpoint.
        if (! $this->indexExists($db, 'connect_webhook_deliveries', 'uq_connect_wb_webhook_event')) {
            $this->forge->addUniqueKey(['webhook_id', 'event_id'], 'uq_connect_wb_webhook_event');
            $this->forge->processIndexes('connect_webhook_deliveries');
        }
    }

    public function down()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_webhook_deliveries')) {
            $fields = $db->getFieldNames('connect_webhook_deliveries');

            foreach (['uuid', 'environment', 'webhook_uuid', 'payload', 'locked_at', 'locked_by', 'last_error_code'] as $col) {
                if (in_array($col, $fields, true)) {
                    try {
                        $this->forge->dropColumn('connect_webhook_deliveries', [$col]);
                    } catch (\Throwable $e) {
                        // Column may not exist on a partially-applied schema.
                    }
                }
            }

            if ($this->indexExists($db, 'connect_webhook_deliveries', 'uq_connect_wb_webhook_event')) {
                try {
                    $this->forge->dropKey('connect_webhook_deliveries', 'uq_connect_wb_webhook_event');
                } catch (\Throwable $e) {
                }
            }
        }

        if ($db->tableExists('connect_webhooks')) {
            $fields = $db->getFieldNames('connect_webhooks');

            foreach (['uuid', 'environment', 'description', 'name', 'last_success_at', 'last_failure_at'] as $col) {
                if (in_array($col, $fields, true)) {
                    try {
                        $this->forge->dropColumn('connect_webhooks', [$col]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        if ($db->tableExists('connect_message_status')) {
            $this->forge->dropTable('connect_message_status');
        }
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

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Humming Bird Connect — Phase 5 reliable-delivery outbox schema.
 *
 * Adds the durable, tenant-scoped outbox that decouples the external API from
 * the existing Humming Bird message system:
 *
 *   POST /api/v1/connect/messages/send
 *     → atomically insert connect_external_message + connect_message_outbox
 *     → COMMIT
 *     → HTTP 202 (queued)
 *
 *   Connect worker (app/Services/Connect/ConnectMessageProcessor)
 *     → claim a pending/retrying outbox row
 *     → create the existing Humming Bird message
 *     → update tracking → mark outbox completed
 *
 * The outbox is NOT a second message store — it is only a reliable processing
 * mechanism between the Connect API and the existing message system.
 *
 * Statuses: pending → processing → completed | retrying → failed.
 *
 * Every change is guarded (tableExists / fieldExists / indexExists) so this is
 * a safe no-op on a Phase-5-shaped schema and upgrades a legacy schema in
 * place. Nothing is dropped or truncated; the migration is reversible.
 */
class AddConnectPhase5Outbox extends Migration
{
    public function up()
    {
        $db = Database::connect();

        // ---------------------------------------------------------------
        // 1. connect_message_outbox table
        // ---------------------------------------------------------------
        if (! $db->tableExists('connect_message_outbox')) {
            $this->forge->addField([
                'id'                         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'uuid'                       => ['type' => 'VARCHAR', 'constraint' => 64],
                'tenant_id'                  => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'environment'                => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'connect_external_message_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'external_message_id'        => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'status'                     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
                'attempts'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'available_at'               => ['type' => 'DATETIME', 'null' => true],
                'locked_at'                  => ['type' => 'DATETIME', 'null' => true],
                'locked_by'                  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'processed_at'               => ['type' => 'DATETIME', 'null' => true],
                'last_error_code'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'last_error_message'         => ['type' => 'TEXT', 'null' => true],
                'created_at'                 => ['type' => 'DATETIME'],
                'updated_at'                 => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('environment');
            $this->forge->addKey('status');
            $this->forge->createTable('connect_message_outbox');
        }

        // ---------------------------------------------------------------
        // 2. Worker lookup indexes (guarded — match actual worker query
        //    patterns: WHERE status IN (pending,retrying) AND available_at <=
        //    NOW() ORDER BY available_at ASC LIMIT n).
        // ---------------------------------------------------------------
        if (! $this->indexExists($db, 'idx_connect_outbox_status_available')) {
            $this->forge->addKey(
                ['status', 'available_at'],
                false,
                false,
                'idx_connect_outbox_status_available',
            );
            $this->forge->processIndexes('connect_message_outbox');
        }

        if (! $this->indexExists($db, 'idx_connect_outbox_tenant_status')) {
            $this->forge->addKey(
                ['tenant_id', 'status'],
                false,
                false,
                'idx_connect_outbox_tenant_status',
            );
            $this->forge->processIndexes('connect_message_outbox');
        }

        if (! $this->indexExists($db, 'idx_connect_outbox_connect_msg_id')) {
            $this->forge->addKey(
                ['connect_external_message_id'],
                false,
                false,
                'idx_connect_outbox_connect_msg_id',
            );
            $this->forge->processIndexes('connect_message_outbox');
        }

        // ---------------------------------------------------------------
        // 3. External message table already carries status/error_code/
        //    error_message (foundation) — Phase 5 also needs the `uuid`
        //    correlation field recommended by the spec. Add it guarded so the
        //    worker has a stable internal operation identity and can detect a
        //    crash-after-message-create without scanning by message text.
        // ---------------------------------------------------------------
        if ($db->tableExists('connect_external_messages')
            && ! $db->fieldExists('uuid', 'connect_external_messages')) {
            $this->forge->addColumn('connect_external_messages', [
                'uuid' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            ]);
        }
    }

    public function down()
    {
        $db = Database::connect();

        if ($db->tableExists('connect_external_messages')
            && $db->fieldExists('uuid', 'connect_external_messages')) {
            $this->forge->dropColumn('connect_external_messages', ['uuid']);
        }

        if ($db->tableExists('connect_message_outbox')) {
            $this->forge->dropTable('connect_message_outbox');
        }
    }

    private function indexExists($db, string $indexName): bool
    {
        if (! $db->tableExists('connect_message_outbox')) {
            return false;
        }

        foreach ((array) $db->getIndexData('connect_message_outbox') as $key => $index) {
            $candidate = is_object($index) ? ($index->name ?? null) : ($key ?? null);
            if ($candidate === $indexName) {
                return true;
            }
        }

        return false;
    }
}

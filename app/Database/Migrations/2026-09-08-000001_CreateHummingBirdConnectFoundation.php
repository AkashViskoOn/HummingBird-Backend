<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Humming Bird Connect — Phase 1 database foundation.
 *
 * Creates all `connect_*` B2B tables. Follows the repo convention: every
 * create is guarded by `tableExists()` so repeated runs (hand-applied on RDS
 * or via `php spark migrate --all`) are safe, and `down()` is guarded too.
 *
 * Foreign-key decision: references to existing Humming Bird tables
 * (users.user_id INT, rooms.r_id VARCHAR, messages.m_id INT) and between
 * Connect tables are implemented as INDEXED columns only. The production
 * schema was created by hand and does not use DB-level foreign keys; mixing
 * column types (INT vs VARCHAR ids) means enforced FKs would break
 * compatibility. Relationships are documented in the column comments and
 * enforced in application code (tenant scoping is mandatory).
 *
 * Existing Humming Bird tables are NOT altered or dropped.
 */
class CreateHummingBirdConnectFoundation extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        // ------------------------------------------------------------------
        // 1. connect_tenants
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_tenants')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'uuid'          => ['type' => 'VARCHAR', 'constraint' => 64],
                'name'          => ['type' => 'VARCHAR', 'constraint' => 150],
                'slug'          => ['type' => 'VARCHAR', 'constraint' => 150],
                'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'environment'   => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'production'],
                'contact_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'contact_name'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'webhook_url'   => ['type' => 'TEXT', 'null' => true],
                'settings'      => ['type' => 'JSON', 'null' => true],
                'created_at'    => ['type' => 'DATETIME'],
                'updated_at'    => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('uuid');
            $this->forge->addUniqueKey('slug');
            $this->forge->addKey('status');
            $this->forge->addKey('environment');
            $this->forge->createTable('connect_tenants');
        }

        // ------------------------------------------------------------------
        // 2. connect_api_credentials
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_api_credentials')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'     => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'credential_id' => ['type' => 'VARCHAR', 'constraint' => 64],
                'key_prefix'    => ['type' => 'VARCHAR', 'constraint' => 32],
                'secret_hash'   => ['type' => 'VARCHAR', 'constraint' => 255],
                'name'          => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'environment'   => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'production'],
                'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'last_used_at'  => ['type' => 'DATETIME', 'null' => true],
                'expires_at'    => ['type' => 'DATETIME', 'null' => true],
                'created_at'    => ['type' => 'DATETIME'],
                'updated_at'    => ['type' => 'DATETIME'],
                'revoked_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('credential_id');
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('key_prefix');
            $this->forge->addKey('status');
            $this->forge->createTable('connect_api_credentials');
        }

        // ------------------------------------------------------------------
        // 3. connect_service_identities
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_service_identities')) {
            $this->forge->addField([
                'id'                 => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'name'               => ['type' => 'VARCHAR', 'constraint' => 150],
                'display_name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'avatar'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'description'        => ['type' => 'TEXT', 'null' => true],
                'verification_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
                'status'             => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'metadata'           => ['type' => 'JSON', 'null' => true],
                'created_at'         => ['type' => 'DATETIME'],
                'updated_at'         => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('status');
            $this->forge->createTable('connect_service_identities');
        }

        // ------------------------------------------------------------------
        // 4. connect_external_users
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_external_users')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'        => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'environment'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'external_user_id' => ['type' => 'VARCHAR', 'constraint' => 150],
                'external_username'=> ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'external_email'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'external_phone'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'metadata'         => ['type' => 'JSON', 'null' => true],
                'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'last_seen_at'     => ['type' => 'DATETIME', 'null' => true],
                'created_at'       => ['type' => 'DATETIME'],
                'updated_at'       => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('environment');
            $this->forge->addKey('external_user_id');
            $this->forge->addKey('status');
            $this->forge->addUniqueKey(['tenant_id', 'external_user_id'], 'uq_connect_ext_user_tenant');
            $this->forge->addUniqueKey(['tenant_id', 'environment', 'external_user_id'], 'uq_connect_ext_user_tenant_env');
            $this->forge->createTable('connect_external_users');
        }

        // ------------------------------------------------------------------
        // 5. connect_user_mappings
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_user_mappings')) {
            $this->forge->addField([
                'id'                  => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'environment'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'external_user_id'    => ['type' => 'VARCHAR', 'constraint' => 150],
                'humming_bird_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'mapping_status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'mapped_at'           => ['type' => 'DATETIME', 'null' => true],
                'unmapped_at'         => ['type' => 'DATETIME', 'null' => true],
                'created_at'          => ['type' => 'DATETIME'],
                'updated_at'          => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('environment');
            $this->forge->addKey('external_user_id');
            $this->forge->addKey('humming_bird_user_id');
            $this->forge->addKey('mapping_status');
            // Only the environment-scoped key below is created. An earlier
            // revision also added UNIQUE(tenant_id, external_user_id) here,
            // which contradicts it: while both exist the scoped key can never
            // be honoured, so mapping one external_user_id in both the test
            // and production environment always failed. Databases that already
            // recorded that revision are repaired by
            // 2026-09-28-000001_DropLegacyConnectMappingTenantUniqueIndex.
            $this->forge->addUniqueKey(['tenant_id', 'environment', 'external_user_id'], 'uq_connect_mapping_tenant_env');
            $this->forge->addUniqueKey(['tenant_id', 'environment', 'humming_bird_user_id'], 'uq_connect_mapping_hb_user_env');
            $this->forge->createTable('connect_user_mappings');
        }

        // ------------------------------------------------------------------
        // 6. connect_external_messages
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_external_messages')) {
            $this->forge->addField([
                'id'                  => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'service_identity_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
                'external_user_id'    => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
                'humming_bird_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'message_uuid'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'external_event_id'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'message_type'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'title'               => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'body'                => ['type' => 'TEXT', 'null' => true],
                'payload'             => ['type' => 'JSON', 'null' => true],
                'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'accepted'],
                'room_id'             => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'message_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'error_code'          => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'error_message'       => ['type' => 'TEXT', 'null' => true],
                'created_at'          => ['type' => 'DATETIME'],
                'updated_at'          => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('service_identity_id');
            $this->forge->addKey('external_user_id');
            $this->forge->addKey('humming_bird_user_id');
            $this->forge->addKey('status');
            $this->forge->addKey('message_uuid');
            $this->forge->addKey(['tenant_id', 'external_event_id'], false, false, 'idx_connect_msg_tenant_event');
            $this->forge->addKey('room_id');
            $this->forge->addKey('message_id');
            $this->forge->createTable('connect_external_messages');
        }

        // ------------------------------------------------------------------
        // 7. connect_message_deliveries
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_message_deliveries')) {
            $this->forge->addField([
                'id'                  => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'external_message_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'tenant_id'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'humming_bird_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'],
                'attempts'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'last_attempt_at'     => ['type' => 'DATETIME', 'null' => true],
                'delivered_at'        => ['type' => 'DATETIME', 'null' => true],
                'read_at'             => ['type' => 'DATETIME', 'null' => true],
                'failed_at'           => ['type' => 'DATETIME', 'null' => true],
                'failure_reason'      => ['type' => 'TEXT', 'null' => true],
                'created_at'          => ['type' => 'DATETIME'],
                'updated_at'          => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('external_message_id');
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('humming_bird_user_id');
            $this->forge->addKey('status');
            $this->forge->createTable('connect_message_deliveries');
        }

        // ------------------------------------------------------------------
        // 8. connect_idempotency_keys
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_idempotency_keys')) {
            $this->forge->addField([
                'id'              => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'       => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 150],
                'request_hash'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'resource_type'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'resource_id'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'response_code'   => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
                'response_body'   => ['type' => 'TEXT', 'null' => true],
                'expires_at'      => ['type' => 'DATETIME', 'null' => true],
                'created_at'      => ['type' => 'DATETIME'],
                'updated_at'      => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('idempotency_key');
            $this->forge->addUniqueKey(['tenant_id', 'idempotency_key'], 'uq_connect_idempotency_tenant');
            $this->forge->createTable('connect_idempotency_keys');
        }

        // ------------------------------------------------------------------
        // 9. connect_api_audit_logs
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_api_audit_logs')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'     => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'credential_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
                'request_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'method'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
                'endpoint'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'ip_address'    => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'user_agent'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'request_hash'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'response_code' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
                'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'error_code'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'action'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'external_user_id'     => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'humming_bird_user_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'created_at'    => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('credential_id');
            $this->forge->addKey('request_id');
            $this->forge->addKey('created_at');
            $this->forge->addKey('response_code');
            $this->forge->addKey('action');
            $this->forge->createTable('connect_api_audit_logs');
        }

        // ------------------------------------------------------------------
        // 10. connect_webhooks
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_webhooks')) {
            $this->forge->addField([
                'id'          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'url'         => ['type' => 'TEXT'],
                'secret_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'events'      => ['type' => 'JSON', 'null' => true],
                'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'created_at'  => ['type' => 'DATETIME'],
                'updated_at'  => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('status');
            $this->forge->createTable('connect_webhooks');
        }

        // ------------------------------------------------------------------
        // 11. connect_webhook_deliveries
        // ------------------------------------------------------------------
        if (!$db->tableExists('connect_webhook_deliveries')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'webhook_id'       => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'tenant_id'        => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'event_id'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'event_type'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'payload_hash'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
                'attempt'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'http_status'      => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
                'response_time_ms' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'next_retry_at'    => ['type' => 'DATETIME', 'null' => true],
                'delivered_at'     => ['type' => 'DATETIME', 'null' => true],
                'last_error'       => ['type' => 'TEXT', 'null' => true],
                'created_at'       => ['type' => 'DATETIME'],
                'updated_at'       => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('webhook_id');
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('event_id');
            $this->forge->addKey('status');
            $this->forge->addKey('next_retry_at');
            $this->forge->addKey(['status', 'next_retry_at'], false, false, 'idx_connect_wb_retry');
            $this->forge->createTable('connect_webhook_deliveries');
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();

        $tables = [
            'connect_webhook_deliveries',
            'connect_webhooks',
            'connect_api_audit_logs',
            'connect_idempotency_keys',
            'connect_message_deliveries',
            'connect_external_messages',
            'connect_user_mappings',
            'connect_external_users',
            'connect_service_identities',
            'connect_api_credentials',
            'connect_tenants',
        ];

        // Drop in reverse dependency order. Only Connect tables are dropped —
        // no existing Humming Bird table is ever touched.
        foreach ($tables as $table) {
            if ($db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStatusStoryEnhancements extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        // Story metadata + privacy columns on status_updates (guarded - schema is externally managed)
        $existingColumns = [];
        try {
            $existingColumns = $db->getFieldNames('status_updates');
        } catch (\Throwable $e) {
            $existingColumns = [];
        }

        $columns = [
            'su_privacy' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'everyone'],
            'su_bg_color' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'su_font_style' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'su_text_color' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'su_caption' => ['type' => 'TEXT', 'null' => true],
            'su_location' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'su_link' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'su_mentions' => ['type' => 'TEXT', 'null' => true],
        ];

        foreach ($columns as $field => $spec) {
            if (in_array($field, $existingColumns, true)) {
                continue;
            }
            try {
                $this->forge->addColumn('status_updates', [$field => $spec]);
            } catch (\Throwable $e) {
                log_message('error', "Migration: failed to add $field: " . $e->getMessage());
            }
        }

        // status_reports
        // Guarded on tableExists(), not just createTable(..., true): Forge keeps
        // the foreign-key store populated after createTable(), so a re-run
        // against an existing table still runs _processForeignKeys() against an
        // empty field list and aborts with "Field srp_status_id not found".
        // Skipping the whole block when the table exists keeps re-runs safe.
        if (!$db->tableExists('status_reports')) {
            $this->forge->addField([
                'srp_id' => [
                    'type' => 'INT', 'constraint' => 11,
                    'unsigned' => true, 'auto_increment' => true,
                ],
                'srp_status_id' => [
                    'type' => 'INT', 'constraint' => 11,
                    'unsigned' => true,
                ],
                'srp_reporter_id' => [
                    'type' => 'INT', 'constraint' => 11,
                    'unsigned' => true,
                ],
                'srp_reason' => [
                    'type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                ],
                'srp_created_at' => [
                    'type' => 'DATETIME', 'null' => true,
                ],
            ]);
            $this->forge->addKey('srp_id', true);
            $this->forge->addKey('srp_status_id');
            $this->forge->addKey('srp_reporter_id');
            $this->forge->createTable('status_reports', true);
            try {
                $this->forge->addForeignKey('srp_status_id', 'status_updates', 'su_id', 'CASCADE', 'CASCADE');
            } catch (\Throwable $e) {
                log_message('error', 'Migration: status_reports FK skipped: ' . $e->getMessage());
            }
        }

        // user_status_settings - persistent per-user status privacy
        if (!$db->tableExists('user_status_settings')) {
            $this->forge->addField([
                'us_id' => [
                    'type' => 'INT', 'constraint' => 11,
                    'unsigned' => true, 'auto_increment' => true,
                ],
                'us_user_id' => [
                    'type' => 'INT', 'constraint' => 11,
                    'unsigned' => true,
                ],
                'us_privacy' => [
                    'type' => 'VARCHAR', 'constraint' => 20, 'default' => 'everyone',
                ],
                'us_except_ids' => [
                    'type' => 'TEXT', 'null' => true,
                ],
                'us_share_ids' => [
                    'type' => 'TEXT', 'null' => true,
                ],
                'us_updated_at' => [
                    'type' => 'DATETIME', 'null' => true,
                ],
            ]);
            $this->forge->addKey('us_id', true);
            $this->forge->addKey('us_user_id');
            $this->forge->createTable('user_status_settings', true);
            try {
                $this->forge->addForeignKey('us_user_id', 'users', 'user_id', 'CASCADE', 'CASCADE');
            } catch (\Throwable $e) {
                log_message('error', 'Migration: user_status_settings FK skipped: ' . $e->getMessage());
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('status_reports', true);
        $this->forge->dropTable('user_status_settings', true);
        try {
            $this->forge->dropColumn('status_updates', array_keys([
                'su_privacy', 'su_bg_color', 'su_font_style', 'su_text_color',
                'su_caption', 'su_location', 'su_link', 'su_mentions',
            ]));
        } catch (\Throwable $e) {
            log_message('error', 'Migration down: ' . $e->getMessage());
        }
    }
}

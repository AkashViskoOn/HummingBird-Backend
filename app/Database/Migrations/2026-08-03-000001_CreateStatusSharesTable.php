<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStatusSharesTable extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        $existingTables = [];
        try {
            $existingTables = $db->listTables();
        } catch (\Throwable $e) {
            $existingTables = [];
        }
        if (in_array('status_shares', $existingTables, true)) {
            return;
        }

        $this->forge->addField([
            'ssh_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true, 'auto_increment' => true,
            ],
            'ssh_status_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'ssh_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'ssh_shared_at' => [
                'type' => 'DATETIME', 'null' => true,
            ],
        ]);
        $this->forge->addKey('ssh_id', true);
        $this->forge->addKey('ssh_status_id');
        $this->forge->addKey('ssh_user_id');
        $this->forge->addKey(['ssh_status_id', 'ssh_user_id']);
        $this->forge->createTable('status_shares', true);
        try {
            $this->forge->addForeignKey('ssh_status_id', 'status_updates', 'su_id', 'CASCADE', 'CASCADE');
        } catch (\Throwable $e) {
            log_message('error', 'Migration: status_shares FK skipped: ' . $e->getMessage());
        }
    }

    public function down()
    {
        $this->forge->dropTable('status_shares', true);
    }
}

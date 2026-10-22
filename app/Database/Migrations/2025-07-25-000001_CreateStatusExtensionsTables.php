<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStatusExtensionsTables extends Migration
{
    public function up()
    {
        // status_reactions
        $this->forge->addField([
            'sr_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true, 'auto_increment' => true,
            ],
            'sr_status_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'sr_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'sr_reaction' => [
                'type' => 'VARCHAR', 'constraint' => 50,
            ],
            'sr_created_at' => [
                'type' => 'DATETIME', 'null' => true,
            ],
        ]);
        $this->forge->addKey('sr_id', true);
        $this->forge->addKey(['sr_status_id', 'sr_user_id']);
        $this->forge->createTable('status_reactions', true);

        // status_replies
        $this->forge->addField([
            'srp_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true, 'auto_increment' => true,
            ],
            'srp_status_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'srp_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'srp_message' => [
                'type' => 'TEXT',
            ],
            'srp_created_at' => [
                'type' => 'DATETIME', 'null' => true,
            ],
        ]);
        $this->forge->addKey('srp_id', true);
        $this->forge->addKey('srp_status_id');
        $this->forge->createTable('status_replies', true);

        // status_mentions
        $this->forge->addField([
            'smn_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true, 'auto_increment' => true,
            ],
            'smn_status_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'smn_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'smn_mentioned_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'smn_created_at' => [
                'type' => 'DATETIME', 'null' => true,
            ],
        ]);
        $this->forge->addKey('smn_id', true);
        $this->forge->addKey('smn_mentioned_user_id');
        $this->forge->createTable('status_mentions', true);

        // status_mutes
        $this->forge->addField([
            'smu_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true, 'auto_increment' => true,
            ],
            'smu_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'smu_muted_user_id' => [
                'type' => 'INT', 'constraint' => 11,
                'unsigned' => true,
            ],
            'smu_created_at' => [
                'type' => 'DATETIME', 'null' => true,
            ],
        ]);
        $this->forge->addKey('smu_id', true);
        $this->forge->addKey(['smu_user_id', 'smu_muted_user_id']);
        $this->forge->createTable('status_mutes', true);
    }

    public function down()
    {
        $this->forge->dropTable('status_reactions', true);
        $this->forge->dropTable('status_replies', true);
        $this->forge->dropTable('status_mentions', true);
        $this->forge->dropTable('status_mutes', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateScheduleDraftsTable extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        if ($db->tableExists('schedule_drafts')) {
            return;
        }

        $this->forge->addField([
            'draft_id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'message_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => '0',
            ],
            'room_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'scheduled_at' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => '0',
            ],
            'interval' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => '0',
            ],
            'count' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => '0',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'custom',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('draft_id', true);
        $this->forge->addKey('user_id');
        $this->forge->createTable('schedule_drafts');
    }

    public function down()
    {
        $db = \Config\Database::connect();

        if ($db->tableExists('schedule_drafts')) {
            $this->forge->dropTable('schedule_drafts');
        }
    }
}
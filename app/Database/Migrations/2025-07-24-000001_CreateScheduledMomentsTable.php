<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateScheduledMomentsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'sm_id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'sm_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'sm_story_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'moment',
            ],
            'sm_image' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sm_video' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sm_thumbnail' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sm_caption' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sm_visibility' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'everyone',
            ],
            'sm_publish_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'now',
            ],
            'sm_scheduled_datetime' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'sm_published_datetime' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'sm_expiry_hours' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 24,
            ],
            'sm_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'pending',
            ],
            'sm_draft' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'sm_view_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'sm_reply_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'sm_share_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'sm_created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'sm_updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'sm_deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('sm_id', true);
        $this->forge->addKey('sm_user_id');
        $this->forge->addKey('sm_status');
        $this->forge->addKey('sm_scheduled_datetime');
        $this->forge->createTable('scheduled_moments');
    }

    public function down()
    {
        $this->forge->dropTable('scheduled_moments');
    }
}

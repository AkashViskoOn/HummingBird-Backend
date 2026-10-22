<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPendingRoomFields extends Migration
{
    public function up()
    {
        $this->forge->addColumn('rooms', [
            'r_is_pending' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'after' => 'r_status',
            ],
            'r_pending_phone' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
                'default' => null,
                'after' => 'r_is_pending',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('rooms', 'r_is_pending');
        $this->forge->dropColumn('rooms', 'r_pending_phone');
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMessageMentionsColumn extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('messages')) {
            return;
        }
        $currentColumns = $db->getFieldNames('messages');

        if (!in_array('m_mentions', $currentColumns, true)) {
            $this->forge->addColumn('messages', [
                'm_mentions' => ['type' => 'TEXT', 'null' => true],
            ]);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('messages')) {
            return;
        }
        $currentColumns = $db->getFieldNames('messages');

        if (in_array('m_mentions', $currentColumns, true)) {
            $this->forge->dropColumn('messages', 'm_mentions');
        }
    }
}
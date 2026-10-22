<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMessageFeatureColumns extends Migration
{
    private array $newColumns = [
        'm_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => '', 'null' => true],
        'm_reactions' => ['type' => 'TEXT', 'null' => true],
        'm_pinned' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => true],
        'm_starred' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => true],
        'm_is_edited' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => true],
        'm_forwarded' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => true],
        'm_forwarded_from' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        'm_forwarded_from_msg_id' => ['type' => 'BIGINT', 'null' => true],
        'm_pinned_at' => ['type' => 'BIGINT', 'null' => true],
    ];

    public function up()
    {
        $db = \Config\Database::connect();
        // The base `messages` table is created outside the migration set (see
        // rds_full_deploy.sql / DB_FIXES.sql), so it is absent on a fresh local
        // SQLite tests database. getFieldNames() then returns [] and every
        // addColumn below aborts the whole runner with "no such table".
        if (!$db->tableExists('messages')) {
            return;
        }
        $currentColumns = $db->getFieldNames('messages');

        foreach ($this->newColumns as $column => $definition) {
            if (in_array($column, $currentColumns, true)) {
                continue;
            }
            $this->forge->addColumn('messages', [$column => $definition]);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('messages')) {
            return;
        }
        $currentColumns = $db->getFieldNames('messages');

        foreach (array_keys($this->newColumns) as $column) {
            if (in_array($column, $currentColumns, true)) {
                $this->forge->dropColumn('messages', $column);
            }
        }
    }
}
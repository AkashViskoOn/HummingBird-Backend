<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Desktop device linking.
 *
 * Two tables, both new — nothing existing is modified, renamed or dropped.
 *
 *  - user_devices               a linked device belonging to a user
 *  - desktop_pairing_sessions   a short-lived QR pairing attempt
 *
 * There is no session table anywhere in the schema, so `user_devices` is
 * deliberately the only device identity: the desktop JWT carries `device_id`
 * as an extra claim, and the socket server reads only `user_id` from it, so
 * no second session store is introduced.
 */
class CreateDesktopDeviceLinkingTables extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('user_devices')) {
            $this->forge->addField([
                'device_id' => [
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
                // 'desktop' | 'mobile' | 'tablet' | 'unknown'
                'platform' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => false,
                    'default'    => 'unknown',
                ],
                'device_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 120,
                    'null'       => true,
                ],
                'browser' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 120,
                    'null'       => true,
                ],
                'app_version' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'null'       => true,
                ],
                'ip_address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                // 'active' | 'revoked'
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => false,
                    'default'    => 'active',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'last_seen' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'revoked_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('device_id', true);
            $this->forge->addKey('user_id');
            $this->forge->addKey(['user_id', 'status']);
            $this->forge->createTable('user_devices');
        }

        if ($db->tableExists('desktop_pairing_sessions')) {
            return;
        }

        $this->forge->addField([
            'pairing_id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            // NULL until a phone approves it. An unapproved pairing belongs to
            // nobody, which is what stops an anonymous caller from claiming a
            // session it did not create.
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            // Public, non-secret handle that goes in the QR code. 32 hex chars.
            'pairing_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            // Public proof that the desktop really requested this session.
            'challenge' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'device_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'platform' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'browser' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'app_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            // pending | scanned | approved | rejected | expired | consumed | cancelled
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'pending',
            ],
            // Bound to device_id once a phone approves. The desktop redeems
            // exactly this device via POST /user/desktop-pairing/claim.
            'device_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            // One-time redeemable secret, stored hashed. This is the value the
            // desktop generates and puts in the QR, so the phone never has to
            // hand anything back to the machine that displayed the code.
            'claim_secret_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'approved_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'consumed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Unique value written by whichever claim wins the race. A client
            // cannot infer "did I win?" from the UPDATE's return value, because
            // some drivers return bool rather than an affected-row count, so the
            // winner is confirmed by reading back its own nonce.
            'claim_nonce' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
        ]);
        $this->forge->addKey('pairing_id', true);
        $this->forge->addKey('pairing_ref', true);
        $this->forge->addKey('user_id');
        $this->forge->addKey('device_id');
        $this->forge->addKey('status');
        $this->forge->createTable('desktop_pairing_sessions');
    }

    public function down()
    {
        $db = \Config\Database::connect();

        if ($db->tableExists('desktop_pairing_sessions')) {
            $this->forge->dropTable('desktop_pairing_sessions', true);
        }

        if ($db->tableExists('user_devices')) {
            $this->forge->dropTable('user_devices', true);
        }
    }
}

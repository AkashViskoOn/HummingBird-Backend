<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Humming Bird Connect — Phase 2 schema additions.
 *
 * The Phase 1 migration created connect_api_credentials without request-side
 * key material or scopes. Phase 2 adds the two minimal columns required for
 * secure API authentication:
 *
 *   - api_key  VARCHAR(64) UNIQUE — the key sent as X-HummingBird-Key
 *     (e.g. `hb_live_<random>`). Lookup is done ONLY by this indexed value.
 *   - scopes   JSON — allowed OAuth-style capability list for the credential.
 *
 * Guarded so it is safe to apply by hand on RDS and via `php spark migrate`.
 */
class AddConnectApiKeyAndScopes extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        if (!$db->tableExists('connect_api_credentials')) {
            return;
        }

        $fields = $db->getFieldNames('connect_api_credentials');

        if (! in_array('api_key', $fields, true)) {
            $this->forge->addColumn('connect_api_credentials', [
                'api_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'credential_id'],
            ]);
            // Add the unique index through the Forge so it respects the
            // connection prefix (e.g. db_ under the 'tests' group).
            $this->forge->addUniqueKey('api_key', 'uq_connect_api_key');
            $this->forge->processIndexes('connect_api_credentials');
        }

        if (! in_array('scopes', $fields, true)) {
            $this->forge->addColumn('connect_api_credentials', [
                'scopes' => ['type' => 'JSON', 'null' => true, 'after' => 'status'],
            ]);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();

        if (!$db->tableExists('connect_api_credentials')) {
            return;
        }

        $fields = $db->getFieldNames('connect_api_credentials');

        if (in_array('api_key', $fields, true)) {
            try {
                $this->forge->dropKey('connect_api_credentials', 'uq_connect_api_key');
            } catch (\Throwable $e) {
                // Index may not exist on a partially-applied schema — ignore.
            }
            $this->forge->dropColumn('connect_api_credentials', 'api_key');
        }

        if (in_array('scopes', $fields, true)) {
            $this->forge->dropColumn('connect_api_credentials', 'scopes');
        }
    }
}
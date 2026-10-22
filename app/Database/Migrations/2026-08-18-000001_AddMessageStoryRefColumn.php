<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds m_story_ref (TEXT, JSON) to messages.
 *
 * Holds the story-reference payload for status replies/reactions/forwards
 * delivered as chat messages: {story_id, story_owner_id, reply_type,
 * message, reaction, media_url, media_type, content, ...}. Idempotent —
 * skips when the column already exists.
 */
class AddMessageStoryRefColumn extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('messages')) {
            return;
        }
        if (!in_array('m_story_ref', $db->getFieldNames('messages'), true)) {
            $this->forge->addColumn('messages', [
                'm_story_ref' => ['type' => 'TEXT', 'null' => true],
            ]);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('messages')) {
            return;
        }
        if (in_array('m_story_ref', $db->getFieldNames('messages'), true)) {
            $this->forge->dropColumn('messages', 'm_story_ref');
        }
    }
}
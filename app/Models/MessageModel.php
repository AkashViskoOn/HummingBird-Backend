<?php

namespace App\Models;

use CodeIgniter\Model;

class MessageModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'messages';
    protected $primaryKey       = 'm_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ["m_unique_id","m_room_id","m_sender_id","m_receiver_id",
    "m_message","m_message_type", "m_read_status", 'm_media', 'm_is_scheduled', 'm_scheduled_at',
    'm_interval', 'm_count', 'm_users_who_deleted', 'm_replied_on', 'm_seen_by', 'm_epoch_time',
    'm_reactions', 'm_pinned', 'm_starred', 'm_is_edited', 'm_forwarded', 'm_forwarded_from',
    'm_forwarded_from_msg_id', 'm_pinned_at', 'm_status', 'm_mentions', 'm_story_ref',
    'm_group_id', 'm_is_deleted', 'm_deleted_at'];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];
}

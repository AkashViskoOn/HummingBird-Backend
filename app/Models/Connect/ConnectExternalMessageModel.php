<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — B2B event/message request before it enters the
 * existing Humming Bird messaging pipeline.
 *
 * Keeps its own mapping (message_id -> messages.m_id) so Phase 1 does not
 * need to modify the existing messages/rooms/users tables.
 */
class ConnectExternalMessageModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_external_messages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'environment',
        'service_identity_id',
        'external_user_id',
        'humming_bird_user_id',
        'uuid',
        'message_uuid',
        'external_event_id',
        'external_message_id',
        'message_type',
        'title',
        'body',
        'payload',
        'status',
        'room_id',
        'message_id',
        'request_id',
        'error_code',
        'error_message',
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

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
<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — durable outbox of accepted external-message
 * operations awaiting background processing (Phase 5).
 *
 * This is NOT a second message store: it only records which Connect
 * operations the API durably accepted and that the Connect worker must still
 * process into the existing Humming Bird message system.
 *
 * Statuses (Config\Connect::OUTBOX_STATUS_*):
 *   pending    — accepted, not yet claimed by a worker
 *   processing — a worker currently owns the job
 *   completed  — existing Humming Bird message created/dispatched
 *   retrying   — temporarily failed, will be retried
 *   failed     — permanent failure after the retry policy
 */
class ConnectMessageOutboxModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_message_outbox';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'uuid',
        'tenant_id',
        'environment',
        'connect_external_message_id',
        'external_message_id',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'locked_by',
        'processed_at',
        'last_error_code',
        'last_error_message',
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

<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — append-only message status ledger (Phase 6).
 *
 * One row per status transition a Connect message goes through. A given
 * (tenant_id, environment, external_message_id, status) is unique, so
 * re-recording an already-seen transition is idempotent — this lets the worker
 * and the read-receipt hook re-fire safely.
 */
class ConnectMessageStatusModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_message_status';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'uuid',
        'tenant_id',
        'environment',
        'external_message_id',
        'connect_message_id',
        'humming_bird_message_id',
        'status',
        'status_reason',
        'occurred_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

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

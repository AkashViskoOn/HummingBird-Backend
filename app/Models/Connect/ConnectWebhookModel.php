<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — outgoing webhook registration. External platforms
 * receive message lifecycle events (message.sent / delivered / read / failed).
 */
class ConnectWebhookModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_webhooks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'uuid',
        'environment',
        'url',
        'secret_hash',
        'events',
        'status',
        'description',
        'name',
        'last_success_at',
        'last_failure_at',
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
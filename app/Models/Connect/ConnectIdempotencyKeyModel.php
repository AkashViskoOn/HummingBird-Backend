<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — idempotency keys to prevent duplicate external API
 * requests. One request per (tenant_id, idempotency_key). A retried request
 * must never create a second Humming Bird message.
 */
class ConnectIdempotencyKeyModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_idempotency_keys';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'idempotency_key',
        'request_hash',
        'resource_type',
        'resource_id',
        'status',
        'response_code',
        'response_body',
        'expires_at',
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
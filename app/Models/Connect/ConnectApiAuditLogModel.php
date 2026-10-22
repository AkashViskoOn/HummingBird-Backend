<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — security and API audit log.
 *
 * Never log Authorization headers, raw secrets, passwords, OTPs, or sensitive
 * tokens. Store a sanitized request_hash / safe metadata only.
 */
class ConnectApiAuditLogModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_api_audit_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'credential_id',
        'request_id',
        'method',
        'endpoint',
        'ip_address',
        'user_agent',
        'request_hash',
        'response_code',
        'status',
        'error_code',
        'action',
        'external_user_id',
        'humming_bird_user_id',
    ];

    // Dates — audit rows are append-only; no updated_at column exists.
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

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
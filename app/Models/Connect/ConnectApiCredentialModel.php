<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — API credentials for external platforms.
 *
 * Only the HASH of the secret is ever stored (secret_hash). The exposed
 * credential_id (e.g. hb_cred_xxxx) is safe to show to the tenant.
 */
class ConnectApiCredentialModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_api_credentials';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'credential_id',
        'api_key',
        'key_prefix',
        'secret_hash',
        'name',
        'environment',
        'status',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
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
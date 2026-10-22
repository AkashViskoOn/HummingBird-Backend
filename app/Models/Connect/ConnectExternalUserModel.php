<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — user identity originating from an external platform.
 *
 * Does NOT replace the Humming Bird user. The same external_user_id may exist
 * under different tenants (unique per tenant_id + external_user_id).
 */
class ConnectExternalUserModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_external_users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'environment',
        'external_user_id',
        'external_username',
        'external_email',
        'external_phone',
        'metadata',
        'status',
        'last_seen_at',
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
<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — verified service identity shown inside Humming Bird
 * (e.g. "Remark", "Remark HR", "Visko Real Estate").
 */
class ConnectServiceIdentityModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_service_identities';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'name',
        'display_name',
        'avatar',
        'description',
        'verification_status',
        'status',
        'metadata',
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
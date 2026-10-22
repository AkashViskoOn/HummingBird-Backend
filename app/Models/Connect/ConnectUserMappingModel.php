<?php

namespace App\Models\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — maps an external platform user to a real Humming Bird
 * user. A tenant must never map an external user to an arbitrary Humming Bird
 * user without authorization.
 */
class ConnectUserMappingModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'connect_user_mappings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tenant_id',
        'environment',
        'external_user_id',
        'humming_bird_user_id',
        'mapping_status',
        'mapped_at',
        'unmapped_at',
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
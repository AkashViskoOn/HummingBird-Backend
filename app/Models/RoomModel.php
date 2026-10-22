<?php

namespace App\Models;

use CodeIgniter\Model;

class RoomModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'rooms';
    protected $primaryKey       = 'r_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ["r_unique_id","r_name","r_type","r_avatar","r_created_by","r_users","r_admins","r_status", "r_user_deleted"];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

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

    
    
    function update_room($roomId, $roomData) {
        $builder = $this->db->table('rooms');
        return $builder->where('r_id', $roomId)->update($roomData);
    }

}

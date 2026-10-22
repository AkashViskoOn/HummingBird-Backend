<?php

namespace App\Models;

use CodeIgniter\Model;

class StatusUpdateModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'status_updates';
    protected $primaryKey       = 'su_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'su_user_id',
        'su_content',
        'su_media_url',
        'su_media_type',
        'su_schedule_id',
        'su_created_at',
        'su_expires_at',
        'su_is_archived',
        'su_privacy',
        'su_bg_color',
        'su_font_style',
        'su_text_color',
        'su_caption',
        'su_location',
        'su_link',
        'su_mentions',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

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

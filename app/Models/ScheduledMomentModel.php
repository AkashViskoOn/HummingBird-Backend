<?php

namespace App\Models;

use CodeIgniter\Model;

class ScheduledMomentModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'scheduled_moments';
    protected $primaryKey       = 'sm_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'sm_user_id',
        'sm_story_type',
        'sm_image',
        'sm_video',
        'sm_thumbnail',
        'sm_caption',
        'sm_visibility',
        'sm_publish_type',
        'sm_scheduled_datetime',
        'sm_published_datetime',
        'sm_expiry_hours',
        'sm_status',
        'sm_draft',
        'sm_view_count',
        'sm_reply_count',
        'sm_share_count',
        'sm_created_at',
        'sm_updated_at',
        'sm_deleted_at',
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

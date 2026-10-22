<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * A linked device (desktop / mobile / tablet) belonging to a user.
 *
 * There is no session table in this schema, so this row IS the device identity.
 * Revoking a row is what ends that device's access: the desktop token carries
 * `device_id`, and DeviceService re-checks `status` on every authenticated call.
 */
class UserDeviceModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'user_devices';
    protected $primaryKey       = 'device_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'platform',
        'device_name',
        'browser',
        'app_version',
        'ip_address',
        'status',
        'created_at',
        'last_seen',
        'revoked_at',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';

    protected $skipValidation = false;
    protected $cleanValidationRules = true;
}

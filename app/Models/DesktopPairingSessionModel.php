<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * A short-lived QR pairing attempt for linking a desktop to an account.
 *
 * Lifecycle: pending -> scanned -> approved -> consumed, with rejected /
 * expired / cancelled as terminals. A session is consumable exactly once:
 * `consumed_at` is set inside the same conditional UPDATE that flips the
 * status, so two concurrent claims cannot both win.
 */
class DesktopPairingSessionModel extends Model
{
    protected $DBGroup          = 'default';
    protected $table            = 'desktop_pairing_sessions';
    protected $primaryKey       = 'pairing_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'pairing_ref',
        'challenge',
        'device_name',
        'platform',
        'browser',
        'app_version',
        'ip_address',
        'status',
        'device_id',
        'claim_secret_hash',
        'created_at',
        'expires_at',
        'approved_at',
        'consumed_at',
        'claim_nonce',
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';

    protected $skipValidation = false;
    protected $cleanValidationRules = true;
}

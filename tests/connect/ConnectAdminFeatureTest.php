<?php

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\AddConnectMappingEnvironmentAndScopes;
use App\Database\Migrations\AddConnectPhase4MessageSchema;
use App\Database\Migrations\AddConnectPhase5Outbox;
use App\Database\Migrations\AddConnectPhase7StatusAndWebhooks;
use App\Database\Migrations\AddConnectPhase8RateLimitsAndQuotas;
use App\Database\Migrations\AddConnectPhase9AdminSchema;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectSecretVault;
use App\Services\Connect\ConnectTenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 9 admin dashboard API.
 *
 * Covers:
 *   - Admin role gate (credential role must be 'admin')
 *   - Admin scope enforcement (admin:connect:overview|view|manage|audit|usage)
 *   - Tenant allowlist isolation (all / specific / out-of-scope)
 *   - Dashboard metrics correctness (counts match seeded fixtures)
 *   - List masking (no api_key/secret_hash/webhook payload/body/payload)
 *   - List pagination, sort allowlist, date range, search
 *   - Identity / webhook mutations delegated to existing service (audit written)
 *   - Update webhook no secret in response
 *   - Out-of-scope row → 404 (not 403)
 *   - Invalid params → 400 CONNECT_ADMIN_INVALID_PARAMETER
 *
 * Rate limiting + quotas are DISABLED for every test to keep counters
 * deterministic (the admin usage endpoint reads counters seeded directly).
 *
 *   php -d extension="C:\CI4\php day 1\php\ext\php_sqlite3.dll" vendor\bin\phpunit --no-coverage tests\connect\ConnectAdminFeatureTest.php
 *
 * @internal
 */
final class ConnectAdminFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SECRET_ENV = 'test-connect-vault-key-0123456789abcdef';

    private CreateHummingBirdConnectFoundation      $foundation;
    private AddConnectApiKeyAndScopes                $phase2Migration;
    private AddConnectMappingEnvironmentAndScopes    $phase3Migration;
    private AddConnectPhase4MessageSchema            $phase4Migration;
    private AddConnectPhase5Outbox                   $phase5Migration;
    private AddConnectPhase7StatusAndWebhooks        $phase7Migration;
    private AddConnectPhase8RateLimitsAndQuotas      $phase8Migration;
    private AddConnectPhase9AdminSchema              $phase9Migration;
    private ConnectSecretVault                       $vault;

    // Tenant ids
    private int $tenantA;
    private int $tenantB;

    // Service identity ids
    private int $idA;
    private int $idA2;
    private int $idB;
    private int $idDisabled;

    // Webhook ids
    private int $whA;
    private int $whA2;
    private int $whB;

    // External message ids
    private int $msgA1;  // delivered (now)
    private int $msgA2;  // failed (yesterday — excluded by date range)
    private int $msgA3;  // sent (now)
    private int $msgB1;  // delivered (now)

    // Credential api_key → secret, plus credential db ids for audit assertions
    private string $adminAllKey;
    private string $adminAllSecret;
    private int    $adminAllDbId;

    private string $adminABKey;
    private string $adminABSecret;

    private string $adminAKey;
    private string $adminASecret;

    private string $adminNoManageKey;
    private string $adminNoManageSecret;

    private string $apiOnlyKey;
    private string $apiOnlySecret;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('CONNECT_SECRET_ENCRYPTION_KEY=' . self::SECRET_ENV);
        putenv('CONNECT_DEBUG=1');
        putenv('CONNECT_RATE_LIMIT_ENABLED=0');
        putenv('CONNECT_QUOTA_ENABLED=0');
        $this->vault = new ConnectSecretVault();

        foreach ([
            '2026-09-08-000001_CreateHummingBirdConnectFoundation',
            '2026-09-09-000001_AddConnectApiKeyAndScopes',
            '2026-09-10-000001_AddConnectMappingEnvironmentAndScopes',
            '2026-09-12-000001_AddConnectPhase4MessageSchema',
            '2026-09-13-000001_AddConnectPhase5Outbox',
            '2026-09-14-000001_AddConnectPhase7StatusAndWebhooks',
            '2026-09-15-000001_AddConnectPhase8RateLimitsAndQuotas',
            '2026-09-16-000001_AddConnectPhase9AdminSchema',
        ] as $file) {
            require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        }

        $this->foundation      = new CreateHummingBirdConnectFoundation();
        $this->phase2Migration = new AddConnectApiKeyAndScopes();
        $this->phase3Migration = new AddConnectMappingEnvironmentAndScopes();
        $this->phase4Migration = new AddConnectPhase4MessageSchema();
        $this->phase5Migration = new AddConnectPhase5Outbox();
        $this->phase7Migration = new AddConnectPhase7StatusAndWebhooks();
        $this->phase8Migration = new AddConnectPhase8RateLimitsAndQuotas();
        $this->phase9Migration = new AddConnectPhase9AdminSchema();

        // Recreate ALL connect tables from scratch for every test method.
        $this->foundation->down();
        $this->foundation->up();
        $this->phase2Migration->up();
        $this->phase3Migration->up();
        $this->phase4Migration->up();
        $this->phase5Migration->down();
        $this->phase5Migration->up();
        $this->phase7Migration->up();
        $this->phase8Migration->down();
        $this->phase8Migration->up();
        $this->phase9Migration->up();

        $this->createUsersTable();
        $this->createMessagesTable();
        $this->createRoomsTable();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->dropTable('messages');
        $this->dropTable('rooms');
        $this->dropUsersTable();
        $this->dropTable('connect_rate_limit_counters');
        $this->dropTable('connect_message_status');
        $this->dropTable('connect_message_outbox');

        if (isset($this->foundation)) {
            $this->foundation->down();
        }

        putenv('CONNECT_SECRET_ENCRYPTION_KEY');
        putenv('CONNECT_DEBUG');
        putenv('CONNECT_RATE_LIMIT_ENABLED');
        putenv('CONNECT_QUOTA_ENABLED');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Minimal HB table stubs
    // ------------------------------------------------------------------

    private function createUsersTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->db->DBPrefix . 'users ('
            . 'user_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'user_first_name TEXT DEFAULT NULL,'
            . 'user_last_name TEXT DEFAULT NULL,'
            . 'user_name TEXT DEFAULT NULL,'
            . 'user_email TEXT DEFAULT NULL,'
            . 'user_mobile_num TEXT DEFAULT NULL,'
            . 'user_image TEXT DEFAULT NULL,'
            . 'u_is_deleted INTEGER DEFAULT 0,'
            . 'fcm_token TEXT DEFAULT NULL,'
            . 'web_fcm_token TEXT DEFAULT NULL'
            . ')');
    }

    private function dropUsersTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->DBPrefix . 'users');
        $this->db->resetDataCache();
    }

    private function createMessagesTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->db->DBPrefix . 'messages ('
            . 'm_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'm_unique_id TEXT,'
            . 'm_room_id TEXT,'
            . 'm_sender_id TEXT,'
            . 'm_receiver_id TEXT,'
            . 'm_message TEXT,'
            . 'm_message_type TEXT DEFAULT "0",'
            . 'm_read_status TEXT DEFAULT "1",'
            . 'm_media TEXT DEFAULT "",'
            . 'm_is_scheduled TEXT DEFAULT "0",'
            . 'm_scheduled_at TEXT DEFAULT "0",'
            . 'm_interval TEXT DEFAULT "0",'
            . 'm_count TEXT DEFAULT "0",'
            . 'm_replied_on TEXT DEFAULT NULL,'
            . 'm_epoch_time TEXT,'
            . 'm_status TEXT DEFAULT ""'
            . ')');
    }

    private function createRoomsTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->db->DBPrefix . 'rooms ('
            . 'r_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'r_unique_id TEXT,'
            . 'r_name TEXT,'
            . 'r_type TEXT DEFAULT "0",'
            . 'r_avatar TEXT DEFAULT "",'
            . 'r_created_by TEXT,'
            . 'r_users TEXT,'
            . 'r_admins TEXT,'
            . 'r_status TEXT DEFAULT "1",'
            . 'r_user_deleted TEXT DEFAULT "[]"'
            . ')');
    }

    private function dropTable(string $name): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->DBPrefix . $name);
        $this->db->resetDataCache();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));

        // ---- Tenants ----
        $this->db->table('connect_tenants')->insert([
            'uuid' => uniqid('tA_', true), 'name' => 'Tenant A', 'slug' => 'tenant-a',
            'status' => 'active', 'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at' => $now, 'updated_at' => $now,
        ], true);
        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('connect_tenants')->insert([
            'uuid' => uniqid('tB_', true), 'name' => 'Tenant B', 'slug' => 'tenant-b',
            'status' => 'active', 'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at' => $now, 'updated_at' => $now,
        ], true);
        $this->tenantB = (int) $this->db->insertID();

        // ---- Service identities ----
        foreach ([
            ['name' => 'remark',  'tenant_id' => $this->tenantA, 'status' => ConnectConfig::IDENTITY_STATUS_ACTIVE],
            ['name' => 'remark2', 'tenant_id' => $this->tenantA, 'status' => ConnectConfig::IDENTITY_STATUS_ACTIVE],
            ['name' => 'remark-b','tenant_id' => $this->tenantB, 'status' => ConnectConfig::IDENTITY_STATUS_ACTIVE],
            ['name' => 'remark-d','tenant_id' => $this->tenantA, 'status' => ConnectConfig::IDENTITY_STATUS_DISABLED],
        ] as $row) {
            $this->db->table('connect_service_identities')->insert(array_merge($row, [
                'display_name'       => $row['name'],
                'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
                'created_at' => $now, 'updated_at' => $now,
            ]), true);
            $id = (int) $this->db->insertID();
            match ($row['name']) {
                'remark'  => $this->idA = $id,
                'remark2' => $this->idA2 = $id,
                'remark-b'=> $this->idB = $id,
                default   => $this->idDisabled = $id,
            };
        }

        // ---- API credentials ----
        $allScopes = [
            ConnectConfig::SCOPE_ADMIN_OVERVIEW,
            ConnectConfig::SCOPE_ADMIN_VIEW,
            ConnectConfig::SCOPE_ADMIN_USAGE,
            ConnectConfig::SCOPE_ADMIN_AUDIT,
            ConnectConfig::SCOPE_ADMIN_MANAGE,
        ];
        $viewScopes = array_diff($allScopes, [ConnectConfig::SCOPE_ADMIN_MANAGE]);

        $creds = [
            'adminAll'      => ['tenant_id' => $this->tenantA, 'role' => 'admin', 'allowed' => null, 'scopes' => $allScopes],
            'adminAB'       => ['tenant_id' => $this->tenantA, 'role' => 'admin', 'allowed' => [$this->tenantA, $this->tenantB], 'scopes' => $allScopes],
            'adminA'        => ['tenant_id' => $this->tenantA, 'role' => 'admin', 'allowed' => [$this->tenantA], 'scopes' => $allScopes],
            'adminNoManage' => ['tenant_id' => $this->tenantA, 'role' => 'admin', 'allowed' => null, 'scopes' => $viewScopes],
            'apiOnly'       => ['tenant_id' => $this->tenantA, 'role' => 'api',    'allowed' => null, 'scopes' => $allScopes],
        ];

        foreach ($creds as $name => $def) {
            $secret = 'test_secret_' . $name . '_0001';
            $apiKey = 'hb_test_' . bin2hex(random_bytes(8));
            $this->db->table('connect_api_credentials')->insert([
                'tenant_id'            => $def['tenant_id'],
                'credential_id'        => 'hb_cred_' . bin2hex(random_bytes(8)),
                'api_key'              => $apiKey,
                'key_prefix'           => ConnectConfig::keyPrefixFor(ConnectConfig::TENANT_ENVIRONMENT_TEST),
                'secret_hash'          => $this->vault->seal($secret),
                'name'                 => $name,
                'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'status'               => ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
                'role'                 => $def['role'],
                'allowed_tenant_ids'   => $def['allowed'] === null ? null : json_encode($def['allowed']),
                'scopes'               => json_encode($def['scopes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at'           => $now, 'updated_at' => $now,
            ], true);
            $dbId = (int) $this->db->insertID();

            $keyProp   = $name . 'Key';
            $secretProp = $name . 'Secret';
            $this->$keyProp   = $apiKey;
            $this->$secretProp = $secret;

            if ($name === 'adminAll') {
                $this->adminAllDbId = $dbId;
            }
        }

        // ---- Webhooks (uuid + environment added by Phase 7) ----
        foreach ([
            ['name' => 'wh-a',  'tenant_id' => $this->tenantA, 'status' => 'active',   'events' => ['message.delivered', 'message.failed']],
            ['name' => 'wh-a2', 'tenant_id' => $this->tenantA, 'status' => 'active',   'events' => ['message.accepted']],
            ['name' => 'wh-b',  'tenant_id' => $this->tenantB, 'status' => 'disabled', 'events' => ['message.accepted']],
        ] as $i => $def) {
            $uuid = 'wh-' . ($i + 1) . '-' . bin2hex(random_bytes(4));
            $this->db->table('connect_webhooks')->insert([
                'uuid'          => $uuid,
                'tenant_id'     => $def['tenant_id'],
                'environment'   => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'url'           => 'https://webhook-' . ($i + 1) . '.example.com/hook',
                'secret_hash'   => $this->vault->seal('webhook_secret_' . ($i + 1)),
                'events'        => json_encode($def['events']),
                'status'        => $def['status'],
                'name'          => $def['name'],
                'description'   => 'Test webhook ' . $def['name'],
                'last_success_at' => ($def['name'] === 'wh-a') ? $now : null,
                'last_failure_at' => null,
                'created_at'    => $now, 'updated_at' => $now,
            ], true);
            $id = (int) $this->db->insertID();
            match ($def['name']) {
                'wh-a'  => $this->whA = $id,
                'wh-a2' => $this->whA2 = $id,
                default => $this->whB = $id,
            };
        }

        // ---- Webhook deliveries (one delivery per webhook event — UNIQUE webhook_id+event_id) ----
        $deliveredHash = hash('sha256', '{"ok":true}');
        $failedHash    = hash('sha256', '{"error":"timeout"}');
        foreach ([
            ['webhook_id' => $this->whA, 'event_type' => 'message.delivered', 'status' => ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED, 'hash' => $deliveredHash, 'http' => 200, 'attempt' => 1],
            ['webhook_id' => $this->whA, 'event_type' => 'message.failed',    'status' => ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,    'hash' => $failedHash,    'http' => 504, 'attempt' => 3],
            ['webhook_id' => $this->whA2,'event_type' => 'message.accepted',  'status' => ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED, 'hash' => $deliveredHash, 'http' => 200, 'attempt' => 1],
            ['webhook_id' => $this->whB, 'event_type' => 'message.accepted',  'status' => ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,   'hash' => null,           'http' => null, 'attempt' => 0],
        ] as $def) {
            $eventUuid = 'evt-del-' . bin2hex(random_bytes(8));
            $this->db->table('connect_webhook_deliveries')->insert([
                'uuid'           => $eventUuid,
                'webhook_id'     => $def['webhook_id'],
                'webhook_uuid'   => '',
                'tenant_id'      => $def['webhook_id'] === $this->whB ? $this->tenantB : $this->tenantA,
                'environment'    => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'event_id'       => $eventUuid,
                'event_type'     => $def['event_type'],
                'payload_hash'   => $def['hash'],
                'status'         => $def['status'],
                'attempt'        => $def['attempt'],
                'http_status'    => $def['http'],
                'response_time_ms' => null,
                'delivered_at'   => $def['status'] === ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED ? $now : null,
                'created_at'     => $now, 'updated_at' => $now,
            ], true);
        }

        // ---- External messages (unique (tenant_id, environment, external_message_id)) ----
        $msgNow = $now;
        $msgYesterday = $yesterday;
        foreach ([
            ['ext_id' => 'ext_admin_msg_001', 'tenant_id' => $this->tenantA, 'sid' => $this->idA,  'status' => 'delivered', 'created_at' => $msgNow,      'body' => 'Hello delivered', 'uuid' => uniqid('m1_', true)],
            ['ext_id' => 'ext_admin_msg_002', 'tenant_id' => $this->tenantA, 'sid' => $this->idA,  'status' => 'failed',    'created_at' => $msgYesterday,'body' => 'Old failed msg', 'uuid' => uniqid('m2_', true)],
            ['ext_id' => 'ext_admin_msg_003', 'tenant_id' => $this->tenantA, 'sid' => $this->idA2, 'status' => 'sent',      'created_at' => $msgNow,      'body' => 'Still sending',  'uuid' => uniqid('m3_', true)],
            ['ext_id' => 'ext_admin_msg_b01', 'tenant_id' => $this->tenantB, 'sid' => $this->idB,  'status' => 'delivered', 'created_at' => $msgNow,      'body' => 'Tenant B msg',   'uuid' => uniqid('m4_', true)],
        ] as $def) {
            $this->db->table('connect_external_messages')->insert([
                'uuid'                 => $def['uuid'],
                'tenant_id'            => $def['tenant_id'],
                'service_identity_id'  => $def['sid'],
                'external_user_id'     => 'ext_user_admin',
                'message_uuid'         => $def['uuid'],
                'external_event_id'    => uniqid('evt_', true),
                'external_message_id'  => $def['ext_id'],
                'request_id'           => 'req_seed_' . $def['ext_id'],
                'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'message_type'         => ConnectConfig::MESSAGE_TYPE_NOTIFICATION,
                'title'                => 'Subject for ' . $def['ext_id'],
                'body'                 => $def['body'],
                'payload'              => json_encode(['seed' => true]),
                'status'               => $def['status'],
                'room_id'              => null,
                'message_id'           => null,
                'error_code'           => $def['status'] === 'failed' ? 'DELIVERY_FAILED' : null,
                'error_message'        => $def['status'] === 'failed' ? 'Timeout' : null,
                'created_at'           => $def['created_at'],
                'updated_at'           => $def['created_at'],
            ], true);
            $id = (int) $this->db->insertID();
            match ($def['ext_id']) {
                'ext_admin_msg_001' => $this->msgA1 = $id,
                'ext_admin_msg_002' => $this->msgA2 = $id,
                'ext_admin_msg_003' => $this->msgA3 = $id,
                default             => $this->msgB1 = $id,
            };
        }

        // ---- Message status ledger (connect_message_status) ----
        foreach ([
            ['ext_id' => 'ext_admin_msg_001', 'tenant_id' => $this->tenantA, 'statuses' => ['accepted','queued','processing','sent','delivered'], 'occurred' => $now],
            ['ext_id' => 'ext_admin_msg_002', 'tenant_id' => $this->tenantA, 'statuses' => ['accepted','queued','processing','failed'],          'occurred' => $yesterday],
            ['ext_id' => 'ext_admin_msg_b01', 'tenant_id' => $this->tenantB, 'statuses' => ['accepted','delivered'],                            'occurred' => $now],
        ] as $def) {
            foreach ($def['statuses'] as $i => $status) {
                $occ = $def['occurred'];
                if ($status !== 'accepted') {
                    $occ = date('Y-m-d H:i:s', strtotime($occ) + $i);
                }
                $this->db->table('connect_message_status')->insert([
                    'uuid'                       => uniqid('st_', true),
                    'tenant_id'                  => $def['tenant_id'],
                    'environment'                => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                    'external_message_id'        => $def['ext_id'],
                    'connect_message_id'         => null,
                    'humming_bird_message_id'    => null,
                    'status'                     => $status,
                    'status_reason'              => null,
                    'occurred_at'                => $occ,
                    'created_at'                 => $occ,
                ], true);
            }
        }

        // ---- Rate/quota counters (connect_rate_limit_counters) ----
        $monthStart = (new \DateTime())->format('Y-m-01 00:00:00');
        $dayStart   = (new \DateTime())->format('Y-m-d 00:00:00');
        $rateWindow = date('Y-m-d H:i:s', strtotime('-30 seconds'));

        foreach ([
            ['kind' => 'quota', 'cat' => 'messages.month', 'tenant' => $this->tenantA, 'used' => 10,  'limit' => 1000, 'bucket' => $monthStart],
            ['kind' => 'quota', 'cat' => 'messages.day',   'tenant' => $this->tenantA, 'used' => 3,   'limit' => 100,  'bucket' => $dayStart],
            ['kind' => 'quota', 'cat' => 'messages.month', 'tenant' => $this->tenantB, 'used' => 1,   'limit' => 1000, 'bucket' => $monthStart],
            ['kind' => 'rate',  'cat' => 'messages.send:60','tenant'=> $this->tenantA, 'used' => 3,   'limit' => 60,   'bucket' => $rateWindow],
            ['kind' => 'rate',  'cat' => 'all:60.m',       'tenant' => $this->tenantB, 'used' => 1,   'limit' => 60,   'bucket' => $rateWindow],
        ] as $def) {
            $this->db->table('connect_rate_limit_counters')->insert([
                'kind'           => $def['kind'],
                'scope_category' => $def['cat'],
                'tenant_id'      => $def['tenant'],
                'environment'    => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'credential_id'  => 0,
                'bucket_start'   => $def['bucket'],
                'usage_count'    => $def['used'],
                'limit_value'    => $def['limit'],
                'created_at'     => $def['bucket'],
                'updated_at'     => $def['bucket'],
            ], true);
        }

        // ---- Audit logs ----
        foreach ([
            ['tenant_id' => $this->tenantA, 'status' => 'success', 'action' => 'CONNECT_MESSAGE_CREATED',  'error_code' => null, 'request_id' => 'req_audit_seed_a1'],
            ['tenant_id' => $this->tenantB, 'status' => 'error',   'action' => null,                        'error_code' => 'DATABASE_ERROR', 'request_id' => 'req_audit_seed_b1'],
        ] as $def) {
            $this->db->table('connect_api_audit_logs')->insert([
                'tenant_id'        => $def['tenant_id'],
                'credential_id'    => $this->adminAllDbId,
                'request_id'       => $def['request_id'],
                'method'           => 'GET',
                'endpoint'         => '/api/v1/connect/admin/audit-logs',
                'ip_address'       => '127.0.0.1',
                'response_code'    => $def['status'] === 'success' ? 200 : 500,
                'status'           => $def['status'],
                'error_code'       => $def['error_code'],
                'action'           => $def['action'],
                'created_at'       => date('Y-m-d H:i:s'),
            ], true);
        }
    }

    // ------------------------------------------------------------------
    // Signing + request helpers (mirror ConnectRateLimitQuotaFeatureTest)
    // ------------------------------------------------------------------

    private function signHeaders(
        string $apiKey,
        string $secret,
        string $method,
        string $path,
        string $body = '',
    ): array {
        $ts   = time();
        $rid  = ConnectAuthenticationService::requestId();
        $sign = ConnectAuthenticationService::sign($method, $path, (string) $ts, $rid, $body, $secret);

        $headers = [
            ConnectConfig::HEADER_API_KEY    => $apiKey,
            ConnectConfig::HEADER_SIGNATURE  => $sign,
            ConnectConfig::HEADER_TIMESTAMP  => (string) $ts,
            ConnectConfig::HEADER_REQUEST_ID => $rid,
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        return $headers;
    }

    private function runConnect(string $method, string $path, array $headers, string $body = '', array $injections = []): TestResponse
    {
        Services::reset();

        $this->request = $this->setupRequest($method, $path);
        $this->request->setMethod($method);
        $this->request = $this->setupHeaders($this->request);

        foreach ($headers as $name => $value) {
            $this->request = $this->request->setHeader($name, $value);
        }
        if ($body !== '') {
            $this->request->setBody($body);
        }

        parse_str((string) $this->request->getUri()->getQuery(), $queryVars);
        $this->request->setGlobal('get', $queryVars);

        Services::injectMock('request', $this->request);

        foreach ($injections as $service => $instance) {
            Services::injectMock($service, $instance);
        }

        $routes = Services::routes()->loadRoutes();
        $routes->setHTTPVerb($method);

        $this->app = $this->createApplication();
        $response  = $this->app
            ->setContext('web')
            ->setRequest($this->request)
            ->run($routes, true);

        Services::reset();

        return new TestResponse($response);
    }

    /**
     * GET request: sign path-only (no query) but send path+query to the router.
     */
    private function adminGet(
        string $apiKey,
        string $secret,
        string $path,
        array  $query = [],
    ): TestResponse {
        $fullPath = $path;
        if ($query !== []) {
            $fullPath .= '?' . http_build_query($query);
        }

        return $this->runConnect(
            'GET',
            $fullPath,
            $this->signHeaders($apiKey, $secret, 'GET', $path),
        );
    }

    /**
     * PATCH request (body JSON-encoded): signs path + body.
     */
    private function adminPatch(
        string $apiKey,
        string $secret,
        string $path,
        array  $body = [],
    ): TestResponse {
        $jsonBody = $body !== [] ? json_encode($body) : '';

        return $this->runConnect(
            'PATCH',
            $path,
            $this->signHeaders($apiKey, $secret, 'PATCH', $path, $jsonBody),
            $jsonBody,
        );
    }

    private function json(TestResponse $response): object
    {
        $body    = (string) $response->response()->getBody();
        $decoded = json_decode($body);

        $this->assertInstanceOf(
            \stdClass::class,
            $decoded,
            'Response body is not valid JSON: ' . substr($body, 0, 200),
        );

        return $decoded;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    // 1. No credentials → 401 (MISSING_AUTH_HEADERS).
    public function test01UnauthorizedWithoutCredentials(): void
    {
        $this->runConnect('GET', '/api/v1/connect/admin/dashboard', [])->assertStatus(401);
    }

    // 2. Role gate: plain 'api' credential → 403 even with admin scopes.
    public function test02RoleGateRejectsPlainApiCredential(): void
    {
        $r = $this->adminGet($this->apiOnlyKey, $this->apiOnlySecret, '/api/v1/connect/admin/dashboard');
        $r->assertStatus(403);
        $j = $this->json($r);
        $this->assertSame(ConnectConfig::ERROR_INSUFFICIENT_SCOPE, $j->error->code);
    }

    // 3. Scope gate: admin missing manage scope → 403 on PATCH.
    public function test03ScopeGateRejectsNonManageForMutation(): void
    {
        $r = $this->adminPatch(
            $this->adminNoManageKey,
            $this->adminNoManageSecret,
            '/api/v1/connect/admin/webhooks/' . $this->whA . '/status',
            ['status' => 'disabled'],
        );
        $r->assertStatus(403);
    }

    // 4. Dashboard counts all tenants for an unrestricted admin.
    public function test04DashboardOverviewCountsAllTenants(): void
    {
        $r = $this->adminGet($this->adminAllKey, $this->adminAllSecret, '/api/v1/connect/admin/dashboard');
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);

        $d = $j->data;
        $this->assertSame(2, $d->tenants->total);
        $this->assertSame(2, $d->tenants->active);

        // identities: remark, remark2, remark-b = active; remark-d = disabled
        $this->assertSame(3, $d->service_identities->active);
        $this->assertSame(1, $d->service_identities->disabled);

        // credentials: all five seeded creds are active
        $this->assertSame(5, $d->credentials->active);

        // webhooks: wh-a + wh-a2 = active, wh-b = disabled
        $this->assertSame(2, $d->webhooks->active);
        $this->assertSame(1, $d->webhooks->disabled);

$this->assertSame(2, $d->messages->delivered);
        $this->assertSame(1, $d->messages->failed);
        $this->assertSame(1, $d->messages->sent);
        $this->assertSame(4, $d->messages->total);
        // delivered_read = delivered+read = 2+0=2
        $this->assertSame(2, $d->messages->delivered_read);
    }

    // 5. Scoped admin sees only their tenant's counts.
    public function test05DashboardShowOnlyScopedTenantsForAdminA(): void
    {
        $r = $this->adminGet($this->adminAKey, $this->adminASecret, '/api/v1/connect/admin/dashboard');
        $r->assertStatus(200);
        $d = $this->json($r)->data;

        $this->assertSame(1, $d->tenants->total);

        // identities in tenant A: remark (active), remark2 (active), remark-d (disabled) = 3 total, 2 active, 1 disabled
        $this->assertSame(2, $d->service_identities->active);
        $this->assertSame(1, $d->service_identities->disabled);

        // webhooks in tenant A: wh-a (active), wh-a2 (active) = 2 active; wh-b excluded
        $this->assertSame(2, $d->webhooks->active);
        $this->assertSame(0, $d->webhooks->disabled);

        // messages in tenant A: mA1 (delivered), mA2 (failed), mA3 (sent) = 3 total
        $this->assertSame(3, $d->messages->total);
    }

    // 6. Requested tenant outside allowlist → 403.
    public function test06RequestedTenantOutsideAllowlistIsForbidden(): void
    {
        $r = $this->adminGet(
            $this->adminAKey,
            $this->adminASecret,
            '/api/v1/connect/admin/dashboard',
            ['tenant_id' => $this->tenantB],
        );
        $r->assertStatus(403);
    }

    // 7. Credentials list: no api_key/secret_hash in response.
    public function test07CredentialsListMasked(): void
    {
        $r = $this->adminGet($this->adminAllKey, $this->adminAllSecret, '/api/v1/connect/admin/credentials');
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);

        foreach ($j->data->items as $item) {
            $this->assertArrayNotHasKey('api_key', (array) $item);
            $this->assertArrayNotHasKey('secret_hash', (array) $item);
            $this->assertArrayHasKey('key_prefix', (array) $item);
            $this->assertArrayHasKey('credential_id', (array) $item);
            $this->assertArrayHasKey('role', (array) $item);
        }
        $this->assertGreaterThanOrEqual(5, $j->data->pagination->total);
    }

    // 8. Messages list: no body/title/payload; status filter works.
    public function test08MessagesListMetadataOnlyAndFilter(): void
    {
        $r = $this->adminGet(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/messages',
            ['status' => 'delivered'],
        );
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);

        foreach ($j->data->items as $item) {
            $this->assertArrayNotHasKey('body', (array) $item);
            $this->assertArrayNotHasKey('title', (array) $item);
            $this->assertArrayNotHasKey('payload', (array) $item);
            $this->assertSame('delivered', $item->status);
        }
        // delivered: msgA1 + msgB1 = 2
        $this->assertSame(2, $j->data->pagination->total);
    }

    // 9. Message detail: out-of-scope message → 404.
    public function test09MessageDetailNotFoundWhenOutOfScope(): void
    {
        $r = $this->adminGet(
            $this->adminAKey,
            $this->adminASecret,
            '/api/v1/connect/admin/messages/' . $this->msgB1,
        );
        $r->assertStatus(404);
    }

    // 10. Delivery events list + date range.
    public function test10DeliveryEventsFromLedger(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today     = date('Y-m-d');

        // accepted → yesterday date range only: msgA2 has 4 statuses yesterday.
        $r = $this->adminGet(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/delivery-events',
            ['status' => 'failed', 'date_from' => $yesterday . ' 00:00:00', 'date_to' => $yesterday . ' 23:59:59'],
        );
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);
        $this->assertSame(1, $j->data->pagination->total);  // msgA2 failed
    }

    // 11. Webhooks list: no secret_hash; delivered_count / failed_count correct.
    public function test11WebhooksListMaskedAndCounts(): void
    {
        $r = $this->adminGet($this->adminAllKey, $this->adminAllSecret, '/api/v1/connect/admin/webhooks');
        $r->assertStatus(200);
        $j = $this->json($r);

        foreach ($j->data->items as $item) {
            $this->assertArrayNotHasKey('secret_hash', (array) $item);
            $this->assertArrayHasKey('events', (array) $item);
        }

        $whAItem = null;
        foreach ($j->data->items as $item) {
            if ($item->id === $this->whA) {
                $whAItem = $item;
                break;
            }
        }
        $this->assertNotNull($whAItem, 'wh-a not found in webhook list');
        $this->assertSame(1, $whAItem->delivered_count);
        $this->assertSame(1, $whAItem->failed_count);
    }

    // 12. Webhook deliveries list: no payload column.
    public function test12WebhookDeliveriesNoPayload(): void
    {
        $r = $this->adminGet($this->adminAllKey, $this->adminAllSecret, '/api/v1/connect/admin/webhook-deliveries');
        $r->assertStatus(200);
        $j = $this->json($r);

        foreach ($j->data->items as $item) {
            $this->assertArrayNotHasKey('payload', (array) $item);
            // payload_hash IS present (the safe proxy)
            $this->assertArrayHasKey('payload_hash', (array) $item);
        }
    }

    // 13. Usage endpoint: quota and rate numbers match seeded counters.
    public function test13UsageQuotaAndRateNumbers(): void
    {
        $r = $this->adminGet($this->adminAllKey, $this->adminAllSecret, '/api/v1/connect/admin/usage');
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);

        // Find tenant A usage entry
        $entryA = null;
        foreach ($j->data->tenants as $entry) {
            if ($entry->tenant_id === $this->tenantA) {
                $entryA = $entry;
                break;
            }
        }
        $this->assertNotNull($entryA);
        $this->assertSame(10, $entryA->quota->{"messages.month"}->used);
        $this->assertSame(1000, $entryA->quota->{"messages.month"}->limit);
        $this->assertSame(990, $entryA->quota->{"messages.month"}->remaining);
        $this->assertSame(3, $entryA->quota->{"messages.day"}->used);
        $this->assertSame(3, $entryA->rate->{'messages.send:60'}->used);
        $this->assertSame(60, $entryA->rate->{'messages.send:60'}->limit);

        $this->assertSame(14, $j->data->totals->quota_used);  // A=10+3=13, B=1
    }

    // 14. Audit logs view.
    public function test14AuditLogsViewAndFilters(): void
    {
        $r = $this->adminGet($this->adminAllKey, $this->adminAllSecret, '/api/v1/connect/admin/audit-logs');
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);
        // seeded 2 + any from filter during this request
        $this->assertGreaterThanOrEqual(2, $j->data->pagination->total);
    }

    // 15. Invalid tenant_id (non-numeric) → 400.
    public function test15InvalidParameterReturns400(): void
    {
        $r = $this->adminGet(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/messages',
            ['tenant_id' => 'abc'],
        );
        $r->assertStatus(400);
        $j = $this->json($r);
        $this->assertSame(ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER, $j->error->code);
    }

    // 16. per_page clamped to 100.
    public function test16PerPageClampedTo100(): void
    {
        $r = $this->adminGet(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/audit-logs',
            ['per_page' => 999],
        );
        $r->assertStatus(200);
        $this->assertSame(100, $this->json($r)->data->pagination->per_page);
    }

    // 17. Invalid sort falls back to default (not 400).
    public function test17InvalidSortFallsBackToDefault(): void
    {
        $r = $this->adminGet(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/audit-logs',
            ['sort' => 'nonexistent', 'order' => 'asc'],
        );
        $r->assertStatus(200);
        $this->assertTrue($this->json($r)->success);
    }

    // 18. Identity status mutation + audit row.
    public function test18IdentityStatusMutationAudited(): void
    {
        $r = $this->adminPatch(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/integrations/' . $this->idA . '/status',
            ['status' => 'disabled'],
        );
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);
        $this->assertSame('disabled', $j->data->identity->status);

        // Audit row: action CONNECT_ADMIN_IDENTITY_STATUS_CHANGED + target tenant A
        $auditRow = $this->db->table('connect_api_audit_logs')
            ->where('action', ConnectConfig::AUDIT_ACTION_ADMIN_IDENTITY_STATUS_CHANGED)
            ->where('tenant_id', $this->tenantA)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($auditRow);
        $this->assertSame($this->adminAllDbId, (int) $auditRow['credential_id']);
    }

    // 19. Identity status change on out-of-scope row → 404.
    public function test19IdentityStatusChangeOutOfScopeIs404(): void
    {
        $r = $this->adminPatch(
            $this->adminAKey,
            $this->adminASecret,
            '/api/v1/connect/admin/integrations/' . $this->idB . '/status',
            ['status' => 'disabled'],
        );
        $r->assertStatus(404);
    }

    // 20. Webhook status mutation delegated via ConnectWebhookService.
    public function test20WebhookStatusMutationDelegatedAudited(): void
    {
        $r = $this->adminPatch(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/webhooks/' . $this->whA . '/status',
            ['status' => 'disabled'],
        );
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertTrue($j->success);
        $this->assertSame('disabled', $j->data->webhook->status);

        // Domain audit row written by ConnectWebhookService::update with admin's credential.
        $auditRow = $this->db->table('connect_api_audit_logs')
            ->where('action', ConnectConfig::AUDIT_ACTION_WEBHOOK_UPDATED)
            ->where('tenant_id', $this->tenantA)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($auditRow);
        $this->assertSame($this->adminAllDbId, (int) $auditRow['credential_id']);
    }

    // 21. Webhook update: no secret in response; DB secret_hash unchanged.
    public function test21WebhookUpdateNoSecretInResponse(): void
    {
        $oldHash = $this->db->table('connect_webhooks')
            ->where('id', $this->whA2)
            ->get()->getRowArray()['secret_hash'];

        $r = $this->adminPatch(
            $this->adminAllKey,
            $this->adminAllSecret,
            '/api/v1/connect/admin/webhooks/' . $this->whA2,
            ['name' => 'wh-a2-renamed'],
        );
        $r->assertStatus(200);
        $j = $this->json($r);
        $this->assertSame('wh-a2-renamed', $j->data->webhook->name);
        $this->assertObjectNotHasProperty('secret', $j->data->webhook);
        $this->assertObjectNotHasProperty('secret_hash', $j->data->webhook);

        $newHash = $this->db->table('connect_webhooks')
            ->where('id', $this->whA2)
            ->get()->getRowArray()['secret_hash'];
        $this->assertSame($oldHash, $newHash);
    }

    // 22. Out-of-scope webhook update → 404 (not 403).
    public function test22OutOfScopeWebhookUpdateIs404(): void
    {
        $r = $this->adminPatch(
            $this->adminAKey,
            $this->adminASecret,
            '/api/v1/connect/admin/webhooks/' . $this->whB . '/status',
            ['status' => 'active'],
        );
        $r->assertStatus(404);
    }

    // 23. Admin-only scope (manage) rejected for api credential.
    public function test23ApiCredentialCannotReachAdminRoutes(): void
    {
        $r = $this->adminGet($this->apiOnlyKey, $this->apiOnlySecret, '/api/v1/connect/admin/usage');
        $r->assertStatus(403);
    }

    // 24. Admin usage: scoped admin only sees counters for their tenants.
    public function test24ScopedAdminUsageExcludesOtherTenant(): void
    {
        $r = $this->adminGet($this->adminAKey, $this->adminASecret, '/api/v1/connect/admin/usage');
        $r->assertStatus(200);
        $j = $this->json($r);

        $tenantIds = array_map(fn($e) => $e->tenant_id, $j->data->tenants);
        $this->assertContains($this->tenantA, $tenantIds);
        $this->assertNotContains($this->tenantB, $tenantIds);
    }
}

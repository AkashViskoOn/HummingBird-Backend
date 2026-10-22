<?php

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\AddConnectMappingEnvironmentAndScopes;
use App\Database\Migrations\AddConnectPhase4MessageSchema;
use App\Database\Migrations\AddConnectPhase5Outbox;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectMessageCipher;
use App\Services\Connect\ConnectMessageProcessor;
use App\Services\Connect\ConnectOutboxService;
use App\Services\Connect\ConnectSecretVault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 4 message-inbound feature tests.
 *
 * Covers the full spec matrix (external message → Humming Bird pipeline):
 * valid send, idempotent send, validation errors, mapping/identity failures,
 * room reuse, bot user creation, tenant/environment isolation, lookup, and
 * audit rows. Secrets are never leaked in responses.
 *
 * Phase 5 made message acceptance asynchronous and durable: POST /messages/send
 * persists the tracking + outbox and returns HTTP 202 (queued) WITHOUT creating
 * the Humming Bird message; the Connect worker (ConnectMessageProcessor) then
 * creates the message. Tests that need a real message row run the worker once.
 *
 *   php -d extension="C:\CI4\php day 1\php\ext\php_sqlite3.dll" vendor\bin\phpunit --no-coverage
 *
 * @internal
 */
final class ConnectMessageFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SECRET_ENV = 'test-connect-vault-key-0123456789abcdef';

    private CreateHummingBirdConnectFoundation      $foundation;
    private AddConnectApiKeyAndScopes               $phase2Migration;
    private AddConnectMappingEnvironmentAndScopes   $phase3Migration;
    private AddConnectPhase4MessageSchema           $phase4Migration;
    private AddConnectPhase5Outbox                  $phase5Migration;
    private ConnectSecretVault                      $vault;

    private int $tenantA;
    private int $tenantB;

    private string $credSend;            // hb_test_* tenant A, messages:send + messages:read scope
    private string $credRead;            // hb_test_* tenant A, messages:read only
    private string $credNoScope;         // hb_test_* tenant A, no message scope
    private string $credProdTenantA;     // hb_live_* tenant A, messages:send scope (prod env)
    private string $credTenantB;         // hb_test_* tenant B, messages:send scope
    private string $secretSend;

    private int $identityA;   // active service identity for tenant A
    private int $identityB;   // active service identity for tenant B
    private int $identityInactive; // inactive identity for tenant A

    private int $hbUser1;     // active HB user
    private int $hbUser2;     // active HB user (for tenant B)
    private int $hbInactive;  // inactive (u_is_deleted=1) HB user

    protected function setUp(): void
    {
        parent::setUp();

        putenv('CONNECT_SECRET_ENCRYPTION_KEY=' . self::SECRET_ENV);
        putenv('CONNECT_DEBUG=1');
        $this->vault = new ConnectSecretVault();

        require_once APPPATH
            . 'Database/Migrations/2026-09-08-000001_CreateHummingBirdConnectFoundation.php';
        require_once APPPATH
            . 'Database/Migrations/2026-09-09-000001_AddConnectApiKeyAndScopes.php';
        require_once APPPATH
            . 'Database/Migrations/2026-09-10-000001_AddConnectMappingEnvironmentAndScopes.php';
        require_once APPPATH
            . 'Database/Migrations/2026-09-12-000001_AddConnectPhase4MessageSchema.php';
        require_once APPPATH
            . 'Database/Migrations/2026-09-13-000001_AddConnectPhase5Outbox.php';

        $this->foundation       = new CreateHummingBirdConnectFoundation();
        $this->phase2Migration  = new AddConnectApiKeyAndScopes();
        $this->phase3Migration  = new AddConnectMappingEnvironmentAndScopes();
        $this->phase4Migration  = new AddConnectPhase4MessageSchema();
        $this->phase5Migration  = new AddConnectPhase5Outbox();

        $this->foundation->down();
        $this->foundation->up();
        $this->phase2Migration->up();
        $this->phase3Migration->up();
        $this->phase4Migration->up();
        $this->phase5Migration->down();
        $this->phase5Migration->up();

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
        $this->dropTable('connect_message_outbox');

        if (isset($this->foundation)) {
            $this->foundation->down();
        }

        putenv('CONNECT_DEBUG');
        parent::tearDown();
    }

    /**
     * Run the Phase 5 worker for a single batch so queued messages become real
     * Humming Bird messages. Socket/FCM dispatch is fire-and-forget and fails
     * harmlessly in the test environment (no local socket), which is itself a
     * required property (spec 23: delivery failures never fail the message).
     */
    private function runWorkerOnce(int $batchSize = 50): int
    {
        return (new ConnectMessageProcessor())->run($batchSize);
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

        // --- tenants ---------------------------------------------------
        $this->db->table('connect_tenants')->insert([
            'uuid'        => uniqid('tA_', true),
            'name'        => 'Tenant A',
            'slug'        => 'tenant-a',
            'status'      => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('connect_tenants')->insert([
            'uuid'        => uniqid('tB_', true),
            'name'        => 'Tenant B',
            'slug'        => 'tenant-b',
            'status'      => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $this->tenantB = (int) $this->db->insertID();

        // --- service identities ----------------------------------------
        $this->db->table('connect_service_identities')->insert([
            'tenant_id'          => $this->tenantA,
            'name'               => 'remark',
            'display_name'       => 'Remark',
            'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
            'status'             => ConnectConfig::IDENTITY_STATUS_ACTIVE,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], true);
        $this->identityA = (int) $this->db->insertID();

        $this->db->table('connect_service_identities')->insert([
            'tenant_id'          => $this->tenantB,
            'name'               => 'remark-b',
            'display_name'       => 'Remark B',
            'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
            'status'             => ConnectConfig::IDENTITY_STATUS_ACTIVE,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], true);
        $this->identityB = (int) $this->db->insertID();

        $this->db->table('connect_service_identities')->insert([
            'tenant_id'          => $this->tenantA,
            'name'               => 'inactive-identity',
            'display_name'       => 'Inactive',
            'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
            'status'             => ConnectConfig::IDENTITY_STATUS_DISABLED,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], true);
        $this->identityInactive = (int) $this->db->insertID();

        // --- credentials -----------------------------------------------
        $this->secretSend = 'test_secret_send_0001';
        $this->credSend = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credSend,
            $this->secretSend,
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        $this->credRead = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credRead,
            'test_secret_read_0001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_MESSAGES_READ],
        );

        $this->credNoScope = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credNoScope,
            'test_noscope_0001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [],
        );

        $this->credProdTenantA = 'hb_live_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credProdTenantA,
            'test_prod_0001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_PRODUCTION,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        $this->credTenantB = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credTenantB,
            'test_tenant_b_001',
            $this->tenantB,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        // --- HB users --------------------------------------------------
        $this->db->table('users')->insert([
            'user_first_name' => 'HB',
            'user_last_name'  => 'UserOne',
            'user_email'      => 'one@example.com',
        ], true);
        $this->hbUser1 = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'user_first_name' => 'HB',
            'user_last_name'  => 'UserTwo',
            'user_email'      => 'two@example.com',
        ], true);
        $this->hbUser2 = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'user_first_name' => 'Inactive',
            'user_last_name'  => 'User',
            'user_email'      => 'inactive@example.com',
            'u_is_deleted'    => 1,
        ], true);
        $this->hbInactive = (int) $this->db->insertID();

        // --- user mappings (tenant A: ext_user_1 → hbUser1) ------------
        $this->db->table('connect_user_mappings')->insert([
            'tenant_id'            => $this->tenantA,
            'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'external_user_id'     => 'ext_user_1',
            'humming_bird_user_id' => $this->hbUser1,
            'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
            'mapped_at'            => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], true);

        // tenant B: ext_user_b → hbUser2
        $this->db->table('connect_user_mappings')->insert([
            'tenant_id'            => $this->tenantB,
            'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'external_user_id'     => 'ext_user_b',
            'humming_bird_user_id' => $this->hbUser2,
            'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
            'mapped_at'            => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], true);
    }

    private function insertCredential(
        string $apiKey,
        string $plainSecret,
        int $tenantId,
        string $environment,
        string $status,
        array $scopes,
    ): void {
        $now = date('Y-m-d H:i:s');

        $this->db->table('connect_api_credentials')->insert([
            'tenant_id'     => $tenantId,
            'credential_id' => 'hb_cred_' . bin2hex(random_bytes(8)),
            'api_key'       => $apiKey,
            'key_prefix'    => ConnectConfig::keyPrefixFor($environment),
            'secret_hash'   => $this->vault->seal($plainSecret),
            'environment'   => $environment,
            'status'        => $status,
            'scopes'        => json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at'    => $now,
            'updated_at'    => $now,
        ], true);
    }

    // ------------------------------------------------------------------
    // App-run + signing helpers
    // ------------------------------------------------------------------

    private function runConnect(string $method, string $path, array $headers, string $body = ''): TestResponse
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

    private function connectRequest(
        string $apiKey,
        string $secret,
        string $method,
        string $path,
        string $body = '',
        ?string $queryExtra = '',
    ): TestResponse {
        $ts   = time();
        $rid  = ConnectAuthenticationService::requestId();
        $sign = ConnectAuthenticationService::sign($method, $path, (string) $ts, $rid, $body, $secret);

        $url = $path;
        if ($queryExtra !== '' && $queryExtra !== null) {
            $url .= (strpos($path, '?') !== false ? '&' : '?') . $queryExtra;
        }

        $headers = [
            ConnectConfig::HEADER_API_KEY    => $apiKey,
            ConnectConfig::HEADER_SIGNATURE  => $sign,
            ConnectConfig::HEADER_TIMESTAMP  => (string) $ts,
            ConnectConfig::HEADER_REQUEST_ID => $rid,
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        return $this->runConnect($method, $url, $headers, $body);
    }

    private function json(TestResponse $response): object
    {
        $body   = (string) $response->response()->getBody();
        $decoded = json_decode($body);

        $this->assertInstanceOf(
            \stdClass::class,
            $decoded,
            'Response body is not valid JSON: ' . substr($body, 0, 200),
        );

        return $decoded;
    }

    // ------------------------------------------------------------------
    // Convenience wrappers for the message endpoints
    // ------------------------------------------------------------------

    private function sendMessage(
        array $body,
        string $apiKey = '',
        string $secret = '',
    ): TestResponse {
        $apiKey = $apiKey ?: $this->credSend;
        $secret = $secret ?: $this->secretSend;

        return $this->connectRequest(
            $apiKey,
            $secret,
            'POST',
            '/api/v1/connect/messages/send',
            json_encode($body),
        );
    }

    private function lookupMessage(
        string $externalMessageId,
        string $apiKey = '',
        string $secret = '',
    ): TestResponse {
        $apiKey = $apiKey ?: $this->credSend;
        $secret = $secret ?: $this->secretSend;

        return $this->connectRequest(
            $apiKey,
            $secret,
            'GET',
            '/api/v1/connect/messages/lookup',
            '',
            "external_message_id={$externalMessageId}",
        );
    }

    private function getRow(string $table, string $column, $value): ?array
    {
        $row = $this->db->table($table)
            ->where($column, $value)
            ->get()
            ->getRowArray();

        return $row === false || $row === null ? null : $row;
    }

    private function countRows(string $table, string $column, $value): int
    {
        return (int) $this->db->table($table)
            ->where($column, $value)
            ->countAllResults();
    }

    // ------------------------------------------------------------------
    // Spec cases 1–27
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // 1. Valid send — basic flow (Phase 5: 202 queued, async)
    // ------------------------------------------------------------------
    public function test01ValidSendReturns202Queued(): void
    {
        $extUserId = 'ext_user_1';
        $extMsgId  = 'remark_msg_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();

        $response = $this->sendMessage([
            'external_user_id'    => $extUserId,
            'body'                => 'Hello from Remark!',
            'external_message_id' => $extMsgId,
        ]);

        $response->assertStatus(202);
        $json = $this->json($response);
        $this->assertTrue($json->status);
        $this->assertSame('queued', $json->data->status);
        $this->assertSame($extUserId, $json->data->external_user_id);
        $this->assertSame($extMsgId, $json->data->external_message_id);
        $this->assertSame((string) $this->hbUser1, $json->data->humming_bird_user_id);
        $this->assertNull($json->data->humming_bird_message_id);
        $this->assertMatchesRegularExpression('/^req_[A-Za-z0-9_-]+$/', $json->request_id);

        // Idempotency + request id are tracked on the durable record.
        $tracking = $this->db->table('connect_external_messages')
            ->where('external_message_id', $extMsgId)
            ->get()
            ->getRowArray();
        $this->assertNotEmpty($tracking);
        $this->assertSame('queued', $tracking['status']);
        $this->assertMatchesRegularExpression('/^req_[A-Za-z0-9_-]+$/', $tracking['request_id']);

        // No Humming Bird message is created until the worker runs.
        $this->assertSame($messagesBefore, (int) $this->db->table('messages')->countAllResults());
    }

    // ------------------------------------------------------------------
    // 2. Valid send without external_message_id (nullable) (202)
    // ------------------------------------------------------------------
    public function test02ValidSendWithoutExternalMessageIdReturns202(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'No external message id',
        ]);

        $response->assertStatus(202);
        $json = $this->json($response);
        $this->assertTrue($json->status);
        $this->assertSame('queued', $json->data->status);
        $this->assertNull($json->data->external_message_id);
        $this->assertNull($json->data->humming_bird_message_id);
    }

    // ------------------------------------------------------------------
    // 3. Idempotent send — duplicate external_message_id (200, same state)
    // ------------------------------------------------------------------
    public function test03DuplicateExternalMessageIdReturns200SameState(): void
    {
        $extMsgId = 'remark_msg_idemp_' . bin2hex(random_bytes(6));

        $r1 = $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'First body',
            'external_message_id' => $extMsgId,
        ]);
        $r1->assertStatus(202);
        $j1 = $this->json($r1);
        $this->assertSame('queued', $j1->data->status);

        // Only a single tracking row is kept; the second call is not queued.
        $this->assertSame(
            1,
            $this->db->table('connect_external_messages')
                ->where('external_message_id', $extMsgId)
                ->countAllResults()
        );
        $this->assertSame(
            1,
            $this->db->table('connect_message_outbox')
                ->where('external_message_id', $extMsgId)
                ->countAllResults()
        );

        // Second send with same external_message_id but different body.
        $r2 = $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Second body (ignored)',
            'external_message_id' => $extMsgId,
        ]);
        $r2->assertStatus(200);
        $j2 = $this->json($r2);
        $this->assertSame('queued', $j2->data->status);
        $this->assertNull($j2->data->humming_bird_message_id);

        // After the worker runs, both reads resolve to the SAME message id.
        $this->runWorkerOnce();
        $l1 = $this->lookupMessage($extMsgId);
        $l1->assertStatus(200);
        $l1j = $this->json($l1);
        $this->assertSame('sent', $l1j->data->status);
        $this->assertNotEmpty($l1j->data->humming_bird_message_id);

        $l2 = $this->lookupMessage($extMsgId);
        $l2j = $this->json($l2);
        $this->assertSame($l1j->data->humming_bird_message_id, $l2j->data->humming_bird_message_id);
    }

    // ------------------------------------------------------------------
    // 4. Missing external_user_id (400)
    // ------------------------------------------------------------------
    public function test04MissingExternalUserIdReturns400(): void
    {
        $response = $this->sendMessage([
            'body' => 'Hello',
        ]);

        $response->assertStatus(400);
        $json = $this->json($response);
        $this->assertFalse($json->status);
        $this->assertSame(ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 5. Invalid external_user_id (400)
    // ------------------------------------------------------------------
    public function test05InvalidExternalUserIdReturns400(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => '',
            'body'             => 'Hello',
        ]);

        $response->assertStatus(400);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 6. Unmapped external_user_id (404)
    // ------------------------------------------------------------------
    public function test06UnmappedExternalUserIdReturns404(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_unknown_' . bin2hex(random_bytes(4)),
            'body'             => 'Hello',
        ]);

        $response->assertStatus(404);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 7. Inactive HB user (422)
    // ------------------------------------------------------------------
    public function test07InactiveHbUserReturns422(): void
    {
        $now = date('Y-m-d H:i:s');
        $extId = 'ext_inactive_' . bin2hex(random_bytes(4));

        $this->db->table('connect_user_mappings')->insert([
            'tenant_id'            => $this->tenantA,
            'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'external_user_id'     => $extId,
            'humming_bird_user_id' => $this->hbInactive,
            'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
            'mapped_at'            => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], true);

        $response = $this->sendMessage([
            'external_user_id' => $extId,
            'body'             => 'Hello',
        ]);

        $response->assertStatus(422);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_HUMMING_BIRD_USER_INACTIVE, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 8. Missing body (400)
    // ------------------------------------------------------------------
    public function test08MissingBodyReturns400(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
        ]);

        $response->assertStatus(400);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MISSING_MESSAGE_BODY, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 9. Empty body (400)
    // ------------------------------------------------------------------
    public function test09EmptyBodyReturns400(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => '',
        ]);

        $response->assertStatus(400);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_INVALID_MESSAGE_BODY, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 10. Body too long (413)
    // ------------------------------------------------------------------
    public function test10BodyTooLongReturns413(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => str_repeat('x', ConnectConfig::MESSAGE_BODY_MAX_LEN + 1),
        ]);

        $response->assertStatus(413);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MESSAGE_BODY_TOO_LONG, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 11. Invalid external_message_id format (400)
    // ------------------------------------------------------------------
    public function test11InvalidExternalMessageIdReturns400(): void
    {
        $response = $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Hello',
            'external_message_id' => str_repeat('x', ConnectConfig::EXTERNAL_MESSAGE_ID_MAX_LEN + 1),
        ]);

        $response->assertStatus(400);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_INVALID_EXTERNAL_MESSAGE_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 12. No active service identity (404)
    // ------------------------------------------------------------------
    public function test12NoActiveServiceIdentityReturns404(): void
    {
        // Disable all identities for tenant A.
        $this->db->table('connect_service_identities')
            ->where('tenant_id', $this->tenantA)
            ->update(['status' => ConnectConfig::IDENTITY_STATUS_DISABLED]);

        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'Hello',
        ]);

        $response->assertStatus(404);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 13. Insufficient scope — messages:send (403)
    // ------------------------------------------------------------------
    public function test13InsufficientScopeSendReturns403(): void
    {
        $response = $this->sendMessage(
            ['external_user_id' => 'ext_user_1', 'body' => 'Hello'],
            $this->credNoScope,
            'test_noscope_0001',
        );

        $response->assertStatus(403);
        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_INSUFFICIENT_SCOPE, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 14. Insufficient scope — messages:read on lookup (403)
    // ------------------------------------------------------------------
    public function test14InsufficientScopeReadReturns403(): void
    {
        $response = $this->lookupMessage(
            'some_msg_id',
            $this->credSend,
            $this->secretSend,
        );

        // credSend has both send+read scopes, so this should NOT be 403.
        // Use credNoScope to test read-scope failure.
        $response2 = $this->lookupMessage(
            'some_msg_id',
            $this->credNoScope,
            'test_noscope_0001',
        );

        $response2->assertStatus(403);
        $json = $this->json($response2);
        $this->assertSame(ConnectConfig::ERROR_INSUFFICIENT_SCOPE, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 15. Valid lookup — 200
    // ------------------------------------------------------------------
    public function test15ValidLookupReturns200(): void
    {
        $extMsgId = 'remark_msg_lookup_' . bin2hex(random_bytes(6));

        // Send first.
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Lookup test',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        // Lookup.
        $response = $this->lookupMessage($extMsgId);
        $response->assertStatus(200);

        $json = $this->json($response);
        $this->assertTrue($json->status);
        $this->assertSame($extMsgId, $json->data->external_message_id);
        $this->assertSame((string) $this->hbUser1, $json->data->humming_bird_user_id);
        $this->assertNull($json->data->humming_bird_message_id);
        $this->assertNotEmpty($json->data->room_id);
        $this->assertSame('queued', $json->data->status);

        // After the worker runs the message id is populated.
        $this->runWorkerOnce();
        $after = $this->lookupMessage($extMsgId);
        $after->assertStatus(200);
        $afterJson = $this->json($after);
        $this->assertSame('sent', $afterJson->data->status);
        $this->assertNotEmpty($afterJson->data->humming_bird_message_id);
    }

    // ------------------------------------------------------------------
    // 16. Lookup non-existent — 404
    // ------------------------------------------------------------------
    public function test16LookupNonExistentReturns404(): void
    {
        $response = $this->lookupMessage('non_existent_msg_' . bin2hex(random_bytes(4)));
        $response->assertStatus(404);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MESSAGE_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 17. Lookup empty external_message_id — 400
    // ------------------------------------------------------------------
    public function test17LookupEmptyExternalMessageIdReturns400(): void
    {
        $response = $this->lookupMessage('');
        $response->assertStatus(400);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_INVALID_EXTERNAL_MESSAGE_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 18. Tenant isolation — message from tenant A not visible to tenant B
    // ------------------------------------------------------------------
    public function test18TenantIsolationHolds(): void
    {
        $extMsgId = 'remark_msg_iso_' . bin2hex(random_bytes(6));

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Tenant A message',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        // Lookup from tenant B should fail.
        $response = $this->lookupMessage($extMsgId, $this->credTenantB, 'test_tenant_b_001');
        $response->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // 19. Environment isolation — test message not visible in prod lookup
    // ------------------------------------------------------------------
    public function test19EnvironmentIsolationHolds(): void
    {
        $extMsgId = 'remark_msg_env_' . bin2hex(random_bytes(6));

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Test env message',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        // Lookup from prod env should fail.
        $response = $this->lookupMessage($extMsgId, $this->credProdTenantA, 'test_prod_0001');
        $response->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // 20. Room reuse — second message to same user goes to same room
    // ------------------------------------------------------------------
    public function test20RoomReuseOnSecondMessage(): void
    {
        $r1 = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'First message',
        ]);
        $r1->assertStatus(202);

        // Fetch the room_id from the tracking record.
        $row1 = $this->db->table('connect_external_messages')
            ->where('tenant_id', $this->tenantA)
            ->where('environment', ConnectConfig::TENANT_ENVIRONMENT_TEST)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $room1 = $row1['room_id'];

        $r2 = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'Second message',
        ]);
        $r2->assertStatus(202);

        $row2 = $this->db->table('connect_external_messages')
            ->where('tenant_id', $this->tenantA)
            ->where('environment', ConnectConfig::TENANT_ENVIRONMENT_TEST)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $room2 = $row2['room_id'];

        $this->assertSame($room1, $room2, 'Room should be reused for the same user pair');
    }

    // ------------------------------------------------------------------
    // 21. Bot user created per service identity
    // ------------------------------------------------------------------
    public function test21BotUserCreatedForServiceIdentity(): void
    {
        $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'Trigger bot creation',
        ])->assertStatus(202);

        $botEmail = 'connect_bot_' . $this->identityA . '@internal.myhummingbird.net';
        $bot = $this->getRow('users', 'user_email', $botEmail);

        $this->assertNotNull($bot, 'Bot user should be created');
        $this->assertSame(0, (int) $bot['u_is_deleted']);
    }

    // ------------------------------------------------------------------
    // 22. Bot user reused on second send
    // ------------------------------------------------------------------
    public function test22BotUserReusedOnSecondSend(): void
    {
        $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'First send',
        ]);
        $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'Second send',
        ])->assertStatus(202);

        $botEmail = 'connect_bot_' . $this->identityA . '@internal.myhummingbird.net';
        $count = $this->countRows('users', 'user_email', $botEmail);

        $this->assertSame(1, $count, 'Bot user should not be created twice');
    }

    // ------------------------------------------------------------------
    // 23. Audit row written for accepted message
    // ------------------------------------------------------------------
    public function test23AuditRowWrittenForAccepted(): void
    {
        $extUserId = 'ext_user_1';

        $this->sendMessage([
            'external_user_id' => $extUserId,
            'body'             => 'Audit test',
        ])->assertStatus(202);

        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('action', ConnectConfig::AUDIT_ACTION_MESSAGE_QUEUED)
            ->get()
            ->getRowArray();

        $this->assertIsArray($audit, 'Audit row should be written');
        $this->assertSame($extUserId, $audit['external_user_id']);
        $this->assertSame('success', $audit['status']);
    }

    // ------------------------------------------------------------------
    // 24. Audit row written for rejected message
    // ------------------------------------------------------------------
    public function test24AuditRowWrittenForRejected(): void
    {
        $this->sendMessage([
            'external_user_id' => 'ext_user_unknown_audit',
            'body'             => 'Rejected audit test',
        ])->assertStatus(404);

        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('action', ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED)
            ->get()
            ->getRowArray();

        $this->assertIsArray($audit, 'Audit row should be written for rejection');
        $this->assertSame('denied', $audit['status']);
    }

    // ------------------------------------------------------------------
    // 25. Audit row written for lookup
    // ------------------------------------------------------------------
    public function test25AuditRowWrittenForLookup(): void
    {
        $extMsgId = 'remark_msg_audit_lookup_' . bin2hex(random_bytes(6));

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Lookup audit test',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $this->lookupMessage($extMsgId)->assertStatus(200);

        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('action', ConnectConfig::AUDIT_ACTION_MESSAGE_LOOKUP)
            ->get()
            ->getRowArray();

        $this->assertIsArray($audit, 'Audit row should be written for lookup');
        $this->assertSame('success', $audit['status']);
    }
    // ------------------------------------------------------------------
    // 26. Secrets never leaked in send response
    // ------------------------------------------------------------------
    public function test26NoSecretsInSendResponse(): void
    {
        $response = $this->sendMessage([
            'external_user_id' => 'ext_user_1',
            'body'             => 'Secret leak test',
        ]);

        $response->assertStatus(202);
        $json = $this->json($response);
        $raw  = (string) $response->response()->getBody();

        // Must not contain encrypted body, cipher keys, or internal DB ids
        // beyond what the spec allows.
        $this->assertStringNotContainsString('myrc4secretkey', $raw);
        $this->assertStringNotContainsString('myblowfishsecretkey', $raw);
        $this->assertStringNotContainsString('mytripledeskey', $raw);
        $this->assertStringNotContainsString('12345678901234567890123456789001', $raw);
        $this->assertObjectNotHasProperty('secret', $json->data ?? new \stdClass());
        $this->assertObjectNotHasProperty('api_key', $json->data ?? new \stdClass());
        $this->assertObjectNotHasProperty('secret_hash', $json->data ?? new \stdClass());
    }

    // ------------------------------------------------------------------
    // 27. Messaging pipeline integration — message row exists in messages table
    // ------------------------------------------------------------------
    public function test27MessageRowExistsInMessagesTable(): void
    {
        $extMsgId = 'remark_msg_pipeline_' . bin2hex(random_bytes(6));

        $r = $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Pipeline integration test',
            'external_message_id' => $extMsgId,
        ]);
        $r->assertStatus(202);
        $this->assertNull($this->json($r)->data->humming_bird_message_id);

        // Phase 5: the worker turns the queued record into a real message.
        $this->runWorkerOnce();

        $lookup = $this->lookupMessage($extMsgId);
        $lookup->assertStatus(200);
        $j = $this->json($lookup);
        $this->assertSame('sent', $j->data->status);
        $hbMsgId = (int) $j->data->humming_bird_message_id;

        $msg = $this->db->table('messages')->where('m_id', $hbMsgId)->get()->getRowArray();
        $this->assertIsArray($msg, 'Message row should exist in messages table');
        $this->assertSame('0', $msg['m_message_type']);
        $this->assertSame('1', $msg['m_read_status']);
        $this->assertSame('0', $msg['m_is_scheduled']);
        $this->assertNotEmpty($msg['m_message']);

        // Verify the body can be decrypted back to plaintext.
        $cipher    = new ConnectMessageCipher();
        $decrypted = $cipher->decryptMessage($msg['m_message']);
        $this->assertSame('Pipeline integration test', $decrypted);
    }

    // ==================================================================
    // Phase 5 — durable outbox + background worker
    // ==================================================================

    // ------------------------------------------------------------------
    // 28. Durability — a queued send durably persists tracking + outbox +
    //     delivery (queued) BEFORE returning 202.
    // ------------------------------------------------------------------
    public function test28QueueDurablyPersistsOutboxAndTracking(): void
    {
        $extMsgId = 'p5_durable_' . bin2hex(random_bytes(6));

        $messagesBefore = (int) $this->db->table('messages')->countAllResults();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Durable queue',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $tracking = $this->db->table('connect_external_messages')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame('queued', $tracking['status']);
        $this->assertEmpty(trim((string) $tracking['message_id'], "'"));
        $this->assertNotEmpty($tracking['uuid']);

        $outbox = $this->db->table('connect_message_outbox')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_PENDING, $outbox['status']);
        $this->assertSame((int) $tracking['id'], (int) $outbox['connect_external_message_id']);
        $this->assertSame($tracking['uuid'], $outbox['uuid']);
        $this->assertSame(0, (int) $outbox['attempts']);
        $this->assertNotNull($outbox['available_at']);

        $delivery = $this->db->table('connect_message_deliveries')
            ->where('external_message_id', (int) $tracking['id'])->get()->getRowArray();
        $this->assertSame(ConnectConfig::DELIVERY_STATUS_QUEUED, $delivery['status']);

        // No HB message is created by the HTTP request alone.
        $this->assertSame($messagesBefore, (int) $this->db->table('messages')->countAllResults());
    }

    // ------------------------------------------------------------------
    // 29. Atomicity — a rejected queue (unmapped user) leaves NO tracking /
    //     outbox / delivery rows (no partial state).
    // ------------------------------------------------------------------
    public function test29RejectedQueueLeavesNoPartialRows(): void
    {
        $before = (int) $this->db->table('connect_external_messages')->countAllResults();

        $this->sendMessage([
            'external_user_id' => 'ext_user_unknown_' . bin2hex(random_bytes(4)),
            'body'             => 'Nope',
        ])->assertStatus(404);

        $this->assertSame($before, (int) $this->db->table('connect_external_messages')->countAllResults());
        $this->assertSame(0, (int) $this->db->table('connect_message_outbox')->countAllResults());
        $this->assertSame(0, (int) $this->db->table('connect_message_deliveries')->countAllResults());
    }

    // ------------------------------------------------------------------
    // 30. Worker — processes a queued job into a real message and completes
    //     the outbox; lookup reflects `sent`.
    // ------------------------------------------------------------------
    public function test30WorkerProcessesQueuedJob(): void
    {
        $extMsgId = 'p5_worker_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Worker turns this into a message',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $processed = $this->runWorkerOnce();
        $this->assertSame(1, $processed);

        $lookup = $this->lookupMessage($extMsgId);
        $lookup->assertStatus(200);
        $j = $this->json($lookup);
        $this->assertSame('sent', $j->data->status);
        $this->assertNotEmpty($j->data->humming_bird_message_id);

        // Exactly one NEW message row, message_id linked, outbox completed.
        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());

        $tracking = $this->db->table('connect_external_messages')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame((int) $j->data->humming_bird_message_id, (int) $tracking['message_id']);
        $this->assertSame('sent', $tracking['status']);

        $outbox = $this->db->table('connect_message_outbox')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_COMPLETED, $outbox['status']);
        $this->assertNotNull($outbox['processed_at']);
    }

    // ------------------------------------------------------------------
    // 31. Worker idempotency — running the worker again does NOT create a
    //     second message (completed jobs are skipped).
    // ------------------------------------------------------------------
    public function test31WorkerRerunIsIdempotent(): void
    {
        $extMsgId = 'p5_rerun_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Once only',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $this->runWorkerOnce();
        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());

        $this->runWorkerOnce(10);
        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());

        $lookup = $this->lookupMessage($extMsgId);
        $j = $this->json($lookup);
        $this->assertSame('sent', $j->data->status);
    }

    // ------------------------------------------------------------------
    // 32. Duplicate external_message_id — a send repeated after the worker
    //     resolves to the SAME message (no second message ever).
    // ------------------------------------------------------------------
    public function test32DuplicateAfterWorkerSingleMessage(): void
    {
        $extMsgId = 'p5_dup_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();
        $payload  = [
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Same op',
            'external_message_id' => $extMsgId,
        ];

        $this->sendMessage($payload)->assertStatus(202);
        $this->runWorkerOnce();

        // Replay the same external_message_id (200, not re-queued).
        $this->sendMessage($payload)->assertStatus(200);

        $this->assertSame(1, (int) $this->db->table('connect_external_messages')
            ->where('external_message_id', $extMsgId)->countAllResults());
        $this->assertSame(1, (int) $this->db->table('connect_message_outbox')
            ->where('external_message_id', $extMsgId)->countAllResults());
        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());
    }

    // ------------------------------------------------------------------
    // 33. Concurrency — two claimers race; only ONE wins the job (atomic
    //     compare-and-set), regardless of order.
    // ------------------------------------------------------------------
    public function test33ConcurrentClaimOnlyOneWinner(): void
    {
        $extMsgId = 'p5_claim_' . bin2hex(random_bytes(6));
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Race me',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $outbox = new ConnectOutboxService();

        // Worker A claims the single pending job.
        $claimedA = $outbox->claimOne('worker-A');
        $this->assertNotNull($claimedA);
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_PROCESSING, $claimedA['status']);
        $this->assertSame('worker-A', $claimedA['locked_by']);
        $this->assertSame(1, (int) $claimedA['attempts']);

        // Worker B sees nothing claimable — status is 'processing', not pending.
        $claimedB = $outbox->claimOne('worker-B');
        $this->assertNull($claimedB);

        // Stale recovery + re-claim works after the job is orphaned.
        $this->db->table('connect_message_outbox')
            ->where('id', (int) $claimedA['id'])
            ->set('locked_at', date('Y-m-d H:i:s', time() - 7200))
            ->update();

        $recovered = $outbox->recoverStale('worker-C', 60);
        $this->assertSame(1, $recovered);

        $claimedC = $outbox->claimOne('worker-C');
        $this->assertNotNull($claimedC);
        $this->assertSame((int) $claimedA['id'], (int) $claimedC['id']);
    }

    // ------------------------------------------------------------------
    // 34. Crash recovery — a job stuck in 'processing' past the stale
    //     timeout is recovered and processed exactly once (no duplicate).
    // ------------------------------------------------------------------
    public function test34CrashRecoveryProcessesOnce(): void
    {
        $extMsgId = 'p5_crash_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Recover me',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        // Simulate a worker that crashed mid-job: status=processing, old lock.
        $this->db->table('connect_message_outbox')
            ->set('status', ConnectConfig::OUTBOX_STATUS_PROCESSING)
            ->set('locked_at', date('Y-m-d H:i:s', time() - 3600))
            ->set('locked_by', 'crash-123')
            ->update();

        // A fresh worker pass recovers the stale job and processes it.
        $this->runWorkerOnce();

        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());
        $outbox = $this->db->table('connect_message_outbox')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_COMPLETED, $outbox['status']);
    }

    // ------------------------------------------------------------------
    // 35. Retry gate — a job that is not `available` yet is NOT claimed;
    //     once past its backoff delay it is processed.
    // ------------------------------------------------------------------
    public function test35BackoffGateWhenNotDueYet(): void
    {
        $extMsgId = 'p5_gate_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Future',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        // Push available_at into the future (simulates a backed-off retry).
        $this->db->table('connect_message_outbox')
            ->set('available_at', date('Y-m-d H:i:s', time() + 600))
            ->update();

        $outbox = new ConnectOutboxService();
        $this->assertNull($outbox->claimOne('w-due'));
        $this->assertSame($messagesBefore, (int) $this->db->table('messages')->countAllResults());

        // Once due, the job becomes claimable and processes.
        $this->db->table('connect_message_outbox')
            ->set('available_at', date('Y-m-d H:i:s', time() - 1))
            ->update();
        $this->runWorkerOnce();
        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());
    }

    // ------------------------------------------------------------------
    // 36. Backoff schedule — retryDelayForAttempt yields increasing delays
    //     and caps at the last configured value.
    // ------------------------------------------------------------------
    public function test36BackoffSchedule(): void
    {
        $outbox = new ConnectOutboxService();

        $this->assertSame(10, $outbox->retryDelayForAttempt(['attempts' => 1]));
        $this->assertSame(30, $outbox->retryDelayForAttempt(['attempts' => 2]));
        $this->assertSame(120, $outbox->retryDelayForAttempt(['attempts' => 3]));

        // Beyond the configured table, cap at the final delay.
        $last = ConnectConfig::WORKER_RETRY_DELAYS[count(ConnectConfig::WORKER_RETRY_DELAYS) - 1];
        $this->assertSame($last, $outbox->retryDelayForAttempt(['attempts' => 50]));
    }

    // ------------------------------------------------------------------
    // 37. Permanent failure — when the persisted HB user is no longer valid
    //     (or max attempts spent) the job FAILS permanently and NO message is
    //     created; lookup reports `failed`.
    // ------------------------------------------------------------------
    public function test37PermanentFailureNoMessage(): void
    {
        $extMsgId = 'p5_fail_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Will fail',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        // Make the mapped HB user unavailable BEFORE the worker runs.
        $this->db->table('connect_external_messages')
            ->where('external_message_id', $extMsgId)
            ->set('humming_bird_user_id', $this->hbInactive)
            ->update();

        $this->runWorkerOnce();

        $this->assertSame($messagesBefore, (int) $this->db->table('messages')->countAllResults());

        $outbox = $this->db->table('connect_message_outbox')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_FAILED, $outbox['status']);

        $lookup = $this->lookupMessage($extMsgId);
        $lookup->assertStatus(200);
        $j = $this->json($lookup);
        $this->assertSame('failed', $j->data->status);
    }

    // ------------------------------------------------------------------
    // 38. Socket / FCM failure — the worker dispatches socket broadcast and
    //     FCM fire-and-forget; their failure must NEVER fail the message.
    //     In the test env there is no socket (curl fails fast), so a
    //     successful completion here proves delivery failures are isolated.
    // ------------------------------------------------------------------
    public function test38SocketAndFcmFailureNeverFailsMessage(): void
    {
        $extMsgId = 'p5_socket_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Socket down',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $this->runWorkerOnce();

        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());
        $outbox = $this->db->table('connect_message_outbox')
            ->where('external_message_id', $extMsgId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_COMPLETED, $outbox['status']);
        $this->assertNull($outbox['last_error_code']);
    }

    // ------------------------------------------------------------------
    // 39. Isolation survives the worker — tenant A's job is processed only
    //     for tenant A; tenant B / other environment still cannot read it.
    // ------------------------------------------------------------------
    public function test39IsolationSurvivesWorker(): void
    {
        $extMsgId = 'p5_iso_' . bin2hex(random_bytes(6));
        $messagesBefore = (int) $this->db->table('messages')->countAllResults();
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Isolated',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $this->runWorkerOnce();

        $this->assertSame($messagesBefore + 1, (int) $this->db->table('messages')->countAllResults());

        $this->lookupMessage($extMsgId, $this->credTenantB, 'test_tenant_b_001')->assertStatus(404);
        $this->lookupMessage($extMsgId, $this->credProdTenantA, 'test_prod_0001')->assertStatus(404);

        // Worker created exactly ONE bot user for tenant A's identity.
        $botEmail = 'connect_bot_' . $this->identityA . '@internal.myhummingbird.net';
        $this->assertSame(1, $this->countRows('users', 'user_email', $botEmail));
    }
}
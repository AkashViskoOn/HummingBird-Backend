<?php

namespace Tests\Connect;

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\AddConnectMappingEnvironmentAndScopes;
use App\Database\Migrations\AddConnectPhase4MessageSchema;
use App\Database\Migrations\AddConnectPhase5Outbox;
use App\Database\Migrations\AddConnectPhase7StatusAndWebhooks;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Services\Connect\ConnectMessageProcessor;
use App\Services\Connect\ConnectMessageStatusService;
use App\Services\Connect\ConnectSecretVault;
use App\Services\Connect\ConnectSsrfGuard;
use App\Services\Connect\ConnectWebhookDeliveryService;
use App\Services\Connect\ConnectWebhookProcessor;
use App\Services\Connect\ConnectWebhookSigner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 7 feature tests.
 *
 * Covers message status ledger + status API + webhook management + webhook
 * event dispatch + delivery outbox (claim / retry / fail / recover) +
 * signature + SSRF guard + tenant/environment isolation + audit.
 *
 * Follows the ConnectMessageFeatureTest harness: migrations are run/dropped in
 * setUp, credentials are seeded with scopes, requests are signed through the
 * same ConnectAuthenticationService helpers. `messages`/`rooms`/`users` stubs
 * carry `CREATE TABLE IF NOT EXISTS` + tearDown drops, so every count
 * assertion is relative (before/after deltas).
 */
class ConnectStatusFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SECRET_ENV = 'test-connect-vault-key-0123456789abcdef';

    private CreateHummingBirdConnectFoundation $foundation;
    private AddConnectApiKeyAndScopes          $phase2Migration;
    private AddConnectMappingEnvironmentAndScopes $phase3Migration;
    private AddConnectPhase4MessageSchema      $phase4Migration;
    private AddConnectPhase5Outbox             $phase5Migration;
    private AddConnectPhase7StatusAndWebhooks  $phase6Migration;
    private ConnectSecretVault                 $vault;

    private int $tenantA;
    private int $tenantB;

    private string $credSend;      // hb_test_* tenant A: messages:send + messages:read
    private string $credStatus;    // hb_test_* tenant A: messages:status + messages:send
    private string $credWebhook;   // hb_test_* tenant A: webhooks:manage
    private string $credNoScope;   // hb_test_* tenant A: no scopes
    private string $credTenantB;   // hb_test_* tenant B: webhooks:manage + messages:send
    private string $secretSend;
    private string $secretStatus;
    private string $secretWebhook;

    private int $hbUser1;
    private int $hbUser2;

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
        require_once APPPATH
            . 'Database/Migrations/2026-09-14-000001_AddConnectPhase7StatusAndWebhooks.php';

        $this->foundation      = new CreateHummingBirdConnectFoundation();
        $this->phase2Migration = new AddConnectApiKeyAndScopes();
        $this->phase3Migration = new AddConnectMappingEnvironmentAndScopes();
        $this->phase4Migration = new AddConnectPhase4MessageSchema();
        $this->phase5Migration = new AddConnectPhase5Outbox();
        $this->phase6Migration = new AddConnectPhase7StatusAndWebhooks();

        $this->foundation->down();
        $this->foundation->up();
        $this->phase2Migration->up();
        $this->phase3Migration->up();
        $this->phase4Migration->up();
        $this->phase5Migration->down();
        $this->phase5Migration->up();
        $this->phase6Migration->up();

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
        $this->dropTable('connect_message_status');

        if (isset($this->foundation)) {
            $this->foundation->down();
        }

        putenv('CONNECT_DEBUG');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Minimal HB table stubs (mirrors ConnectMessageFeatureTest)
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

        $this->db->table('connect_service_identities')->insert([
            'tenant_id'           => $this->tenantA,
            'name'                => 'remark',
            'display_name'        => 'Remark',
            'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
            'status'              => ConnectConfig::IDENTITY_STATUS_ACTIVE,
            'created_at'          => $now,
            'updated_at'          => $now,
        ], true);

        $this->db->table('connect_service_identities')->insert([
            'tenant_id'           => $this->tenantB,
            'name'                => 'remark-b',
            'display_name'        => 'Remark B',
            'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
            'status'              => ConnectConfig::IDENTITY_STATUS_ACTIVE,
            'created_at'          => $now,
            'updated_at'          => $now,
        ], true);

        // --- credentials -------------------------------------------------
        $this->secretSend = 'test_secret_send_0001';
        $this->credSend = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credSend,
            $this->secretSend,
            $this->tenantA,
            [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        $this->secretStatus = 'test_secret_status_01';
        $this->credStatus = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credStatus,
            $this->secretStatus,
            $this->tenantA,
            [ConnectConfig::SCOPE_MESSAGES_STATUS, ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        $this->secretWebhook = 'test_secret_webhook_1';
        $this->credWebhook = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credWebhook,
            $this->secretWebhook,
            $this->tenantA,
            [ConnectConfig::SCOPE_WEBHOOKS_MANAGE],
        );

        $this->credNoScope = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credNoScope,
            'test_noscope_0001',
            $this->tenantA,
            [],
        );

        $this->credTenantB = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credTenantB,
            'test_tenant_b_001',
            $this->tenantB,
            [ConnectConfig::SCOPE_WEBHOOKS_MANAGE, ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_STATUS, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        // --- HB users ----------------------------------------------------
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

        // --- user mappings ------------------------------------------------
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

    private function insertCredential(string $apiKey, string $plainSecret, int $tenantId, array $scopes): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('connect_api_credentials')->insert([
            'tenant_id'     => $tenantId,
            'credential_id' => 'hb_cred_' . bin2hex(random_bytes(8)),
            'api_key'       => $apiKey,
            'key_prefix'    => ConnectConfig::keyPrefixFor(ConnectConfig::TENANT_ENVIRONMENT_TEST),
            'secret_hash'   => $this->vault->seal($plainSecret),
            'environment'   => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'status'        => ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
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
        string $queryExtra = '',
    ): TestResponse {
        $ts   = time();
        $rid  = \App\Services\Connect\ConnectAuthenticationService::requestId();
        $sign = \App\Services\Connect\ConnectAuthenticationService::sign($method, $path, (string) $ts, $rid, $body, $secret);

        $url = $path;
        if ($queryExtra !== '') {
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
    // Convenience wrappers
    // ------------------------------------------------------------------

    private function sendMessage(array $body, string $apiKey = '', string $secret = ''): TestResponse
    {
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

    private function createWebhook(array $body, string $apiKey = '', string $secret = ''): TestResponse
    {
        $apiKey = $apiKey ?: $this->credWebhook;
        $secret = $secret ?: $this->secretWebhook;

        return $this->connectRequest(
            $apiKey,
            $secret,
            'POST',
            '/api/v1/connect/webhooks',
            json_encode($body),
        );
    }

    private function listWebhooks(string $apiKey = '', string $secret = ''): TestResponse
    {
        $apiKey = $apiKey ?: $this->credWebhook;
        $secret = $secret ?: $this->secretWebhook;

        return $this->connectRequest($apiKey, $secret, 'GET', '/api/v1/connect/webhooks');
    }

    private function getWebhook(int $id, string $apiKey = '', string $secret = ''): TestResponse
    {
        $apiKey = $apiKey ?: $this->credWebhook;
        $secret = $secret ?: $this->secretWebhook;

        return $this->connectRequest($apiKey, $secret, 'GET', '/api/v1/connect/webhooks/' . $id);
    }

    private function patchWebhook(int $id, array $body, string $apiKey = '', string $secret = ''): TestResponse
    {
        $apiKey = $apiKey ?: $this->credWebhook;
        $secret = $secret ?: $this->secretWebhook;

        return $this->connectRequest(
            $apiKey,
            $secret,
            'PATCH',
            '/api/v1/connect/webhooks/' . $id,
            json_encode($body),
        );
    }

    private function deleteWebhook(int $id, string $apiKey = '', string $secret = ''): TestResponse
    {
        $apiKey = $apiKey ?: $this->credWebhook;
        $secret = $secret ?: $this->secretWebhook;

        return $this->connectRequest($apiKey, $secret, 'DELETE', '/api/v1/connect/webhooks/' . $id);
    }

    private function testWebhook(int $id, string $apiKey = '', string $secret = ''): TestResponse
    {
        $apiKey = $apiKey ?: $this->credWebhook;
        $secret = $secret ?: $this->secretWebhook;

        return $this->connectRequest($apiKey, $secret, 'POST', '/api/v1/connect/webhooks/' . $id . '/test');
    }

    private function statusFor(string $externalMessageId, string $apiKey = '', string $secret = '', string $pathTpl = '/api/v1/connect/messages/status'): TestResponse
    {
        $apiKey = $apiKey ?: $this->credStatus;
        $secret = $secret ?: $this->secretStatus;

        return $this->connectRequest($apiKey, $secret, 'GET', $pathTpl, '', 'external_message_id=' . rawurlencode($externalMessageId));
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

    /**
     * Run the Phase 5 message worker once (socket/FCM failures are harmless).
     */
    private function runMessageWorker(int $batchSize = 50): int
    {
        return (new ConnectMessageProcessor())->run($batchSize);
    }

    // ------------------------------------------------------------------
    // Webhook registration & management API
    // ------------------------------------------------------------------

    public function test01WebhookCreateReturnsSecretOnce(): void
    {
        $response = $this->createWebhook([
            'url'      => 'https://example.com/hb/hook',
            'secret'   => 'my_super_secret_123456789012345',
            'events'   => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT, ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ],
            'status'   => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'name'     => 'remark-hook',
            'description' => 'Remark status hook',
        ]);

        $response->assertStatus(201);
        $json = $this->json($response);
        $this->assertTrue($json->status);
        $this->assertSame('my_super_secret_123456789012345', $json->data->secret);
        $this->assertNotEmpty($json->data->webhook->uuid);
        $this->assertSame(ConnectConfig::TENANT_ENVIRONMENT_TEST, $json->data->webhook->environment);
        $this->assertSame('remark-hook', $json->data->webhook->name);

        // Secret is sealed at rest (vault blob) — never stored in plaintext.
        $row = $this->db->table('connect_webhooks')
            ->where('id', (int) $json->data->webhook->id)
            ->get()
            ->getRowArray();
        $this->assertNotSame('my_super_secret_123456789012345', $row['secret_hash']);
        $this->assertStringContainsString(':', $row['secret_hash']);

        // GET must NEVER reveal the secret again.
        $get = $this->getWebhook((int) $json->data->webhook->id);
        $getJson = $this->json($get);
        $this->assertObjectNotHasProperty('secret', $getJson->data->webhook);
        $this->assertObjectNotHasProperty('secret_hash', $getJson->data->webhook);
    }

    public function test02WebhookCreateRejectsSsrfTargets(): void
    {
        $blocked = [
            'http://localhost:4000/hook',
            'http://127.0.0.1:8080/hook',
            'http://10.0.0.5/hook',
            'http://192.168.1.1/hook',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]:443/hook',
            'http://internal.local/cb',
            'http://myhost/cb',
            'ftp://example.com/hook',
            'http://example.com:9999/hook',
        ];

        foreach ($blocked as $url) {
            $response = $this->createWebhook([
                'url'    => $url,
                'secret' => 'aaabbbcccdddeeefffggghhh',
                'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
            ]);
            $response->assertStatus(422);
            $this->assertFalse($this->json($response)->status);
        }

        // No webhooks were created.
        $this->assertSame(0, (int) $this->db->table('connect_webhooks')->countAllResults());
    }

    public function test03WebhookCreateRejectsUnknownEvent(): void
    {
        $response = $this->createWebhook([
            'url'    => 'https://example.com/hb/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => ['message.disappeared'],
        ]);

        $response->assertStatus(400);
        $this->assertSame(ConnectConfig::ERROR_WEBHOOK_INVALID_EVENTS, $this->json($response)->error->code);
    }

    public function test04WebhookCreateRejectsShortSecret(): void
    {
        $response = $this->createWebhook([
            'url'    => 'https://example.com/hb/hook',
            'secret' => 'short',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
        ]);

        $response->assertStatus(400);
        $this->assertSame(ConnectConfig::ERROR_WEBHOOK_SECRET_TOO_SHORT, $this->json($response)->error->code);
    }

    public function test05WebhookListIsScopedAndEnvironmentFiltered(): void
    {
        $a1 = $this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
            'name'   => 'a-one',
        ]);
        $aJson = $this->json($a1);

        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantB,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'https://b.example.com/hook',
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], true);

        $list = $this->listWebhooks();
        $list->assertStatus(200);
        $data = $this->json($list)->data;

        $this->assertCount(1, $data->webhooks);
        $this->assertSame((int) $aJson->data->webhook->id, (int) $data->webhooks[0]->id);
    }

    public function test06WebhookGetCrossTenantIsolation(): void
    {
        $created = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
        ]));

        // Tenant B (credTenantB) tries to read tenant A's webhook row.
        $response = $this->getWebhook((int) $created->data->webhook->id, $this->credTenantB, 'test_tenant_b_001');
        $response->assertStatus(404);
        $this->assertSame(ConnectConfig::ERROR_WEBHOOK_NOT_FOUND, $this->json($response)->error->code);
    }

    public function test07WebhookUpdateChangesUrlEventsAndDescription(): void
    {
        $created = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
        ]));
        $id = (int) $created->data->webhook->id;

        $updated = $this->patchWebhook($id, [
            'url'         => 'https://new.example.com/hook/v2',
            'events'      => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT, ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ],
            'status'      => ConnectConfig::WEBHOOK_STATUS_DISABLED,
            'description' => 'updated',
        ]);
        $updated->assertStatus(200);

        $row = $this->db->table('connect_webhooks')->where('id', $id)->get()->getRowArray();
        $this->assertSame('https://new.example.com/hook/v2', $row['url']);
        $this->assertSame(ConnectConfig::WEBHOOK_STATUS_DISABLED, $row['status']);
        $this->assertSame('updated', $row['description']);
        $events = json_decode((string) $row['events'], true);
        $this->assertContains(ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ, $events);
    }

    public function test08WebhookDeleteRemovesRow(): void
    {
        $created = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
        ]));
        $id = (int) $created->data->webhook->id;

        $del = $this->deleteWebhook($id);
        $del->assertStatus(200);
        $this->assertTrue($this->json($del)->data->deleted);

        $this->assertNull($this->getRow('connect_webhooks', 'id', $id));

        $reget = $this->getWebhook($id);
        $reget->assertStatus(404);
    }

    public function test09WebhookTestEnqueuesWebhookTestDelivery(): void
    {
        $created = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
        ]));
        $id = (int) $created->data->webhook->id;

        $test = $this->testWebhook($id);
        $test->assertStatus(202);
        $data = $this->json($test)->data;
        $this->assertSame(ConnectConfig::WEBHOOK_EVENT_WEBHOOK_TEST, $data->test_event);

        $delivery = $this->db->table('connect_webhook_deliveries')
            ->where('webhook_id', $id)
            ->where('event_type', ConnectConfig::WEBHOOK_EVENT_WEBHOOK_TEST)
            ->get()
            ->getRowArray();
        $this->assertNotEmpty($delivery);
        $this->assertSame(ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING, $delivery['status']);
        $this->assertSame(0, (int) $delivery['attempt']);
        // Payload stored for later signature; hash kept.
        $this->assertNotEmpty($delivery['payload']);
        $this->assertSame(hash('sha256', (string) $delivery['payload']), $delivery['payload_hash']);
    }

    // ------------------------------------------------------------------
    // Status ledger + status API
    // ------------------------------------------------------------------

    public function test10MessageLifecycleRecordsLedgerAndSendsWebhook(): void
    {
        // Webhook subscribed to queued + sent.
        $wh = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [
                ConnectConfig::WEBHOOK_EVENT_MESSAGE_QUEUED,
                ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
                ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ,
            ],
        ]));
        $webhookId = (int) $wh->data->webhook->id;

        $extMsgId = 'st_' . bin2hex(random_bytes(6));
        $sent = $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'hello status ledger',
            'external_message_id' => $extMsgId,
        ]);
        $sent->assertStatus(202);

        // Queue-time ledger: accepted + queued recorded once each.
        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_ACCEPTED));
        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_QUEUED));

        // Queue-time webhook deliveries (best-effort) are queued for the
        // subscribed events the message touched.
        $this->assertSame(
            1,
            $this->countRows('connect_webhook_deliveries', 'event_type', ConnectConfig::WEBHOOK_EVENT_MESSAGE_QUEUED),
        );

        // Now run the worker → processing + sent.
        $this->runMessageWorker(50);

        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_PROCESSING));
        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_SENT));
        $this->assertSame(
            1,
            $this->countRows('connect_webhook_deliveries', 'event_type', ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT),
        );

        // Each delivery is scoped to the webhook.
        $deliveryRows = $this->db->table('connect_webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->whereIn('event_type', [ConnectConfig::WEBHOOK_EVENT_MESSAGE_QUEUED, ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT])
            ->get()
            ->getResultArray();
        $this->assertCount(2, $deliveryRows);

        // Status API returns sent (+ the full ledger as events).
        $status = $this->statusFor($extMsgId);
        $status->assertStatus(200);
        $s = $this->json($status)->data;
        $this->assertSame('sent', $s->status);
        $this->assertNotEmpty($s->humming_bird_message_id);

        $statuses = array_column($s->events, 'status');
        $this->assertContains(ConnectConfig::MESSAGE_STATUS_ACCEPTED, $statuses);
        $this->assertContains(ConnectConfig::MESSAGE_STATUS_QUEUED, $statuses);
        $this->assertContains(ConnectConfig::MESSAGE_STATUS_PROCESSING, $statuses);
        $this->assertContains(ConnectConfig::MESSAGE_STATUS_SENT, $statuses);
    }

    public function test11StatusApiRequiresScope(): void
    {
        $extMsgId = 'scope_gate_' . bin2hex(random_bytes(6));

        // Queue a message first so the status exists.
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'scope gate',
            'external_message_id' => $extMsgId,
        ]);
        $this->runMessageWorker(50);

        // No-scope credential is rejected by the filter before the controller.
        $denied = $this->statusFor($extMsgId, $this->credNoScope, 'test_noscope_0001');
        $denied->assertStatus(403);
    }

    public function test12ReadReceiptRecordsDeliveredAndReadWebhooks(): void
    {
        $wh = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [
                ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
                ConnectConfig::WEBHOOK_EVENT_MESSAGE_DELIVERED,
                ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ,
            ],
        ]));

        $extMsgId = 'rr_' . bin2hex(random_bytes(6));
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'read me',
            'external_message_id' => $extMsgId,
        ]);
        $this->runMessageWorker(50);

        $tracking = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $this->assertNotEmpty($tracking);
        $hbMessageId = (int) $tracking['message_id'];
        $this->assertGreaterThan(0, $hbMessageId);

        // The read hook is the ONLY producer of delivered/read. Try the worker
        // statuses first — they must NOT be accepted through recordFromMessageId.
        $service = new ConnectMessageStatusService();
        $this->assertSame(0, $service->recordFromMessageId($hbMessageId, ConnectConfig::MESSAGE_STATUS_SENT, 'nope'));

        // Recipient device reports delivered (2) then read (3).
        $service->recordFromMessageId($hbMessageId, ConnectConfig::MESSAGE_STATUS_DELIVERED, 'delivery hook');

        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_DELIVERED));
        $this->assertSame(
            1,
            $this->countRows('connect_webhook_deliveries', 'event_type', ConnectConfig::WEBHOOK_EVENT_MESSAGE_DELIVERED),
        );

        $service->recordFromMessageId($hbMessageId, ConnectConfig::MESSAGE_STATUS_READ, 'read hook');

        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_READ));
        $this->assertSame(
            1,
            $this->countRows('connect_webhook_deliveries', 'event_type', ConnectConfig::WEBHOOK_EVENT_MESSAGE_READ),
        );

        // Status API now reports read as current status.
        $status = $this->json($this->statusFor($extMsgId));
        $this->assertSame('read', $status->data->status);

        // Re-recording the same transition is idempotent.
        $service->recordFromMessageId($hbMessageId, ConnectConfig::MESSAGE_STATUS_READ, 'again');
        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_READ));
    }

    public function test13StatusHistoryEndpoint(): void
    {
        $extMsgId = 'hist_' . bin2hex(random_bytes(6));
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'history please',
            'external_message_id' => $extMsgId,
        ]);
        $this->runMessageWorker(50);

        $response = $this->connectRequest(
            $this->credStatus,
            $this->secretStatus,
            'GET',
            '/api/v1/connect/messages/status/' . rawurlencode($extMsgId) . '/events',
        );
        $response->assertStatus(200);
        $data = $this->json($response)->data;
        $this->assertSame($extMsgId, $data->external_message_id);

        $statuses = array_column($data->events, 'status');
        $this->assertContains('accepted', $statuses);
        $this->assertContains('queued', $statuses);
        $this->assertContains('processing', $statuses);
        $this->assertContains('sent', $statuses);
    }

    public function test14StatusApiNotFoundIsolated(): void
    {
        $response = $this->statusFor('does_not_exist_123');
        $response->assertStatus(404);
        $this->assertSame(ConnectConfig::ERROR_STATUS_NOT_FOUND, $this->json($response)->error->code);
    }

    public function test15DuplicateSendLedgerIdempotent(): void
    {
        $extMsgId = 'dup_' . bin2hex(random_bytes(6));

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'first',
            'external_message_id' => $extMsgId,
        ]);
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'second (dup)',
            'external_message_id' => $extMsgId,
        ]);

        // Only one tracking row + one queued/absence of duplicate ledger rows.
        $this->assertSame(1, $this->countRows('connect_external_messages', 'external_message_id', $extMsgId));
        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_ACCEPTED));
        $this->assertSame(1, $this->countLedger($extMsgId, ConnectConfig::MESSAGE_STATUS_QUEUED));
    }

    // ------------------------------------------------------------------
    // Delivery outbox — claim / retry / fail
    // ------------------------------------------------------------------

    public function test16WebhookDeliveryRetriesThenFailsDeadEndpoint(): void
    {
        // Insert webhook directly with an unreachable URL (the SSRF guard only
        // runs on the management API, never on dispatch — so we can exercise
        // the delivery worker against a dead endpoint deterministically).
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantA,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'http://127.0.0.1:1/dead',
            'secret_hash' => $this->vault->seal('aaabbbcccdddeeefffggghhh'),
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $webhookId = (int) $this->db->insertID();

        $deliveryService = new ConnectWebhookDeliveryService();
        $deliveryService->enqueue(
            $webhookId,
            ['id' => $webhookId, 'tenant_id' => $this->tenantA, 'uuid' => 'wbx', 'environment' => 'test'],
            ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
            uniqid('evt_', true),
            ['id' => 'evt', 'type' => 'message.sent', 'data' => ['x' => 1]],
            $now,
        );

        $delivery = $this->db->table('connect_webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->get()
            ->getRowArray();
        $this->assertNotEmpty($delivery);
        $deliveryId = (int) $delivery['id'];
        $initialEventId = (string) $delivery['event_id'];

        $processor = new ConnectWebhookProcessor();
        $maxAttempts = (int) (config(ConnectConfig::class)->webhookWorkerMaxAttempts);

        $processed = 0;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $processed += $processor->run(10);
            // Rewind the backoff so the next pass can claim it again.
            $this->db->table('connect_webhook_deliveries')
                ->where('id', $deliveryId)
                ->set('next_retry_at', date('Y-m-d H:i:s'))
                ->update();
        }

        $this->assertGreaterThan(0, $processed);

        $final = $this->db->table('connect_webhook_deliveries')->where('id', $deliveryId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED, $final['status']);
        $this->assertNotEmpty($final['last_error_code']);
        $this->assertStringContainsString('HTTP 0', (string) $final['last_error']);
        $this->assertSame($maxAttempts, (int) $final['attempt']);

        // Exactly one delivery row survived every retry, and the retries reused
        // the same event id (a consumer sees a byte-identical, idempotent event).
        $this->assertSame(1, $this->countRows('connect_webhook_deliveries', 'webhook_id', $webhookId));
        $rows = $this->db->table('connect_webhook_deliveries')->where('webhook_id', $webhookId)->get()->getResultArray();
        $this->assertSame($initialEventId, $rows[0]['event_id']);

        // The webhook was marked failing (last_failure_at) but not succeeded.
        $wb = $this->db->table('connect_webhooks')->where('id', $webhookId)->get()->getRowArray();
        $this->assertNotEmpty($wb['last_failure_at']);
        $this->assertEmpty($wb['last_success_at']);

        // Audit trail records one failed-attempt entry per delivery attempt.
        $this->assertSame(
            $maxAttempts,
            $this->countRows('connect_api_audit_logs', 'action', ConnectConfig::AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED),
        );
    }

    public function test17WebhookDeliveryMarkDeliveredUpdatesWebhook(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantA,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'https://example.com/hb/hook',
            'secret_hash' => $this->vault->seal('aaabbbcccdddeeefffggghhh'),
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $webhookId = (int) $this->db->insertID();

        $deliveryService = new ConnectWebhookDeliveryService();
        $deliveryService->enqueue(
            $webhookId,
            ['id' => $webhookId, 'tenant_id' => $this->tenantA, 'uuid' => 'wbx', 'environment' => 'test'],
            ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
            uniqid('evt_', true),
            ['id' => 'evt', 'type' => 'message.sent', 'data' => []],
            $now,
        );

        $delivery = $this->db->table('connect_webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->get()
            ->getRowArray();
        $deliveryId = (int) $delivery['id'];

        $deliveryService->markDelivered($deliveryId, 200, 42, $webhookId, $this->tenantA);

        $final = $this->db->table('connect_webhook_deliveries')->where('id', $deliveryId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED, $final['status']);
        $this->assertSame(200, (int) $final['http_status']);
        $this->assertSame(42, (int) $final['response_time_ms']);
        $this->assertNotEmpty($final['delivered_at']);

        $wb = $this->db->table('connect_webhooks')->where('id', $webhookId)->get()->getRowArray();
        $this->assertNotEmpty($wb['last_success_at']);
    }

    public function test18WebhookDispatchSkipsDisabledWebhook(): void
    {
        $created = $this->json($this->createWebhook([
            'url'     => 'https://a.example.com/hook',
            'secret'  => 'aaabbbcccdddeeefffggghhh',
            'events'  => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
            'status'  => ConnectConfig::WEBHOOK_STATUS_DISABLED,
        ]));
        $this->assertSame(ConnectConfig::WEBHOOK_STATUS_DISABLED, $created->data->webhook->status);

        $extMsgId = 'disabled_wb_' . bin2hex(random_bytes(6));
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'disabled webhook test',
            'external_message_id' => $extMsgId,
        ]);
        $this->runMessageWorker(50);

        // No delivery rows produced for the disabled webhook.
        $this->assertSame(
            0,
            (int) $this->db->table('connect_webhook_deliveries')
                ->where('webhook_id', (int) $created->data->webhook->id)
                ->countAllResults(),
        );
    }

    // ------------------------------------------------------------------
    // Signature + SSRF guard (pure units)
    // ------------------------------------------------------------------

    public function test19WebhookSignerRoundTrips(): void
    {
        $signer = new ConnectWebhookSigner();
        $secret = 'aaabbbcccdddeeefffggghhh';
        $body   = '{"a":1,"b":"text"}';
        $ts     = (string) time();

        $sig = $signer->sign($secret, $ts, $body);
        $this->assertStringStartsWith('t=' . $ts . ',v1=', $sig);

        $this->assertTrue($signer->verify($secret, $ts, $body, $sig));
        $this->assertFalse($signer->verify($secret, $ts, $body . 'x', $sig)); // tampered body
        $this->assertFalse($signer->verify('different-secret', $ts, $body, $sig)); // wrong secret
        $this->assertFalse($signer->verify($secret, (string) (time() - 2000), $body, $sig)); // stale timestamp

        $this->assertTrue((new ConnectWebhookSigner())->isFresh($ts));
        $this->assertFalse((new ConnectWebhookSigner())->isFresh((string) (time() - 2000)));
        $this->assertFalse((new ConnectWebhookSigner())->isFresh('not-a-number'));
    }

    public function test20SsrfGuardAllowsPublicUrls(): void
    {
        $guard = new ConnectSsrfGuard();

        $this->assertNull($guard->check('https://hooks.example.com/callback'));
        $this->assertNull($guard->check('https://example.com/path?q=1#frag'));
        $this->assertNull($guard->check('http://8.8.8.8/cb'));
        $this->assertNull($guard->check('https://example.com:443/hook'));
    }

    public function test21SsrfGuardBlocksPrivateAndSpecialHosts(): void
    {
        $guard = new ConnectSsrfGuard();

        foreach ([
            'https://localhost/x',
            'http://127.0.0.1/x',
            'http://0.0.0.0/x',
            'http://10.1.2.3/x',
            'http://172.16.0.1/x',
            'http://192.168.0.1/x',
            'http://169.254.169.254/latest/meta-data',
            'http://[::1]:443/x',
            'http://[fc00::1]/x',
            'http://metadata.google.internal/latest',
            'http://internal.lan/x',
            'http://singlelabel/x',
            'ftp://example.com/x',
            'http://example.com:8080/x',
            'http://user:pass@example.com/x',
        ] as $bad) {
            $this->assertNotNull($guard->check($bad), 'expected blocked: ' . $bad);
        }
    }

    // ------------------------------------------------------------------
    // Audit
    // ------------------------------------------------------------------

    public function test22AuditTrailWrittenForWebhookAndStatusActions(): void
    {
        $wh = $this->json($this->createWebhook([
            'url'    => 'https://a.example.com/hook',
            'secret' => 'aaabbbcccdddeeefffggghhh',
            'events' => [ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT],
        ]));

        $this->assertSame(
            1,
            $this->countRows('connect_api_audit_logs', 'action', ConnectConfig::AUDIT_ACTION_WEBHOOK_CREATED),
        );

        $extMsgId = 'audit_' . bin2hex(random_bytes(6));
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'audit trail',
            'external_message_id' => $extMsgId,
        ]);
        $this->runMessageWorker(50);

        $this->statusFor($extMsgId);
        $this->assertSame(
            1,
            $this->countRows('connect_api_audit_logs', 'action', ConnectConfig::AUDIT_ACTION_MESSAGE_STATUS_LOOKUP),
        );

        // Audit rows never leak the webhook secret.
        $createdAudit = $this->db->table('connect_api_audit_logs')
            ->where('action', ConnectConfig::AUDIT_ACTION_WEBHOOK_CREATED)
            ->get()
            ->getRowArray();
        $joined = json_encode($createdAudit);
        $this->assertStringNotContainsString('aaabbbcccdddeeefffggghhh', $joined);
    }

    // ------------------------------------------------------------------
    // Worker recover-stale + claim idempotency
    // ------------------------------------------------------------------

    public function test23WebhookWorkerRecoversStaleProcessingDeliveries(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantA,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'http://127.0.0.1:1/stale',
            'secret_hash' => $this->vault->seal('aaabbbcccdddeeefffggghhh'),
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $webhookId = (int) $this->db->insertID();

        $staleUntil = (new \DateTime())->modify('-600 seconds')->format('Y-m-d H:i:s');

        $this->db->table('connect_webhook_deliveries')->insert([
            'webhook_id'    => $webhookId,
            'webhook_uuid'  => 'wbx',
            'tenant_id'     => $this->tenantA,
            'environment'   => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'uuid'          => uniqid('wbdel_', true),
            'event_id'      => uniqid('evt_', true),
            'event_type'    => ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
            'payload_hash'  => hash('sha256', '{}'),
            'payload'       => '{"x":1}',
            'status'        => ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING,
            'attempt'       => 3,
            'locked_at'     => $staleUntil,
            'locked_by'     => 'hb-worker-webhook-dead',
            'next_retry_at' => $staleUntil,
            'created_at'    => $staleUntil,
            'updated_at'    => $staleUntil,
        ], true);

        $processor = new ConnectWebhookProcessor();
        $processor->run(10);

        $row = $this->db->table('connect_webhook_deliveries')
            ->where('webhook_id', $webhookId)
            ->get()
            ->getRowArray();

        // Recovery returned the stale processing row to retrying (then the
        // claim+dead endpoint will push it to failed or retrying). At minimum
        // it can no longer be stuck in 'processing'.
        $this->assertNotSame(ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING, $row['status']);
        $this->assertNull($row['locked_by']);
    }

    public function test24WebhookPermanent4xxFailsImmediately(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantA,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'https://example.com/hb/hook',
            'secret_hash' => $this->vault->seal('aaabbbcccdddeeefffggghhh'),
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $webhookId = (int) $this->db->insertID();

        $deliveryService = new ConnectWebhookDeliveryService();
        $deliveryService->enqueue(
            $webhookId,
            ['id' => $webhookId, 'tenant_id' => $this->tenantA, 'uuid' => 'wbx', 'environment' => 'test'],
            ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
            uniqid('evt_', true),
            ['id' => 'evt', 'type' => 'message.sent', 'data' => ['x' => 1]],
            $now,
        );

        // Simulate a permanent 400 (bad signature) from the endpoint.
        $processor = new class() extends ConnectWebhookProcessor {
            public function post(string $url, string $secret, string $rawBody, string $eventType, string $eventId): array
            {
                return [400, 2, null];
            }
        };
        $processor->run(10);

        $final = $this->db->table('connect_webhook_deliveries')->where('webhook_id', $webhookId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED, $final['status']);
        $this->assertSame(1, (int) $final['attempt']);
        $this->assertSame('CONNECT_WEBHOOK_HTTP_400', $final['last_error_code']);
        $this->assertEmpty($final['next_retry_at']);
    }

    public function test25Webhook429IsRetriedWithBackoff(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantA,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'https://example.com/hb/hook',
            'secret_hash' => $this->vault->seal('aaabbbcccdddeeefffggghhh'),
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $webhookId = (int) $this->db->insertID();

        $deliveryService = new ConnectWebhookDeliveryService();
        $deliveryService->enqueue(
            $webhookId,
            ['id' => $webhookId, 'tenant_id' => $this->tenantA, 'uuid' => 'wbx', 'environment' => 'test'],
            ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT,
            uniqid('evt_', true),
            ['id' => 'evt', 'type' => 'message.sent', 'data' => ['x' => 1]],
            $now,
        );

        // 429 (temporary rate-limit) must be retried, honoring Retry-After.
        $processor = new class() extends ConnectWebhookProcessor {
            public function post(string $url, string $secret, string $rawBody, string $eventType, string $eventId): array
            {
                return [429, 2, 3];
            }
        };
        $processor->run(10);

        $final = $this->db->table('connect_webhook_deliveries')->where('webhook_id', $webhookId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING, $final['status']);
        $this->assertSame(1, (int) $final['attempt']);
        $this->assertSame('CONNECT_WEBHOOK_HTTP_429', $final['last_error_code']);
        $this->assertNotEmpty($final['next_retry_at']);
    }

    public function test26WebhookEnqueueIsIdempotentPerEvent(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_webhooks')->insert([
            'tenant_id'   => $this->tenantA,
            'uuid'        => uniqid('wb_', true),
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'url'         => 'https://example.com/hb/hook',
            'secret_hash' => $this->vault->seal('aaabbbcccdddeeefffggghhh'),
            'events'      => json_encode([ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT]),
            'status'      => ConnectConfig::WEBHOOK_STATUS_ACTIVE,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $webhookId = (int) $this->db->insertID();

        $eventUuid = uniqid('evt_', true);
        $webhookRow = ['id' => $webhookId, 'tenant_id' => $this->tenantA, 'uuid' => 'wbx', 'environment' => 'test'];
        $payload = ['id' => $eventUuid, 'type' => 'message.sent', 'data' => ['x' => 1]];

        $service = new ConnectWebhookDeliveryService();
        $this->assertTrue($service->enqueue($webhookId, $webhookRow, ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT, $eventUuid, $payload, $now));
        $this->assertFalse($service->enqueue($webhookId, $webhookRow, ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT, $eventUuid, $payload, $now));

        // Exactly one delivery row per event — no duplicate webhook fire.
        $this->assertSame(1, $this->countRows('connect_webhook_deliveries', 'webhook_id', $webhookId));

        $row = $this->db->table('connect_webhook_deliveries')->where('webhook_id', $webhookId)->get()->getRowArray();
        $this->assertSame(ConnectConfig::WEBHOOK_EVENT_MESSAGE_SENT, $row['event_type']);
        $this->assertSame($eventUuid, $row['event_id']);
        $this->assertSame(hash('sha256', (string) $row['payload']), $row['payload_hash']);
    }

    public function test27CleanupPurgesOnlyExpiredTerminalDeliveries(): void
    {
        $now   = date('Y-m-d H:i:s');
        $old   = date('Y-m-d H:i:s', strtotime('-40 days'));

        $webhookRow = ['tenant_id' => $this->tenantA, 'uuid' => 'wb-clean', 'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST];
        $service    = new ConnectWebhookDeliveryService();

        $payload = ['type' => 'webhook.test', 'data' => ['x' => 1]];

        // Seed one of each status, an old pair and a fresh pair.
        $seed = [
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING,  null],
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING, null],
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING, null],
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED, $old],
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,    $old],
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED, $now],
            [ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED,    $now],
        ];

        foreach ($seed as $i => [$status, $updatedAt]) {
            $this->db->table('connect_webhook_deliveries')->insert([
                'webhook_id'    => 999900 + $i,
                'webhook_uuid'  => 'wb-clean-' . $i,
                'tenant_id'     => $this->tenantA,
                'environment'   => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'uuid'          => uniqid('dlv_', true),
                'event_id'      => uniqid('evt_', true),
                'event_type'    => ConnectConfig::WEBHOOK_EVENT_WEBHOOK_TEST,
                'payload_hash'  => hash('sha256', '{}'),
                'payload'       => json_encode($payload),
                'status'        => $status,
                'attempt'       => 1,
                'next_retry_at' => $now,
                'created_at'    => $updatedAt ?? $now,
                'updated_at'    => $updatedAt ?? $now,
            ]);
        }

        $totalBefore = (int) $this->db->table('connect_webhook_deliveries')->countAllResults();
        $this->assertSame(7, $totalBefore);

        // Only the two terminal rows older than 30 days should go.
        $purged = $service->purgeOlderThan(30);

        $this->assertSame(2, $purged);

        $statuses = [];
        foreach ($this->db->table('connect_webhook_deliveries')->select('status')->get()->getResultArray() as $r) {
            $statuses[] = $r['status'];
        }

        $this->assertCount(5, $statuses);
        $this->assertContains(ConnectConfig::WEBHOOK_DELIVERY_STATUS_PENDING, $statuses);
        $this->assertContains(ConnectConfig::WEBHOOK_DELIVERY_STATUS_PROCESSING, $statuses);
        $this->assertContains(ConnectConfig::WEBHOOK_DELIVERY_STATUS_RETRYING, $statuses);
        $this->assertContains(ConnectConfig::WEBHOOK_DELIVERY_STATUS_DELIVERED, $statuses);
        $this->assertContains(ConnectConfig::WEBHOOK_DELIVERY_STATUS_FAILED, $statuses);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function countLedger(string $externalMessageId, string $status): int
    {
        return (int) $this->db->table('connect_message_status')
            ->where('external_message_id', $externalMessageId)
            ->where('status', $status)
            ->countAllResults();
    }
}
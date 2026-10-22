<?php

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\AddConnectMappingEnvironmentAndScopes;
use App\Database\Migrations\AddConnectPhase4MessageSchema;
use App\Database\Migrations\AddConnectPhase5Outbox;
use App\Database\Migrations\AddConnectPhase8RateLimitsAndQuotas;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectMessageService;
use App\Services\Connect\ConnectQuotaService;
use App\Services\Connect\ConnectRateLimiter;
use App\Services\Connect\ConnectSecretVault;
use App\Services\Connect\ConnectTenantContext;
use App\Services\Connect\DatabaseRateLimitStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 8 rate-limit windows + usage quotas.
 *
 * Rate limits: atomic, DB-backed fixed windows (per-second/minute/hour plus
 * per-category caps) via ConnectRateLimiter over `connect_rate_limit_counters`,
 * with RateLimit-* / Retry-After headers stamped by ConnectApiAuthFilter.
 *
 * Quotas: durable tenant+environment budget metered by ConnectQuotaService
 * INSIDE the connect message transaction. Idempotent retries / rejected sends
 * never consume a unit; exhaustion returns HTTP 429 QUOTA_EXCEEDED.
 *
 * Every HTTP request injects its own ConnectRateLimiter / ConnectMessageService
 * built from a FRESH `new ConnectConfig()` (env re-read per test) — the Factory
 * cached config() is deliberately bypassed because Services::reset() does not
 * clear it.
 *
 *   php -d extension="C:\CI4\php day 1\php\ext\php_sqlite3.dll" vendor\bin\phpunit --no-coverage
 *
 * @internal
 */
final class ConnectRateLimitQuotaFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SECRET_ENV = 'test-connect-vault-key-0123456789abcdef';

    private const ENV_RATE = [
        'CONNECT_RATE_LIMIT_ENABLED',
        'CONNECT_RATE_LIMIT_PER_MINUTE',
        'CONNECT_RATE_LIMIT_PER_SECOND',
        'CONNECT_RATE_LIMIT_PER_HOUR',
        'CONNECT_RATE_LIMIT_FAIL_OPEN',
    ];

    private const ENV_QUOTA = [
        'CONNECT_QUOTA_ENABLED',
        'CONNECT_QUOTA_MESSAGES_PER_MONTH',
        'CONNECT_QUOTA_MESSAGES_PER_DAY',
        'CONNECT_QUOTA_CREDENTIAL_SCOPED',
        'CONNECT_QUOTA_FAIL_OPEN',
    ];

    private CreateHummingBirdConnectFoundation    $foundation;
    private AddConnectApiKeyAndScopes             $phase2Migration;
    private AddConnectMappingEnvironmentAndScopes $phase3Migration;
    private AddConnectPhase4MessageSchema         $phase4Migration;
    private AddConnectPhase5Outbox                $phase5Migration;
    private AddConnectPhase8RateLimitsAndQuotas   $phase8Migration;
    private ConnectSecretVault                    $vault;

    private int $tenantA;
    private int $tenantB;
    private int $hbUser1;

    private string $credSend;
    private string $secretSend;
    private string $credSend2;
    private string $secretSend2;
    private string $credTenantB;
    private string $credAuthTest;
    private string $secretAuthTest;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('CONNECT_SECRET_ENCRYPTION_KEY=' . self::SECRET_ENV);
        putenv('CONNECT_DEBUG=1');
        $this->vault = new ConnectSecretVault();

        foreach (array_merge(self::ENV_RATE, self::ENV_QUOTA) as $name) {
            putenv($name); // clean slate — each test opts into explicit values
        }

        foreach ([
            '2026-09-08-000001_CreateHummingBirdConnectFoundation',
            '2026-09-09-000001_AddConnectApiKeyAndScopes',
            '2026-09-10-000001_AddConnectMappingEnvironmentAndScopes',
            '2026-09-12-000001_AddConnectPhase4MessageSchema',
            '2026-09-13-000001_AddConnectPhase5Outbox',
            '2026-09-15-000001_AddConnectPhase8RateLimitsAndQuotas',
        ] as $file) {
            require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        }

        $this->foundation      = new CreateHummingBirdConnectFoundation();
        $this->phase2Migration = new AddConnectApiKeyAndScopes();
        $this->phase3Migration = new AddConnectMappingEnvironmentAndScopes();
        $this->phase4Migration = new AddConnectPhase4MessageSchema();
        $this->phase5Migration = new AddConnectPhase5Outbox();
        $this->phase8Migration = new AddConnectPhase8RateLimitsAndQuotas();

        $this->foundation->down();
        $this->foundation->up();
        $this->phase2Migration->up();
        $this->phase3Migration->up();
        $this->phase4Migration->up();
        $this->phase5Migration->down();
        $this->phase5Migration->up();
        $this->phase8Migration->down();
        $this->phase8Migration->up();

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
        $this->dropTable('connect_rate_limit_counters');

        if (isset($this->foundation)) {
            $this->foundation->down();
        }

        foreach (array_merge(self::ENV_RATE, self::ENV_QUOTA) as $name) {
            putenv($name);
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
    // Fixtures (mirrors ConnectMessageFeatureTest + a second credential)
    // ------------------------------------------------------------------

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('connect_tenants')->insert([
            'uuid' => uniqid('tA_', true), 'name' => 'Tenant A', 'slug' => 'tenant-a',
            'status' => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at' => $now, 'updated_at' => $now,
        ], true);
        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('connect_tenants')->insert([
            'uuid' => uniqid('tB_', true), 'name' => 'Tenant B', 'slug' => 'tenant-b',
            'status' => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at' => $now, 'updated_at' => $now,
        ], true);
        $this->tenantB = (int) $this->db->insertID();

        foreach (['remark' => 'Remark', 'remark-b' => 'Remark B'] as $name => $display) {
            $this->db->table('connect_service_identities')->insert([
                'tenant_id' => $name === 'remark' ? $this->tenantA : $this->tenantB,
                'name' => $name, 'display_name' => $display,
                'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
                'status' => ConnectConfig::IDENTITY_STATUS_ACTIVE,
                'created_at' => $now, 'updated_at' => $now,
            ], true);
        }

        $this->secretSend  = 'test_secret_send_0001';
        $this->credSend    = 'hb_test_' . bin2hex(random_bytes(8));
        $this->secretSend2 = 'test_secret_send_0002';
        $this->credSend2   = 'hb_test_' . bin2hex(random_bytes(8));
        $this->credTenantB = 'hb_test_' . bin2hex(random_bytes(8));

        $sendScopes = [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ];
        $this->insertCredential($this->credSend, $this->secretSend, $this->tenantA, $sendScopes);
        $this->insertCredential($this->credSend2, $this->secretSend2, $this->tenantA, $sendScopes);
        $this->insertCredential($this->credTenantB, 'test_tenant_b_001', $this->tenantB, $sendScopes);

        // auth/test additionally requires SCOPE_CONNECT_TEST (AuthController).
        $this->secretAuthTest = 'test_secret_authtest_0001';
        $this->credAuthTest   = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credAuthTest,
            $this->secretAuthTest,
            $this->tenantA,
            [ConnectConfig::SCOPE_CONNECT_TEST],
        );

        $this->db->table('users')->insert([
            'user_first_name' => 'HB', 'user_last_name' => 'UserOne', 'user_email' => 'one@example.com',
        ], true);
        $this->hbUser1 = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'user_first_name' => 'HB', 'user_last_name' => 'UserTwo', 'user_email' => 'two@example.com',
        ], true);

        $this->db->table('connect_user_mappings')->insert([
            'tenant_id'            => $this->tenantA,
            'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'external_user_id'     => 'ext_user_1',
            'humming_bird_user_id' => $this->hbUser1,
            'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
            'mapped_at'            => $now, 'created_at' => $now, 'updated_at' => $now,
        ], true);

        $this->db->table('connect_user_mappings')->insert([
            'tenant_id'            => $this->tenantB,
            'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'external_user_id'     => 'ext_user_b',
            'humming_bird_user_id' => $this->hbUser1,
            'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
            'mapped_at'            => $now, 'created_at' => $now, 'updated_at' => $now,
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
            'created_at'    => $now, 'updated_at' => $now,
        ], true);
    }

    // ------------------------------------------------------------------
    // Phase 8 config builders — fresh ConnectConfig per call (env re-read)
    // ------------------------------------------------------------------

    private function freshConfig(array $rate = [], array $quota = []): ConnectConfig
    {
        putenv('CONNECT_RATE_LIMIT_ENABLED=' . (string) ($rate['enabled'] ?? 1));
        putenv('CONNECT_RATE_LIMIT_PER_MINUTE=' . (string) ($rate['per_minute'] ?? 60));
        if (array_key_exists('per_second', $rate) && $rate['per_second'] !== null) {
            putenv('CONNECT_RATE_LIMIT_PER_SECOND=' . (int) $rate['per_second']);
        } else {
            putenv('CONNECT_RATE_LIMIT_PER_SECOND');
        }
        putenv('CONNECT_RATE_LIMIT_PER_HOUR=' . (string) ($rate['per_hour'] ?? 0));
        putenv('CONNECT_RATE_LIMIT_FAIL_OPEN=' . (string) ($rate['fail_open'] ?? 1));

        putenv('CONNECT_QUOTA_ENABLED=' . (string) ($quota['enabled'] ?? 1));
        putenv('CONNECT_QUOTA_MESSAGES_PER_MONTH=' . (string) ($quota['per_month'] ?? 100000));
        putenv('CONNECT_QUOTA_MESSAGES_PER_DAY=' . (string) ($quota['per_day'] ?? 0));
        putenv('CONNECT_QUOTA_CREDENTIAL_SCOPED=' . (string) ($quota['credential_scoped'] ?? 0));
        putenv('CONNECT_QUOTA_FAIL_OPEN=' . (string) ($quota['fail_open'] ?? 1));

        return new ConnectConfig();
    }

    private function makeRateLimiter(ConnectConfig $config): ConnectRateLimiter
    {
        return new ConnectRateLimiter(new DatabaseRateLimitStore(), $config);
    }

    private function makeQuota(ConnectConfig $config): ConnectQuotaService
    {
        return new ConnectQuotaService(new DatabaseRateLimitStore(), $config);
    }

    private function makeMessageService(ConnectQuotaService $quota): ConnectMessageService
    {
        return new ConnectMessageService(null, null, null, null, null, null, null, null, null, null, $quota);
    }

    private function context(): ConnectTenantContext
    {
        return new ConnectTenantContext(
            $this->tenantA,
            1,
            'hb_cred_test',
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectAuthenticationService::requestId(),
            [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );
    }

    private function dropRateTables(): void
    {
        $this->dropTable('connect_rate_limit_counters');
        if ($this->db->tableExists('connect_api_audit_logs', false)) {
            $this->db->table('connect_api_audit_logs')->truncate();
        }
    }

    // ------------------------------------------------------------------
    // App-run + signing helpers (with per-request service injections)
    // ------------------------------------------------------------------

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

    private function connectRequest(
        string $apiKey,
        string $secret,
        string $method,
        string $path,
        string $body = '',
        array $injections = [],
    ): TestResponse {
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

        return $this->runConnect($method, $path, $headers, $body, $injections);
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

    private function sendMessage(array $body, string $apiKey = '', string $secret = '', array $injections = []): TestResponse
    {
        $apiKey = $apiKey ?: $this->credSend;
        $secret = $secret ?: $this->secretSend;

        return $this->connectRequest(
            $apiKey,
            $secret,
            'POST',
            '/api/v1/connect/messages/send',
            json_encode($body),
            $injections,
        );
    }

    private function authTest(array $injections = []): TestResponse
    {
        return $this->connectRequest($this->credAuthTest, $this->secretAuthTest, 'GET', '/api/v1/connect/auth/test', '', $injections);
    }

    private function headerValue(TestResponse $response, string $name): string
    {
        return trim((string) $response->response()->getHeaderLine($name));
    }

    private function counterRow(string $scopeCategory, int $tenantId): ?array
    {
        $row = $this->db->table('connect_rate_limit_counters')
            ->where('scope_category', $scopeCategory)
            ->where('tenant_id', $tenantId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        return ($row === false || $row === null) ? null : $row;
    }

    // ------------------------------------------------------------------
    // RATE LIMITS
    // ------------------------------------------------------------------

    // 1. General per-minute window — headers on success, 429 + Retry-After.
    public function test01GeneralRateWindowHeadersAnd429(): void
    {
        $config  = $this->freshConfig(['per_minute' => 3]);
        $inject  = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->authTest($inject)->assertStatus(200);
        $this->authTest($inject)->assertStatus(200);
        $r3 = $this->authTest($inject);
        $r3->assertStatus(200);
        $this->assertSame('0', $this->headerValue($r3, ConnectConfig::HEADER_RATE_LIMIT_REMAINING));

        $r4 = $this->authTest($inject);
        $r4->assertStatus(429);
        $j = $this->json($r4);
        $this->assertSame(ConnectConfig::ERROR_RATE_LIMIT_EXCEEDED, $j->error->code);
        $this->assertSame('0', $this->headerValue($r4, ConnectConfig::HEADER_RATE_LIMIT_REMAINING));
        $this->assertGreaterThan(0, (int) $this->headerValue($r4, ConnectConfig::HEADER_RETRY_AFTER));

        $row = $this->counterRow('all.60.m', $this->tenantA);
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['usage_count']);
        $this->assertSame(3, (int) $row['limit_value']);
    }

    // 2. Per-second window with an injected clock (deterministic bucket math).
    public function test02PerSecondWindowWithInjectedClock(): void
    {
        $config = $this->freshConfig(['per_minute' => 0, 'per_second' => 2]);
        $limit  = $this->makeRateLimiter($config);
        $ctx    = $this->context();
        $t      = 1_000_000_000;

        $this->assertSame(1, $limit->check($ctx, 'request', $t)->remaining());
        $d2 = $limit->check($ctx, 'request', $t);
        $this->assertTrue($d2->allowed());
        $this->assertSame(0, $d2->remaining());

        $d3 = $limit->check($ctx, 'request', $t);
        $this->assertFalse($d3->allowed());
        $this->assertSame(1, $d3->retryAfter()); // bucket ends at t+1
        $this->assertSame(ConnectConfig::ERROR_RATE_LIMIT_EXCEEDED, $d3->errorCode());

        // Next second = fresh bucket → allowed again.
        $this->assertTrue($limit->check($ctx, 'request', $t + 1)->allowed());
    }

    // 3. Per-category caps are isolated from each other.
    public function test03CategoryCapsAreIsolated(): void
    {
        $config = $this->freshConfig(['per_minute' => 0]);
        $config->rateLimitCategories = ['messages' => 2];
        $limit = $this->makeRateLimiter($config);
        $ctx   = $this->context();
        $t     = time();

        $this->assertTrue($limit->check($ctx, 'messages', $t)->allowed());
        $this->assertTrue($limit->check($ctx, 'messages', $t)->allowed());
        $denied = $limit->check($ctx, 'messages', $t);
        $this->assertFalse($denied->allowed());
        $this->assertSame(ConnectConfig::ERROR_RATE_LIMIT_EXCEEDED, $denied->errorCode());

        // 'auth' has no cap (and no general windows) → always allowed.
        $this->assertTrue($limit->check($ctx, 'auth', $t)->allowed());
    }

    // 4. Rate limiting disabled → every check allowed, no header info.
    public function test04RateLimitDisabledAllowsEverything(): void
    {
        $config = $this->freshConfig(['enabled' => 0, 'per_minute' => 1]);
        $limit  = $this->makeRateLimiter($config);
        $ctx    = $this->context();
        $t      = time();

        $d = $limit->check($ctx, 'request', $t);
        $this->assertTrue($d->allowed());
        $this->assertNull($d->limit());
        $this->assertTrue($limit->check($ctx, 'request', $t)->allowed());
    }

    // 5. Store-level CAS — a burst of 12 claims against a 3-unit window never
    //    overshoots the limit.
    public function test05ConcurrentBurstNeverOvershoots(): void
    {
        $config = $this->freshConfig(['per_minute' => 3]);
        $limit  = new ConnectRateLimiter(new DatabaseRateLimitStore(), $config);
        $ctx    = $this->context();

        $allowed = 0;
        $denied  = 0;

        for ($i = 0; $i < 12; $i++) {
            if ($limit->check($ctx, 'request')->allowed()) {
                $allowed++;
            } else {
                $denied++;
            }
        }

        $this->assertSame(3, $allowed);
        $this->assertSame(9, $denied);
        $this->assertSame(3, (int) $this->counterRow('all.60.m', $this->tenantA)['usage_count']);
    }

    // 6. Rate limit applies on the message send endpoint itself.
    public function test06RateLimitBlocksMessagesSend(): void
    {
        $config = $this->freshConfig(['per_minute' => 2]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'one'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'two'], '', '', $inject)->assertStatus(202);

        $r3 = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'three'], '', '', $inject);
        $r3->assertStatus(429);
        $this->assertSame(ConnectConfig::ERROR_RATE_LIMIT_EXCEEDED, $this->json($r3)->error->code);
        $this->assertNotSame('', $this->headerValue($r3, ConnectConfig::HEADER_RETRY_AFTER));

        // Third send was rejected at the filter — only two tracking rows exist.
        $this->assertSame(2, (int) $this->db->table('connect_external_messages')
            ->where('tenant_id', $this->tenantA)
            ->countAllResults());
    }

    // 7. Missing counter table → legacy audit-row fallback: no crash, works.
    public function test07MissingTableFallsBackToLegacyCounting(): void
    {
        $this->dropRateTables();

        $store = new DatabaseRateLimitStore();
        $this->assertFalse($store->available());

        $config = $this->freshConfig(['per_minute' => 2]);
        $limit  = new ConnectRateLimiter($store, $config);

        // No audit rows yet → claims pass without touching a missing table.
        $this->assertTrue($limit->check($this->context(), 'request')->allowed());
        $this->assertTrue($limit->check($this->context(), 'request')->allowed());
        $this->assertIsInt($limit->check($this->context(), 'request')->remaining());

        // HTTP path still completes with a valid envelope.
        $response = $this->authTest(['connectratelimiter' => $limit]);
        $response->assertStatus(200);
        $this->assertTrue($this->json($response)->success);
    }

    // ------------------------------------------------------------------
    // USAGE QUOTAS
    // ------------------------------------------------------------------

    // 8. Monthly quota — 202s down to the limit, then 429 + quota headers.
    public function test08QuotaExceededReturns429(): void
    {
        $config = $this->freshConfig(['per_minute' => 1000], ['per_month' => 2]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $r1 = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a', 'external_message_id' => 'q1'], '', '', $inject);
        $r1->assertStatus(202);
        $this->assertSame('2', $this->headerValue($r1, ConnectConfig::QUOTA_HEADER_LIMIT));
        $this->assertSame('1', $this->headerValue($r1, ConnectConfig::QUOTA_HEADER_REMAINING));
        $this->assertSame('month', $this->headerValue($r1, ConnectConfig::QUOTA_HEADER_PERIOD));

        $r2 = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b', 'external_message_id' => 'q2'], '', '', $inject);
        $r2->assertStatus(202);
        $this->assertSame('0', $this->headerValue($r2, ConnectConfig::QUOTA_HEADER_REMAINING));

        $r3 = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'c', 'external_message_id' => 'q3'], '', '', $inject);
        $r3->assertStatus(429);
        $j = $this->json($r3);
        $this->assertFalse($j->status);
        $this->assertSame(ConnectConfig::ERROR_QUOTA_EXCEEDED, $j->error->code);
        $this->assertNotSame('', $this->headerValue($r3, ConnectConfig::HEADER_RETRY_AFTER));

        $row = $this->counterRow('messages.month', $this->tenantA);
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['usage_count']);
        $this->assertSame(2, (int) $row['limit_value']);
    }

    // 9. Idempotent duplicates never consume quota.
    public function test09IdempotentDuplicateDoesNotConsumeQuota(): void
    {
        $config = $this->freshConfig(['per_minute' => 1000], ['per_month' => 2]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'x', 'external_message_id' => 'dup1'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'x-again', 'external_message_id' => 'dup1'], '', '', $inject)->assertStatus(200);

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'y', 'external_message_id' => 'dup2'], '', '', $inject)->assertStatus(202);

        $this->assertSame(2, (int) $this->counterRow('messages.month', $this->tenantA)['usage_count']);

        $r = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'z', 'external_message_id' => 'dup3'], '', '', $inject);
        $r->assertStatus(429);
        $this->assertSame(ConnectConfig::ERROR_QUOTA_EXCEEDED, $this->json($r)->error->code);
    }

    // 10. Rejected sends (mapping failure) never consume quota.
    public function test10RejectedSendDoesNotConsumeQuota(): void
    {
        $config = $this->freshConfig(['per_minute' => 1000], ['per_month' => 2]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_unknown', 'body' => 'nope'], '', '', $inject)->assertStatus(404);

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a', 'external_message_id' => 'r1'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b', 'external_message_id' => 'r2'], '', '', $inject)->assertStatus(202);

        $r = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'c', 'external_message_id' => 'r3'], '', '', $inject);
        $r->assertStatus(429);
        $this->assertSame(2, (int) $this->counterRow('messages.month', $this->tenantA)['usage_count']);
    }

    // 11. Quota is isolated per tenant.
    public function test11QuotaIsIsolatedPerTenant(): void
    {
        $config = $this->freshConfig(['per_minute' => 1000], ['per_month' => 1]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a', 'external_message_id' => 't1'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b', 'external_message_id' => 't2'], '', '', $inject)->assertStatus(429);

        $ok = $this->sendMessage(
            ['external_user_id' => 'ext_user_b', 'body' => 'b-msg', 'external_message_id' => 'tb1'],
            $this->credTenantB,
            'test_tenant_b_001',
            $inject,
        );
        $ok->assertStatus(202);

        $this->assertSame(2, (int) $this->db->table('connect_rate_limit_counters')
            ->where('kind', 'quota')
            ->countAllResults());
    }

    // 12. Daily window (month disabled) enforces its own calendar bucket.
    public function test12DailyQuotaWindowEnforced(): void
    {
        $config = $this->freshConfig(['per_minute' => 1000], ['per_month' => 0, 'per_day' => 1]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $r1 = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a', 'external_message_id' => 'd1'], '', '', $inject);
        $r1->assertStatus(202);
        $this->assertSame('day', $this->headerValue($r1, ConnectConfig::QUOTA_HEADER_PERIOD));

        $r2 = $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b', 'external_message_id' => 'd2'], '', '', $inject);
        $r2->assertStatus(429);
        $this->assertSame(ConnectConfig::ERROR_QUOTA_EXCEEDED, $this->json($r2)->error->code);
    }

    // 13. Quota disabled entirely → unlimited accepts, no counter rows.
    public function test13QuotaDisabledAllowsAllSends(): void
    {
        $config = $this->freshConfig(['per_minute' => 1000], ['enabled' => 0]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'c'], '', '', $inject)->assertStatus(202);

        $this->assertSame(0, (int) $this->db->table('connect_rate_limit_counters')
            ->where('kind', 'quota')
            ->countAllResults());
    }

    // 14. Quota fail-open — missing counter table never rejects a send.
    public function test14QuotaMissingTableFailsOpen(): void
    {
        $this->dropRateTables();

        $config = $this->freshConfig(['per_minute' => 1000], ['per_month' => 2]);
        $inject = [
            'connectratelimiter' => $this->makeRateLimiter($config),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($config)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b'], '', '', $inject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'c'], '', '', $inject)->assertStatus(202);
    }

    // 15. Credential-scoped quota (quotaCredentialScoped=true) splits the
    //    budget per API key; default (false) shares one tenant budget.
    public function test15QuotaCredentialScoping(): void
    {
        $scopedConfig = $this->freshConfig(['per_minute' => 1000], ['per_month' => 1, 'credential_scoped' => 1]);
        $scopedInject = [
            'connectratelimiter' => $this->makeRateLimiter($scopedConfig),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($scopedConfig)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'a', 'external_message_id' => 'c1a'], '', '', $scopedInject)->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'b', 'external_message_id' => 'c1b'], '', '', $scopedInject)->assertStatus(429);

        // Second credential still has its own fresh unit.
        $ok = $this->sendMessage(
            ['external_user_id' => 'ext_user_1', 'body' => 'c', 'external_message_id' => 'c2a'],
            $this->credSend2,
            $this->secretSend2,
            $scopedInject,
        );
        $ok->assertStatus(202);

        $scopedCreds = (int) $this->db->table('connect_rate_limit_counters')
            ->where('kind', 'quota')
            ->distinct(true)
            ->countAllResults();
        $this->assertGreaterThanOrEqual(2, $scopedCreds);

        // Shared (default) budget across both credentials.
        $sharedConfig = $this->freshConfig(['per_minute' => 1000], ['per_month' => 1, 'credential_scoped' => 0]);
        $sharedInject = [
            'connectratelimiter' => $this->makeRateLimiter($sharedConfig),
            'connectmessage'     => $this->makeMessageService($this->makeQuota($sharedConfig)),
        ];

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'd', 'external_message_id' => 'sh1'], '', '', $sharedInject)->assertStatus(202);
        $denied = $this->sendMessage(
            ['external_user_id' => 'ext_user_1', 'body' => 'e', 'external_message_id' => 'sh2'],
            $this->credSend2,
            $this->secretSend2,
            $sharedInject,
        );
        $denied->assertStatus(429);
        $this->assertSame(ConnectConfig::ERROR_QUOTA_EXCEEDED, $this->json($denied)->error->code);
    }
}
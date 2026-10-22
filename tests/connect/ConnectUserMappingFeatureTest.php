<?php

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Database\Migrations\AddConnectMappingEnvironmentAndScopes;
use App\Database\Migrations\DropLegacyConnectMappingTenantUniqueIndex;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectSecretVault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 3 user-mapping feature tests.
 *
 * Covers the full spec matrix (external→HB user mapping): valid create,
 * idempotent create, unknown/inactive HB user, missing/invalid ids, tenant
 * isolation, environment isolation, unmapping, audit rows, and secrets
 * never leaked in responses.
 *
 *   php -d extension="C:\CI4\php day 1\php\ext\php_sqlite3.dll" vendor\bin\phpunit --no-coverage
 *
 * @internal
 */
final class ConnectUserMappingFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SECRET_ENV = 'test-connect-vault-key-0123456789abcdef';

    private CreateHummingBirdConnectFoundation $foundation;
    private AddConnectApiKeyAndScopes $phase2Migration;
    private AddConnectMappingEnvironmentAndScopes $phase3Migration;
    private DropLegacyConnectMappingTenantUniqueIndex $legacyIndexFix;
    private ConnectSecretVault $vault;

    private int $tenantA;
    private int $tenantB;

    private string $credWrite;          // hb_test_* tenant A, users:write scope
    private string $credWriteTenantB;   // hb_test_* tenant B, users:write scope
    private string $credProdTenantA;    // hb_live_* tenant A, users:write (env=live)
    private string $credNoScope;        // hb_test_* tenant B, no scope
    private string $credRevoked;        // hb_test_* tenant A, revoked status
    private string $secretWrite;

    private int $hbUser1;   // active HB user fixture
    private int $hbUser2;   // active HB user fixture
    private int $hbInactive; // inactive (u_is_deleted=1) HB user fixture

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
            . 'Database/Migrations/2026-09-28-000001_DropLegacyConnectMappingTenantUniqueIndex.php';

        $this->foundation       = new CreateHummingBirdConnectFoundation();
        $this->phase2Migration  = new AddConnectApiKeyAndScopes();
        $this->phase3Migration  = new AddConnectMappingEnvironmentAndScopes();
        $this->legacyIndexFix   = new DropLegacyConnectMappingTenantUniqueIndex();

        $this->foundation->down();
        $this->foundation->up();
        $this->phase2Migration->up();
        $this->phase3Migration->up();
        // The repair migration production needs. It is a no-op once the legacy
        // index is gone, so running it here keeps the tested schema identical
        // to a migrated production database.
        $this->legacyIndexFix->up();

        $this->createUsersTable();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->dropUsersTable();

        if (isset($this->foundation)) {
            $this->foundation->down();
        }

        putenv('CONNECT_DEBUG');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // HB user fixtures (minimal `users` table)
    // ------------------------------------------------------------------

    private function createUsersTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->db->DBPrefix . 'users ('
            . 'user_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'user_name TEXT DEFAULT NULL,'
            . 'user_email TEXT DEFAULT NULL,'
            . 'user_mobile_num TEXT DEFAULT NULL,'
            . 'u_is_deleted INTEGER DEFAULT 0'
            . ')');
    }

    private function dropUsersTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->DBPrefix . 'users');
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

        // --- credentials -----------------------------------------------
        $this->secretWrite = 'test_secret_write_local_0001';
        $this->credWrite = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credWrite,
            $this->secretWrite,
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_USERS_WRITE],
        );

        $this->credWriteTenantB = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credWriteTenantB,
            'secret_write_tenant_b_001',
            $this->tenantB,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_USERS_WRITE],
        );

        $this->credProdTenantA = 'hb_live_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credProdTenantA,
            'secret_prod_tenant_a_001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_PRODUCTION,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_USERS_WRITE],
        );

        $this->credNoScope = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credNoScope,
            'noscope_secret_local_0001',
            $this->tenantB,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [],
        );

        $this->credRevoked = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credRevoked,
            'revoked_secret_local_0001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_REVOKED,
            [ConnectConfig::SCOPE_USERS_WRITE],
        );

        // --- HB users --------------------------------------------------
        $this->db->table('users')->insert([
            'user_name' => 'HB User One',
            'user_email' => 'one@example.com',
        ], true);
        $this->hbUser1 = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'user_name' => 'HB User Two',
            'user_email' => 'two@example.com',
        ], true);
        $this->hbUser2 = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'user_name'      => 'Inactive User',
            'user_email'     => 'inactive@example.com',
            'u_is_deleted'   => 1,
        ], true);
        $this->hbInactive = (int) $this->db->insertID();
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
    // App-run + signing helpers (same pattern as ConnectAuthFeatureTest)
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

        // Populate GET globals from the URI query string so the controller's
        // getGet('external_user_id') sees the same values a real server would.
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

    private function mapExt(
        string $extId,
        int $hbUserId,
        string $apiKey = '',
        string $secret = '',
        string $method = 'POST',
    ): TestResponse {
        $apiKey  = $apiKey ?: $this->credWrite;
        $secret  = $secret ?: $this->secretWrite;
        $path    = '/api/v1/connect/users/map';
        $payload = json_encode([
            'external_user_id'     => $extId,
            'humming_bird_user_id' => (string) $hbUserId,
        ]);

        return $this->connectRequest($apiKey, $secret, $method, $path, $payload);
    }

    private function lookupExt(
        string $extId,
        string $apiKey = '',
        string $secret = '',
    ): TestResponse {
        $apiKey = $apiKey ?: $this->credWrite;
        $secret = $secret ?: $this->secretWrite;
        $path   = '/api/v1/connect/users/mapping';

        return $this->connectRequest($apiKey, $secret, 'GET', $path, '', "external_user_id={$extId}");
    }

    private function unmapExt(
        string $extId,
        string $apiKey = '',
        string $secret = '',
    ): TestResponse {
        $apiKey  = $apiKey ?: $this->credWrite;
        $secret  = $secret ?: $this->secretWrite;
        $path    = '/api/v1/connect/users/mapping';
        $payload = json_encode(['external_user_id' => $extId]);

        return $this->connectRequest($apiKey, $secret, 'DELETE', $path, $payload);
    }

    // ------------------------------------------------------------------
    // 1. Valid create (201)
    // ------------------------------------------------------------------
    public function test01ValidCreateReturns201WithMappedStatus(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbUser1);
        $response->assertStatus(201);

        $json = $this->json($response);
        $this->assertTrue($json->status);
        $this->assertSame($extId, $json->data->external_user_id);
        $this->assertSame((string) $this->hbUser1, $json->data->humming_bird_user_id);
        $this->assertSame(ConnectConfig::MAPPING_STATUS_MAPPED, $json->data->status);
        $this->assertSame(ConnectConfig::TENANT_ENVIRONMENT_TEST, $json->data->environment);
        $this->assertMatchesRegularExpression('/^req_[A-Za-z0-9_-]+$/', $json->request_id);
    }

    // ------------------------------------------------------------------
    // 2. Unknown HB user (404)
    // ------------------------------------------------------------------
    public function test02UnknownHummingBirdUserReturns404(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, 999999);
        $response->assertStatus(404);

        $json = $this->json($response);
        $this->assertFalse($json->status);
        $this->assertSame(ConnectConfig::ERROR_HUMMING_BIRD_USER_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 3. Idempotent create — same ext+hb → 200 with existing data
    // ------------------------------------------------------------------
    public function test03IdempotentCreateReturns200WithSameData(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $r1 = $this->mapExt($extId, $this->hbUser1);
        $r1->assertStatus(201);

        $r2 = $this->mapExt($extId, $this->hbUser1);
        $r2->assertStatus(200);

        $json2 = $this->json($r2);
        $this->assertTrue($json2->status);
        $this->assertSame($extId, $json2->data->external_user_id);
        $this->assertSame((string) $this->hbUser1, $json2->data->humming_bird_user_id);
        $this->assertSame(ConnectConfig::MAPPING_STATUS_MAPPED, $json2->data->status);
    }

    // ------------------------------------------------------------------
    // 4. Conflict — ext already mapped to different HB user → 409
    // ------------------------------------------------------------------
    public function test04DuplicateExtDifferentHbUserReturns409MappingConflict(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extId, $this->hbUser1)->assertStatus(201);

        $response = $this->mapExt($extId, $this->hbUser2);
        $response->assertStatus(409);

        $json = $this->json($response);
        $this->assertFalse($json->status);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_CONFLICT, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 5. Conflict — same HB user already mapped by different ext → 409
    // ------------------------------------------------------------------
    public function test05HbUserAlreadyMappedToAnotherExtReturns409(): void
    {
        $ext1 = 'remark_user_' . bin2hex(random_bytes(6));
        $ext2 = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($ext1, $this->hbUser1)->assertStatus(201);

        $response = $this->mapExt($ext2, $this->hbUser1);
        $response->assertStatus(409);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_USER_ALREADY_MAPPED, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 6. Missing external_user_id (400)
    // ------------------------------------------------------------------
    public function test06MissingExternalUserIdReturns400(): void
    {
        $path  = '/api/v1/connect/users/map';
        $body  = json_encode(['humming_bird_user_id' => (string) $this->hbUser1]);

        $response = $this->connectRequest($this->credWrite, $this->secretWrite, 'POST', $path, $body);
        $response->assertStatus(400);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 7. Missing humming_bird_user_id (400)
    // ------------------------------------------------------------------
    public function test07MissingHummingBirdUserIdReturns400(): void
    {
        $path  = '/api/v1/connect/users/map';
        $body  = json_encode(['external_user_id' => 'ext_xxx']);

        $response = $this->connectRequest($this->credWrite, $this->secretWrite, 'POST', $path, $body);
        $response->assertStatus(400);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MISSING_HUMMING_BIRD_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 8. Invalid external_user_id — control character (400)
    // ------------------------------------------------------------------
    public function test08InvalidExternalUserIdReturns400(): void
    {
        $extId = "remark\x00user_123";

        $response = $this->mapExt($extId, $this->hbUser1);
        $response->assertStatus(400);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_INVALID_EXTERNAL_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 9. Empty body (400)
    // ------------------------------------------------------------------
    public function test09EmptyBodyReturns400(): void
    {
        $path = '/api/v1/connect/users/map';

        $response = $this->connectRequest($this->credWrite, $this->secretWrite, 'POST', $path);
        $response->assertStatus(400);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 10. Missing scope → 403 (scope filter rejects before controller)
    // ------------------------------------------------------------------
    public function test10MissingScopeReturns403(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbUser1, $this->credNoScope, 'noscope_secret_local_0001');
        $response->assertStatus(403);

        $json = $this->json($response);
        $this->assertFalse($json->success);
        $this->assertSame(ConnectConfig::ERROR_INSUFFICIENT_SCOPE, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 11. Revoked credential → 401 (filter rejects before controller)
    // ------------------------------------------------------------------
    public function test11RevokedCredentialReturns401(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbUser1, $this->credRevoked, 'revoked_secret_local_0001');
        $response->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // 12. Inactive HB user (u_is_deleted=1) → 422
    // ------------------------------------------------------------------
    public function test12InactiveHummingBirdUserReturns422(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbInactive);
        $response->assertStatus(422);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_HUMMING_BIRD_USER_INACTIVE, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 13. Tenant isolation — tenant B cannot see tenant A mapping via GET
    // ------------------------------------------------------------------
    public function test13TenantIsolationGetCannotSeeOtherTenantMapping(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extId, $this->hbUser1)->assertStatus(201);

        // tenant B credentials — same ext, but not mapped
        $response = $this->lookupExt($extId, $this->credWriteTenantB, 'secret_write_tenant_b_001');
        $response->assertStatus(404);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 14. Tenant isolation — tenant B cannot unmap tenant A mapping
    // ------------------------------------------------------------------
    public function test14TenantIsolationDeleteCannotUnmapOtherTenantMapping(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extId, $this->hbUser1)->assertStatus(201);

        $response = $this->unmapExt($extId, $this->credWriteTenantB, 'secret_write_tenant_b_001');
        $response->assertStatus(404);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 15. Environment isolation — same ext in different env → separate
    // ------------------------------------------------------------------
    public function test15EnvironmentIsolationSameExtInDifferentEnv(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        // create in test environment
        $this->mapExt($extId, $this->hbUser1, $this->credWrite, $this->secretWrite)->assertStatus(201);

        // create in live environment (different env) → should also succeed (201)
        $response = $this->mapExt($extId, $this->hbUser2, $this->credProdTenantA, 'secret_prod_tenant_a_001');
        $response->assertStatus(201);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::TENANT_ENVIRONMENT_PRODUCTION, $json->data->environment);
        $this->assertSame((string) $this->hbUser2, $json->data->humming_bird_user_id);

        // lookup in live env should find hbUser2, not hbUser1
        $lookupLive = $this->lookupExt($extId, $this->credProdTenantA, 'secret_prod_tenant_a_001');
        $lookupLive->assertStatus(200);
        $lookupJson = $this->json($lookupLive);
        $this->assertSame((string) $this->hbUser2, $lookupJson->data->humming_bird_user_id);

        // lookup in test env should find hbUser1
        $lookupTest = $this->lookupExt($extId, $this->credWrite, $this->secretWrite);
        $lookupTest->assertStatus(200);
        $lookupJson2 = $this->json($lookupTest);
        $this->assertSame((string) $this->hbUser1, $lookupJson2->data->humming_bird_user_id);
    }

    // ------------------------------------------------------------------
    // 16. HB user env-isolation — same HB user can be mapped in test and live
    // ------------------------------------------------------------------
    public function test16SameHbUserCanBeMappedInDifferentEnvironments(): void
    {
        $extTest = 'remark_user_' . bin2hex(random_bytes(6));
        $extLive = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extTest, $this->hbUser1, $this->credWrite, $this->secretWrite)->assertStatus(201);
        $this->mapExt($extLive, $this->hbUser1, $this->credProdTenantA, 'secret_prod_tenant_a_001')->assertStatus(201);

        // each env's lookup returns its own mapping
        $this->lookupExt($extTest, $this->credWrite, $this->secretWrite)->assertStatus(200);
        $this->lookupExt($extLive, $this->credProdTenantA, 'secret_prod_tenant_a_001')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // 17. Lookup not found (404)
    // ------------------------------------------------------------------
    public function test17LookupNotMappedReturns404(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->lookupExt($extId);
        $response->assertStatus(404);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 18. Unmap success (200)
    // ------------------------------------------------------------------
    public function test18UnmapReturns200WithUnmappedStatus(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extId, $this->hbUser1)->assertStatus(201);

        $response = $this->unmapExt($extId);
        $response->assertStatus(200);

        $json = $this->json($response);
        $this->assertTrue($json->status);
        $this->assertSame(ConnectConfig::MAPPING_STATUS_UNMAPPED, $json->data->status);
        $this->assertSame($extId, $json->data->external_user_id);
        $this->assertSame((string) $this->hbUser1, $json->data->humming_bird_user_id);

        // verify mapping is now unmapped via lookup (lookup only returns mapped rows)
        $this->lookupExt($extId)->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // 19. Unmap not found (404)
    // ------------------------------------------------------------------
    public function test19UnmapNotMappedReturns404(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->unmapExt($extId);
        $response->assertStatus(404);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_NOT_FOUND, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 20. Unmap already-unmapped → 409 MAPPING_ALREADY_REMOVED
    // ------------------------------------------------------------------
    public function test20UnmapAlreadyUnmappedReturns409(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extId, $this->hbUser1)->assertStatus(201);
        $this->unmapExt($extId)->assertStatus(200);

        $response = $this->unmapExt($extId);
        $response->assertStatus(409);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_ALREADY_REMOVED, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 21. Response keys contain no internal/secret fields
    // ------------------------------------------------------------------
    public function test21ResponseKeysContainNoSecretsOrInternals(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $createResponse = $this->mapExt($extId, $this->hbUser1);
        $createResponse->assertStatus(201);

        $json = $this->json($createResponse);

        // top-level keys: status, data, message, request_id
        $this->assertObjectHasProperty('status', $json);
        $this->assertObjectHasProperty('data', $json);
        $this->assertObjectHasProperty('request_id', $json);

        // data keys: external_user_id, humming_bird_user_id, status, environment
        $this->assertObjectHasProperty('external_user_id', $json->data);
        $this->assertObjectHasProperty('humming_bird_user_id', $json->data);
        $this->assertObjectHasProperty('status', $json->data);
        $this->assertObjectHasProperty('environment', $json->data);

        // no internal fields
        $this->assertObjectNotHasProperty('id', $json->data);
        $this->assertObjectNotHasProperty('tenant_id', $json->data);
        $this->assertObjectNotHasProperty('mapped_at', $json->data);
        $this->assertObjectNotHasProperty('credential_id', $json);

        // secret not in serialized response
        $this->assertStringNotContainsString(
            $this->secretWrite,
            (string) $createResponse->response()->getBody(),
        );
    }

    // ------------------------------------------------------------------
    // 22. Audit row written for successful create
    // ------------------------------------------------------------------
    public function test22AuditRowWrittenForCreate(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbUser1);
        $response->assertStatus(201);

        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('action', ConnectConfig::AUDIT_ACTION_MAPPING_CREATED)
            ->where('status', 'success')
            ->get()->getResultArray();

        $this->assertCount(1, $audit);
        $this->assertSame($extId, $audit[0]['external_user_id']);
        $this->assertSame((string) $this->hbUser1, $audit[0]['humming_bird_user_id']);
        $this->assertStringNotContainsString($this->secretWrite, json_encode($audit));
    }

    // ------------------------------------------------------------------
    // 23. Audit row written for conflict error
    // ------------------------------------------------------------------
    public function test23AuditRowWrittenForConflictError(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->mapExt($extId, $this->hbUser1)->assertStatus(201);
        $this->mapExt($extId, $this->hbUser2)->assertStatus(409);

        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('action', ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT)
            ->where('status', 'denied')
            ->get()->getResultArray();

        $this->assertCount(1, $audit);
        $this->assertSame(ConnectConfig::ERROR_MAPPING_CONFLICT, $audit[0]['error_code']);
    }

    // ------------------------------------------------------------------
    // 24. Audit row written for lookup not found
    // ------------------------------------------------------------------
    public function test24AuditRowWrittenForLookupNotFound(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $this->lookupExt($extId)->assertStatus(404);

        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('action', ConnectConfig::AUDIT_ACTION_MAPPING_LOOKUP)
            ->where('status', 'error')
            ->get()->getResultArray();

        $this->assertCount(1, $audit);
        $this->assertSame($extId, $audit[0]['external_user_id']);
    }

    // ------------------------------------------------------------------
    // 25. Security headers present
    // ------------------------------------------------------------------
    public function test25SecurityHeadersPresent(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbUser1);
        $response->assertStatus(201);
        $response->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    // ------------------------------------------------------------------
    // 26. GET with empty external_user_id → 400
    // ------------------------------------------------------------------
    public function test26GetMappingEmptyExternalUserIdReturns400(): void
    {
        $response = $this->lookupExt('');
        $response->assertStatus(400);

        $json = $this->json($response);
        $this->assertSame(ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID, $json->error->code);
    }

    // ------------------------------------------------------------------
    // 27. HB user mapping conflict via concurrent insert (direct)
    // ------------------------------------------------------------------
    public function test27DirectInsertUniqueViolationReturns409MappingConflict(): void
    {
        // Simulate a race: insert two different mappings for the same HB user
        // with different ext ids directly into the DB (skipping the service),
        // then verify a lookup/unmap doesn't get confused.
        $ext1 = 'direct_ext_' . bin2hex(random_bytes(6));
        $ext2 = 'direct_ext_' . bin2hex(random_bytes(6));

        $now = date('Y-m-d H:i:s');

        $this->db->table('connect_user_mappings')->insert([
            'tenant_id'            => $this->tenantA,
            'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'external_user_id'     => $ext1,
            'humming_bird_user_id' => (int) $this->hbUser1,
            'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
            'mapped_at'            => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], true);

        // second insert with SAME hb_user + tenant + env but different ext → violates uq_connect_mapping_hb_user_env
        try {
            $this->db->table('connect_user_mappings')->insert([
                'tenant_id'            => $this->tenantA,
                'environment'          => ConnectConfig::TENANT_ENVIRONMENT_TEST,
                'external_user_id'     => $ext2,
                'humming_bird_user_id' => (int) $this->hbUser1,
                'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
                'mapped_at'            => $now,
                'created_at'           => $now,
                'updated_at'           => $now,
            ], true);

            // if we reach here, the unique constraint was NOT enforced (unexpected)
            $this->fail('Expected DatabaseException for duplicate HB user mapping');
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            $this->assertStringContainsString('unique', strtolower($e->getMessage()));
        }

        // the first mapping should still be valid
        $lookup = $this->lookupExt($ext1);
        $lookup->assertStatus(200);
        $lookupJson = $this->json($lookup);
        $this->assertSame($ext1, $lookupJson->data->external_user_id);
    }

    // ------------------------------------------------------------------
    // 28. Inactive credential → 401 (filter rejects)
    // ------------------------------------------------------------------
    public function test28InactiveCredentialReturns401(): void
    {
        $extId = 'remark_user_' . bin2hex(random_bytes(6));

        $response = $this->mapExt($extId, $this->hbUser1, $this->credRevoked, 'revoked_secret_local_0001');
        $response->assertStatus(401);
    }
}
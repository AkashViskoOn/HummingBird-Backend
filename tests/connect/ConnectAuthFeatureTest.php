<?php

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectScopeGuard;
use App\Services\Connect\ConnectSecretVault;
use App\Models\Connect\ConnectExternalUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 2 authentication feature tests.
 *
 * Hits the real /api/v1/connect/auth/test route through the framework (filter
 * + controller + audit), exercising all 15 required scenarios against the
 * SQLite `tests` connection. Requires the php `sqlite3` extension:
 *
 *   php -d extension="C:\CI4\php day 1\php\ext\php_sqlite3.dll" vendor\bin\phpunit --no-coverage
 *
 * Requests are executed via a manual app-run helper instead of
 * FeatureTestTrait::call() because call() re-renders JSON bodies through its
 * HTML/TestResponse pipeline (status correct, body HTML-wrapped in this repo's
 * CI4 bundle). The helper here drives the same CodeIgniter::run() directly and
 * returns real status, headers and clean JSON. Services::reset() between runs
 * guarantees no shared mock/request bleed across sequential requests within a
 * test method.
 *
 * Secrets used here are test-only fixtures (never production values).
 *
 * @internal
 */
final class ConnectAuthFeatureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const PATH = '/api/v1/connect/auth/test';

    private const SECRET_A   = 'test_secret_tenant_a_only_local_0001';
    private const SECRET_B   = 'test_secret_tenant_b_only_local_0002';
    private const SECRET_ENV = 'test-connect-vault-key-0123456789abcdef';

    private CreateHummingBirdConnectFoundation $foundation;
    private AddConnectApiKeyAndScopes $phase2Migration;
    private ConnectSecretVault $vault;

    private int $tenantA;
    private int $tenantB;
    private string $credKeyA;   // hb_test_* → tenant A
    private string $credRevoked;
    private string $credNoScope;
    private string $credMismatch; // hb_live_* with test credential env

    protected function setUp(): void
    {
        parent::setUp();

        putenv('CONNECT_SECRET_ENCRYPTION_KEY=' . self::SECRET_ENV);
        $this->vault = new ConnectSecretVault();

        require_once APPPATH
            . 'Database/Migrations/2026-09-08-000001_CreateHummingBirdConnectFoundation.php';
        require_once APPPATH
            . 'Database/Migrations/2026-09-09-000001_AddConnectApiKeyAndScopes.php';

        $this->foundation     = new CreateHummingBirdConnectFoundation();
        $this->phase2Migration = new AddConnectApiKeyAndScopes();

        $this->foundation->down();
        $this->foundation->up();
        $this->phase2Migration->up();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->foundation)) {
            $this->foundation->down();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('connect_tenants')->insert([
            'uuid'        => uniqid('tenant_', true),
            'name'        => 'Tenant A',
            'slug'        => 'tenant-a',
            'status'      => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('connect_tenants')->insert([
            'uuid'        => uniqid('tenant_', true),
            'name'        => 'Tenant B',
            'slug'        => 'tenant-b',
            'status'      => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $this->tenantB = (int) $this->db->insertID();

        $this->credKeyA = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credKeyA,
            self::SECRET_A,
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_CONNECT_TEST],
        );

        $this->credRevoked = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credRevoked,
            'revoked_secret_local_0001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_REVOKED,
            [ConnectConfig::SCOPE_CONNECT_TEST],
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

        // Environment confusion fixture: hb_live_ prefix but 'test' credential.
        $this->credMismatch = 'hb_live_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credMismatch,
            'mismatch_secret_local_0001',
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST, // env 'test' mismatches live prefix
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_CONNECT_TEST],
        );
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
    // App-run helper — real filter → controller → audit pipeline
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

        Services::injectMock('request', $this->request);

        $routes = Services::routes()->loadRoutes();
        $routes->setHTTPVerb($method);

        $this->app = $this->createApplication();
        $response = $this->app
            ->setContext('web')
            ->setRequest($this->request)
            ->run($routes, true);

        Services::reset();

        return new TestResponse($response);
    }

    private function json(TestResponse $response): object
    {
        $body = (string) $response->response()->getBody();
        $decoded = json_decode($body);

        $this->assertInstanceOf(
            \stdClass::class,
            $decoded,
            'Response body is not JSON: ' . substr($body, 0, 200),
        );

        return $decoded;
    }

    // ------------------------------------------------------------------
    // Request helper (mirrors the client signing implementation exactly)
    // ------------------------------------------------------------------

    // Request helper. sign* values feed the canonical string; send* values are
    // what the test actually transmits (default to their sign counterparts).
    private function authRequest(
        string $apiKey,
        string $signMethod,
        string $signPath,
        int $timestamp,
        string $signRequestId,
        string $secret,
        string $signBody = '',
        bool $signBodyHash = true,
        ?string $sendMethod = null,
        ?string $sendPath = null,
        ?string $sendRequestId = null,
        ?string $sendBody = null,
    ): TestResponse {
        $sendMethod    ??= $signMethod;
        $sendPath      ??= $signPath;
        $sendRequestId ??= $signRequestId;
        $sendBody      ??= ($signBodyHash ? $signBody : '');

        $signBodyForHash = $signBodyHash ? $signBody : '';

        $signature = ConnectAuthenticationService::sign(
            $signMethod,
            $signPath,
            (string) $timestamp,
            $signRequestId,
            $signBodyForHash,
            $secret,
        );

        return $this->runConnect(
            $sendMethod,
            $sendPath,
            [
                ConnectConfig::HEADER_API_KEY    => $apiKey,
                ConnectConfig::HEADER_SIGNATURE  => $signature,
                ConnectConfig::HEADER_TIMESTAMP  => (string) $timestamp,
                ConnectConfig::HEADER_REQUEST_ID => $sendRequestId,
            ],
            $sendBody,
        );
    }

    private function validAuth(int $tenant = 0): TestResponse
    {
        return $this->authRequest(
            $this->credKeyA,
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            self::SECRET_A,
        );
    }

    private function assertError(TestResponse $response, int $httpStatus, string $errorCode): void
    {
        $response->assertStatus($httpStatus);
        $json = $this->json($response);
        $this->assertTrue($json->success === false);
        $this->assertSame($errorCode, $json->error->code);
        $this->assertMatchesRegularExpression('/^req_[A-Za-z0-9_-]+$/', $json->request_id);
    }

    // ------------------------------------------------------------------
    // TEST 1 — valid credentials
    // ------------------------------------------------------------------

    public function test01ValidCredentialsAuthenticate(): void
    {
        $response = $this->validAuth();
        $response->assertStatus(200);

        $json = $this->json($response);
        $this->assertTrue($json->success);
        $this->assertTrue($json->data->authenticated);
        $this->assertSame((string) $this->tenantA, (string) $json->data->tenant_id);
        $this->assertSame(ConnectConfig::TENANT_ENVIRONMENT_TEST, $json->data->environment);

        // Security headers.
        $response->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->assertHeader(ConnectConfig::HEADER_REQUEST_ID);

        // Audit row written, sanitized (no secret, no signature).
        $audit = $this->db->table('connect_api_audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('status', 'success')
            ->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString(self::SECRET_A, json_encode($audit));
    }

    // ------------------------------------------------------------------
    // TEST 2 — invalid API key
    // ------------------------------------------------------------------

    public function test02InvalidApiKey(): void
    {
        $response = $this->authRequest(
            'hb_test_nonexistent',
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            self::SECRET_A,
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_INVALID_API_KEY);
    }

    // ------------------------------------------------------------------
    // TEST 3 — invalid signature
    // ------------------------------------------------------------------

    public function test03InvalidSignature(): void
    {
        $response = $this->authRequest(
            $this->credKeyA,
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            'wrong_secret_for_signing',
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_INVALID_SIGNATURE);
    }

    // ------------------------------------------------------------------
    // TEST 4 — expired timestamp
    // ------------------------------------------------------------------

    public function test04ExpiredTimestamp(): void
    {
        $response = $this->authRequest(
            $this->credKeyA,
            'GET',
            self::PATH,
            time() - 3600,
            ConnectAuthenticationService::requestId(),
            self::SECRET_A,
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_REQUEST_EXPIRED);
    }

    // ------------------------------------------------------------------
    // TEST 5 — replayed request id
    // ------------------------------------------------------------------

    public function test05ReplayedRequestId(): void
    {
        $requestId = ConnectAuthenticationService::requestId();
        $first = $this->authRequest(
            $this->credKeyA, 'GET', self::PATH, time(), $requestId, self::SECRET_A,
        );
        $first->assertStatus(200);

        // Identical signed request replayed.
        $second = $this->authRequest(
            $this->credKeyA, 'GET', self::PATH, time(), $requestId, self::SECRET_A,
        );
        $this->assertError($second, 401, ConnectConfig::ERROR_REQUEST_REPLAYED);
    }

    // ------------------------------------------------------------------
    // TEST 6 — revoked credential
    // ------------------------------------------------------------------

    public function test06RevokedCredentialFails(): void
    {
        $response = $this->authRequest(
            $this->credRevoked,
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            'revoked_secret_local_0001',
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_CREDENTIAL_REVOKED);
    }

    // ------------------------------------------------------------------
    // TEST 7 — suspended tenant
    // ------------------------------------------------------------------

    public function test07SuspendedTenantFails(): void
    {
        $this->db->table('connect_tenants')
            ->where('id', $this->tenantA)
            ->update(['status' => ConnectConfig::TENANT_STATUS_SUSPENDED]);

        $response = $this->validAuth();
        $this->assertError($response, 403, ConnectConfig::ERROR_TENANT_SUSPENDED);
    }

    // ------------------------------------------------------------------
    // TEST 8 — environment mismatch
    // ------------------------------------------------------------------

    public function test08EnvironmentMismatch(): void
    {
        $response = $this->authRequest(
            $this->credMismatch,
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            'mismatch_secret_local_0001',
        );
        $this->assertError($response, 403, ConnectConfig::ERROR_ENVIRONMENT_MISMATCH);
    }

    // ------------------------------------------------------------------
    // TEST 9 — missing authentication headers
    // ------------------------------------------------------------------

    public function test09MissingAuthHeaders(): void
    {
        $response = $this->runConnect(
            'get',
            self::PATH,
            [ConnectConfig::HEADER_TIMESTAMP => (string) time()],
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_MISSING_AUTH_HEADERS);
    }

    // ------------------------------------------------------------------
    // TEST 10 — cross-tenant access must never leak tenant B data
    // ------------------------------------------------------------------

    public function test10TenantAccessCannotReachTenantBResources(): void
    {
        // Tenant A authenticated request describes ONLY tenant A.
        $response = $this->validAuth();
        $response->assertStatus(200);
        $this->assertSame((string) $this->tenantA, (string) $this->json($response)->data->tenant_id);

        // Tenant-scoped query guard: a Tenant A context using a Tenant B row id
        // returns null — tenant B data structurally unreachable.
        $now = date('Y-m-d H:i:s');
        $this->db->table('connect_external_users')->insert([
            'tenant_id'        => $this->tenantA,
            'external_user_id' => 'user-111',
            'status'           => ConnectConfig::EXTERNAL_USER_STATUS_ACTIVE,
            'created_at'       => $now,
            'updated_at'       => $now,
        ], true);
        $extAofA = (int) $this->db->insertID();
        $this->db->table('connect_external_users')->insert([
            'tenant_id'        => $this->tenantB,
            'external_user_id' => 'user-222',
            'status'           => ConnectConfig::EXTERNAL_USER_STATUS_ACTIVE,
            'created_at'       => $now,
            'updated_at'       => $now,
        ], true);
        $extB = (int) $this->db->insertID();

        $model = new ConnectExternalUserModel($this->db);

        $this->assertNull(ConnectScopeGuard::owned($model, $this->tenantA, $extB));
        $this->assertNotNull(ConnectScopeGuard::owned($model, $this->tenantA, $extAofA));
        $this->assertSame((string) $this->tenantA, (string) ConnectScopeGuard::owned($model, $this->tenantA, $extAofA)['tenant_id']);
    }

    // ------------------------------------------------------------------
    // TEST 11 — insufficient scope
    // ------------------------------------------------------------------

    public function test11InsufficientScope(): void
    {
        $response = $this->authRequest(
            $this->credNoScope,
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            'noscope_secret_local_0001',
        );
        $this->assertError($response, 403, ConnectConfig::ERROR_INSUFFICIENT_SCOPE);
    }

    // ------------------------------------------------------------------
    // TEST 12 — tampered request body
    // ------------------------------------------------------------------

    public function test12TamperedBodyInvalidSignature(): void
    {
        // Signed with an EMPTY body, transmitted with a non-empty body.
        $response = $this->authRequest(
            $this->credKeyA,
            'GET',
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            self::SECRET_A,
            '',                         // signature body: empty
            true,
            'GET',
            self::PATH,
            null,
            '{"tampered":true}',        // sent body: non-empty
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_INVALID_SIGNATURE);
    }

    // ------------------------------------------------------------------
    // TEST 13 — modified request path
    // ------------------------------------------------------------------

    public function test13ModifiedPathInvalidSignature(): void
    {
        // Signed for the evil path, but the request is sent to the real one.
        $response = $this->authRequest(
            $this->credKeyA,
            'GET',
            '/api/v1/connect/evil',
            time(),
            ConnectAuthenticationService::requestId(),
            self::SECRET_A,
            '',
            true,
            'GET',
            self::PATH,
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_INVALID_SIGNATURE);
    }

    // ------------------------------------------------------------------
    // TEST 14 — modified request method
    // ------------------------------------------------------------------

    public function test14ModifiedMethodInvalidSignature(): void
    {
        // Signed as POST but actually transmitted as GET.
        $response = $this->authRequest(
            $this->credKeyA,
            'POST',   // signed method
            self::PATH,
            time(),
            ConnectAuthenticationService::requestId(),
            self::SECRET_A,
            '',
            true,
            'GET',    // sent method
            self::PATH,
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_INVALID_SIGNATURE);
    }

    // ------------------------------------------------------------------
    // TEST 15 — different request id than signed
    // ------------------------------------------------------------------

    public function test15DifferentRequestIdInvalidSignature(): void
    {
        // Signed for req_signed_one but presented with req_different_two.
        $response = $this->authRequest(
            $this->credKeyA,
            'GET',
            self::PATH,
            time(),
            'req_signed_one',
            self::SECRET_A,
            '',
            true,
            'GET',
            self::PATH,
            'req_different_two',
        );
        $this->assertError($response, 401, ConnectConfig::ERROR_INVALID_SIGNATURE);
    }
}
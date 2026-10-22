<?php

namespace Tests;

use App\Controllers\DevicePairingController;
use App\Services\DevicePairingService;
use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Desktop device pairing: the security properties of the QR link handshake.
 *
 * The happy path is not the interesting part. What matters is that the flow
 * cannot be short-circuited, so this suite pins the failure modes:
 *
 *  - a session is unowned until a phone approves it, so an anonymous caller
 *    can never mint a desktop token for itself
 *  - approve/linked-devices reject a missing JWT
 *  - claim before approve is refused
 *  - the claim secret is one-time (a replay gets nothing) and stored hashed
 *  - a wrong challenge or a wrong claim secret gets nothing
 *  - an expired session resolves to a terminal 'expired', never a hang
 *  - a revoked device's token stops working immediately, not just visually
 *  - a user cannot revoke the device it is currently signed in as
 *  - the QR payload carries no credential at all
 *
 * Stubs are the minimal real-shape tables the pairing code reads (users,
 * user_devices, desktop_pairing_sessions). Dropped unguarded + resetDataCache()
 * per AGENTS.md: a tableExists() guard reads a stale dataCache and skips the
 * DROP, leaking rows into later tests in the same process.
 *
 * @internal
 */
final class DesktopPairingFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const JWT_SECRET = 'unit-test-pairing-secret';

    private ?array $savedDefaultGroup = null;

    private int $userA;
    private int $userB;
    private const MOBILE_A = '100001';
    private const MOBILE_B = '100002';

    protected function setUp(): void
    {
        parent::setUp();
        helper('token_helper');
        helper('user_helper');
        putenv('JWT_SECRET=' . self::JWT_SECRET);

        // The pairing Models hardcode DBGroup 'default', which resolves to
        // MySQLi even under ENVIRONMENT=testing (only a null group auto-selects
        // the SQLite 'tests' group). Point 'default' at the same SQLite group.
        $dbConfig = config('Database');
        $this->savedDefaultGroup = $dbConfig->default;
        $dbConfig->default       = $dbConfig->tests;

        $clearDefault = $this->clearDefaultInstance();
        $clearDefault();

        $this->db = \Config\Database::connect();
        $prefix   = $this->db->DBPrefix;

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'desktop_pairing_sessions');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'user_devices');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        $this->createUsersTable();
        $this->createDevicesTable();
        $this->createPairingTable();

        $this->db->table('users')->insert([
            'user_first_name' => 'Alice',
            'user_mobile_num' => self::MOBILE_A,
        ]);
        $this->userA = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'user_first_name' => 'Bob',
            'user_mobile_num' => self::MOBILE_B,
        ]);
        $this->userB = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'desktop_pairing_sessions');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'user_devices');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        if ($this->savedDefaultGroup !== null) {
            config('Database')->default = $this->savedDefaultGroup;
        }

        ($this->clearDefaultInstance())();

        parent::tearDown();
    }

    /**
     * Config\Database::connect() caches the connection OBJECT under the group
     * key, so an earlier class that touched a default-group Model leaves a stale
     * MySQLi object that would bypass the SQLite remap. Drop it before and
     * after this suite makes its own 'default' connection.
     */
    private function clearDefaultInstance(): \Closure
    {
        return function (): void {
            $ref       = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
            $ref->setAccessible(true);
            $instances = $ref->getValue();
            unset($instances['default']);
            $ref->setValue($instances);
        };
    }

    // ------------------------------------------------------------ table stubs

    private function createUsersTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'users ('
            . 'user_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'user_first_name TEXT DEFAULT NULL,'
            . 'user_last_name TEXT DEFAULT NULL,'
            . 'user_image TEXT DEFAULT NULL,'
            . 'user_mobile_num TEXT DEFAULT NULL,'
            . 'user_email TEXT DEFAULT NULL,'
            . 'user_bio TEXT DEFAULT NULL,'
            . 'user_profile_complete TEXT DEFAULT "0",'
            . 'user_created_at TEXT DEFAULT NULL,'
            . 'user_updated_at TEXT DEFAULT NULL,'
            . 'u_is_deleted INTEGER DEFAULT 0'
            . ')');
    }

    private function createDevicesTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'user_devices ('
            . 'device_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'user_id INTEGER NOT NULL,'
            . 'platform TEXT NOT NULL DEFAULT "unknown",'
            . 'device_name TEXT DEFAULT NULL,'
            . 'browser TEXT DEFAULT NULL,'
            . 'app_version TEXT DEFAULT NULL,'
            . 'ip_address TEXT DEFAULT NULL,'
            . 'status TEXT NOT NULL DEFAULT "active",'
            . 'created_at TEXT DEFAULT NULL,'
            . 'last_seen TEXT DEFAULT NULL,'
            . 'revoked_at TEXT DEFAULT NULL'
            . ')');
    }

    private function createPairingTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'desktop_pairing_sessions ('
            . 'pairing_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'user_id INTEGER DEFAULT NULL,'
            . 'pairing_ref TEXT NOT NULL,'
            . 'challenge TEXT NOT NULL,'
            . 'device_name TEXT DEFAULT NULL,'
            . 'platform TEXT DEFAULT NULL,'
            . 'browser TEXT DEFAULT NULL,'
            . 'app_version TEXT DEFAULT NULL,'
            . 'ip_address TEXT DEFAULT NULL,'
            . 'status TEXT NOT NULL DEFAULT "pending",'
            . 'device_id INTEGER DEFAULT NULL,'
            . 'claim_secret_hash TEXT NOT NULL,'
            . 'created_at TEXT DEFAULT NULL,'
            . 'expires_at TEXT DEFAULT NULL,'
            . 'approved_at TEXT DEFAULT NULL,'
            . 'consumed_at TEXT DEFAULT NULL,'
            . 'claim_nonce TEXT DEFAULT NULL'
            . ')');
    }

    // ---------------------------------------------------------------- helpers

    private function jwtFor(int $userId, string $mobile, ?int $deviceId = null): string
    {
        $now = time();

        return JWT::encode([
            'user_id'         => (string) $userId,
            'user_mobile_num' => $mobile,
            'device_id'       => $deviceId,
            'iat'             => $now,
            'exp'             => $now + 3600,
        ], self::JWT_SECRET, 'HS256');
    }

    private function call(string $method, string $uri, array $headers = [], array $params = []): \CodeIgniter\Test\TestResponse
    {
        Services::reset();

        $query        = parse_url($uri, PHP_URL_QUERY) ?? '';
        parse_str($query, $get);
        $savedGet     = $_GET;
        $savedRequest = $_REQUEST;
        $savedPost    = $_POST;

        // getVar() reads the 'request' global first, then POST, then the query,
        // so populate both superglobals and the request globals explicitly, then
        // restore afterwards to avoid leaking between tests.
        $merged = $method === 'GET' ? $get : $params;
        $_GET     = $get;
        $_REQUEST = $merged;
        $_POST    = $method === 'GET' ? [] : $params;

        try {
            $this->request = $this->setupRequest($method, $uri);
            $this->request->setMethod($method);
            $this->request->setGlobal('get', $get);
            $this->request->setGlobal('request', $merged);
            $this->request->setGlobal('post', $method === 'GET' ? [] : $params);
            foreach ($headers as $name => $value) {
                $this->request->setHeader($name, $value);
            }

            Services::injectMock('request', $this->request);

            $routes = Services::routes()->loadRoutes();
            $routes->setHTTPVerb($method);

            $this->app = $this->createApplication();
            $response  = $this->app
                ->setContext('web')
                ->setRequest($this->request)
                ->run($routes, true);
        } finally {
            $_GET     = $savedGet;
            $_REQUEST = $savedRequest;
            $_POST    = $savedPost;
            Services::reset();
        }

        return new \CodeIgniter\Test\TestResponse($response);
    }

    private function json(\CodeIgniter\Test\TestResponse $response): object
    {
        $body    = (string) $response->response()->getBody();
        $decoded = json_decode($body);

        $this->assertInstanceOf(
            \stdClass::class,
            $decoded,
            'Response body is not JSON: ' . substr($body, 0, 300),
        );

        return $decoded;
    }

    /**
     * Open a pairing session the way the desktop does: it generates the claim
     * secret itself and sends it, and that same secret is what it will later
     * claim with.
     */
    private function createSession(): array
    {
        $claimSecret = bin2hex(random_bytes(24));

        $result = $this->call('POST', 'user/desktop-pairing/create', [], [
            'device_name'  => 'Alice Laptop',
            'platform'     => 'windows',
            'browser'      => 'Chrome',
            'claim_secret' => $claimSecret,
        ]);
        $result->assertStatus(200);
        $data = $this->json($result)->data;

        return (array) $data + ['claim_secret' => $claimSecret];
    }

    /** The phone approves. */
    private function approve(array $session, ?int $asUser = null, ?string $challenge = null): \CodeIgniter\Test\TestResponse
    {
        return $this->call(
            'POST',
            'user/desktop-pairing/approve',
            ['Authorization' => 'Bearer ' . $this->jwtFor($asUser ?? $this->userA, self::MOBILE_A)],
            [
                'pairing_ref' => $session['pairing_ref'],
                'challenge'   => $challenge ?? $session['challenge'],
            ],
        );
    }

    private function pairRow(string $ref): array
    {
        $prefix = $this->db->DBPrefix;
        $row    = $this->db->query('SELECT * FROM ' . $prefix . 'desktop_pairing_sessions WHERE pairing_ref = '
            . $this->db->escape($ref))->getRowArray();
        $this->assertNotNull($row, 'pairing row missing');

        return $row;
    }

    // ------------------------------------------------------------------ tests

    public function testCreatePairingReturnsPublicValuesAndNoCredential(): void
    {
        $claimSecret = bin2hex(random_bytes(24));
        $result = $this->call('POST', 'user/desktop-pairing/create', [], [
            'device_name'  => 'Alice Laptop',
            'platform'     => 'windows',
            'claim_secret' => $claimSecret,
        ]);
        $result->assertStatus(200);

        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $json->data->pairing_ref);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $json->data->challenge);

        // The QR payload must not be able to carry a session with it.
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('eyJ', $body, 'create response must not contain a JWT');
        $this->assertObjectNotHasProperty('exchange', $json->data);
        $this->assertObjectNotHasProperty('token', $json->data);

        // And the stored row starts unowned — that is what stops an anonymous
        // caller from approving its own session.
        $row = $this->pairRow($json->data->pairing_ref);
        $this->assertNull($row['user_id']);
        $this->assertSame('pending', $row['status']);
    }

    public function testAnonymousCallerCannotApprove(): void
    {
        $session = $this->createSession();

        $result = $this->call('POST', 'user/desktop-pairing/approve', [], [
            'pairing_ref' => $session['pairing_ref'],
            'challenge'   => $session['challenge'],
        ]);

        $result->assertStatus(401);
        $this->assertFalse($this->json($result)->status);
        $this->assertSame('pending', $this->pairRow($session['pairing_ref'])['status']);
    }

    public function testAnonymousCallerCannotListLinkedDevices(): void
    {
        $result = $this->call('GET', 'user/linked-devices');

        $result->assertStatus(401);
        $this->assertFalse($this->json($result)->status);
    }

    public function testOtpTempTokenCannotApprove(): void
    {
        // The temp token minted by register-number carries only a `user` claim,
        // no `user_id`. It must NOT be able to approve a pairing: casting the
        // missing claim to int yields 0 and would create an orphan user_id=0
        // device with a desktop JWT for a phantom account.
        $session = $this->createSession();
        $now = time();
        $tempToken = JWT::encode([
            'user' => self::MOBILE_A,
            'iat'  => $now,
            'exp'  => $now + 900,
        ], self::JWT_SECRET, 'HS256');

        $result = $this->call('POST', 'user/desktop-pairing/approve', [
            'Authorization' => 'Bearer ' . $tempToken,
        ], [
            'pairing_ref' => $session['pairing_ref'],
            'challenge'   => $session['challenge'],
        ]);

        $result->assertStatus(401);
        $this->assertFalse($this->json($result)->status);
        $this->assertSame('pending', $this->pairRow($session['pairing_ref'])['status']);
        $this->assertSame(0, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testOtpTempTokenCannotReject(): void
    {
        $session = $this->createSession();
        $now = time();
        $tempToken = JWT::encode([
            'user' => self::MOBILE_A,
            'iat'  => $now,
            'exp'  => $now + 900,
        ], self::JWT_SECRET, 'HS256');

        $result = $this->call('POST', 'user/desktop-pairing/reject', [
            'Authorization' => 'Bearer ' . $tempToken,
        ], [
            'pairing_ref' => $session['pairing_ref'],
        ]);

        $result->assertStatus(401);
        $this->assertFalse($this->json($result)->status);
        $this->assertSame('pending', $this->pairRow($session['pairing_ref'])['status']);
    }

    public function testCreateWithoutAClaimSecretIsRefused(): void
    {
        // A session with no claim secret would be claimable by whoever found its
        // pairing_ref, so the request is rejected outright.
        $result = $this->call('POST', 'user/desktop-pairing/create', [], [
            'device_name' => 'No Secret',
        ]);

        $result->assertStatus(500);
        $this->assertFalse($this->json($result)->status);
        $this->assertSame(0, (int) $this->db->table('desktop_pairing_sessions')->countAllResults());
    }

    public function testClaimBeforeApproveIsRefused(): void
    {
        $session = $this->createSession();

        // No phone approval, so there is nothing to redeem and no token to mint.
        $result = $this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => str_repeat('a', 64),
            'challenge'     => $session['challenge'],
        ]);

        $result->assertStatus(400);
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('not_claimable', $json->data->reason);
        $this->assertSame(0, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testApproveWithWrongChallengeIsRefused(): void
    {
        $session = $this->createSession();

        $result = $this->approve($session, null, str_repeat('b', 32));

        $result->assertStatus(400);
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('challenge_mismatch', $json->data->reason);
        $this->assertSame('pending', $this->pairRow($session['pairing_ref'])['status']);
        $this->assertSame(0, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testHappyPathIssuesDesktopTokenWithRequiredClaims(): void
    {
        $session = $this->createSession();

        $approved = $this->approve($session);
        $approved->assertStatus(200);
        $approvedJson = $this->json($approved);
        $this->assertTrue($approvedJson->status);
        $this->assertSame('approved', $approvedJson->data->status);

        // Approval must NOT hand the phone a reusable credential — the claim
        // secret already exists on the desktop that displayed the QR, so there
        // is nothing for the phone to pass back.
        $this->assertObjectNotHasProperty('exchange', $approvedJson->data);
        $this->assertObjectNotHasProperty('token', $approvedJson->data);

        // The claim secret is stored hashed, never in the clear.
        $row = $this->pairRow($session['pairing_ref']);
        $this->assertNotSame($session['claim_secret'], $row['claim_secret_hash']);
        $this->assertTrue(password_verify($session['claim_secret'], $row['claim_secret_hash']));

        $claimed = $this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]);
        $claimed->assertStatus(200);
        $json = $this->json($claimed);
        $this->assertTrue($json->status);
        $this->assertSame('connected', $json->data->status);

        // The token must satisfy BOTH verifiers in the system: the socket reads
        // user_id, and the user() helper resolves the account by
        // user_mobile_num. A desktop token missing the latter would make every
        // controller that calls user() fail.
        $decoded = JWT::decode($json->data->token, new Key(self::JWT_SECRET, 'HS256'));
        $this->assertSame($this->userA, (int) $decoded->user_id);
        $this->assertSame(self::MOBILE_A, $decoded->user_mobile_num);
        $this->assertSame('desktop', $decoded->platform);
        $this->assertGreaterThan(0, (int) $decoded->device_id);

        $this->assertSame('consumed', $this->pairRow($session['pairing_ref'])['status']);
        $this->assertSame(1, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testClaimSecretIsSingleUse(): void
    {
        $session = $this->createSession();
        $this->approve($session);

        $first = $this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]);
        $first->assertStatus(200);

        // Replaying the same code must not mint a second session.
        $replay = $this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]);
        $replay->assertStatus(410);
        $json = $this->json($replay);
        $this->assertFalse($json->status);
        $this->assertSame('consumed', $json->data->status);
        $this->assertSame(1, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testWrongClaimSecretIsRefused(): void
    {
        $session = $this->createSession();
        $this->approve($session);

        $result = $this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => str_repeat('c', 64),
            'challenge'     => $session['challenge'],
        ]);

        $result->assertStatus(400);
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('bad_claim_secret', $json->data->reason);
        $this->assertSame('approved', $this->pairRow($session['pairing_ref'])['status']);
    }

    public function testExpiredSessionResolvesToTerminalState(): void
    {
        $session = $this->createSession();

        $prefix = $this->db->DBPrefix;
        $this->db->query('UPDATE ' . $prefix . 'desktop_pairing_sessions SET expires_at = '
            . $this->db->escape(date('Y-m-d H:i:s', time() - 5)) . ' WHERE pairing_ref = '
            . $this->db->escape($session['pairing_ref']));

        $result = $this->call('GET', 'user/desktop-pairing/status?pairing_ref=' . $session['pairing_ref']);
        $result->assertStatus(200);

        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertSame('expired', $json->data->status);
        $this->assertSame('timeout', $json->data->reason);
        $this->assertSame('expired', $this->pairRow($session['pairing_ref'])['status']);

        // An expired window can never be approved after the fact.
        $this->approve($session)->assertStatus(400);
    }

    public function testUnknownPairingRefResolvesExpired(): void
    {
        $result = $this->call('GET', 'user/desktop-pairing/status?pairing_ref=' . str_repeat('f', 32));
        $result->assertStatus(200);
        $this->assertSame('expired', $this->json($result)->data->status);
    }

    public function testApproveTwiceByAnotherUserIsRefused(): void
    {
        $session = $this->createSession();
        $this->approve($session);

        // The session is now bound to Alice; Bob's phone must not be able to
        // re-approve it and slip his account onto her desktop.
        $result = $this->approve($session, $this->userB, $session['challenge']);
        // A mobile JWT carries no device_id, so the header must be Bob's.
        $result = $this->call('POST', 'user/desktop-pairing/approve', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userB, self::MOBILE_B),
        ], [
            'pairing_ref' => $session['pairing_ref'],
            'challenge'   => $session['challenge'],
        ]);
        $result->assertStatus(400);
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('already_bound', $json->data->reason);
    }

    public function testApproveTwiceBySameUserMintsOneDeviceAndStaysClaimable(): void
    {
        // A double-tap on Allow, or a retried request, must not stack a second
        // desktop row: the session is bound to the first approval and answers
        // with the same device. A single desktop token is then claimable.
        $session = $this->createSession();

        $first = $this->approve($session);
        $first->assertStatus(200);
        $firstDevice = (int) $this->json($first)->data->device->device_id;

        $second = $this->approve($session);
        $second->assertStatus(200);

        $json = $this->json($second);
        $this->assertTrue($json->status);
        $this->assertSame('approved', $json->data->status);
        $this->assertSame($firstDevice, (int) $json->data->device->device_id);
        $this->assertSame(1, (int) $this->db->table('user_devices')->countAllResults());

        // The desktop can still claim exactly once off the same session.
        $claimed = $this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]);
        $claimed->assertStatus(200);
        $this->assertSame('connected', $this->json($claimed)->data->status);
        $this->assertSame(1, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testRejectedSessionCannotBeClaimed(): void
    {
        $session = $this->createSession();

        $rejected = $this->call('POST', 'user/desktop-pairing/reject', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ], ['pairing_ref' => $session['pairing_ref']]);
        $rejected->assertStatus(200);
        $this->assertSame('rejected', $this->json($rejected)->data->status);

        $status = $this->json($this->call(
            'GET',
            'user/desktop-pairing/status?pairing_ref=' . $session['pairing_ref'],
        ));
        $this->assertSame('rejected', $status->data->status);
        $this->assertSame(0, (int) $this->db->table('user_devices')->countAllResults());
    }

    public function testLinkedDevicesListsLinkedDesktop(): void
    {
        $session = $this->createSession();
        $this->approve($session);

        $result = $this->call('GET', 'user/linked-devices', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ]);
        $result->assertStatus(200);

        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertCount(1, $json->data);
        $this->assertSame('desktop', $json->data[0]->platform);
        $this->assertSame('active', $json->data[0]->status);
        $this->assertSame('Alice Laptop', $json->data[0]->device_name);

        // Another user never sees it.
        $other = $this->call('GET', 'user/linked-devices', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userB, self::MOBILE_B),
        ]);
        $this->assertCount(0, $this->json($other)->data);
    }

    public function testRevokedDeviceDisappearsFromLinkedDevicesList(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $deviceId = (int) JWT::decode($json->data->token, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        // It is listed while active.
        $before = $this->call('GET', 'user/linked-devices', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ]);
        $before->assertStatus(200);
        $this->assertCount(1, $this->json($before)->data);

        // A phone signs it out...
        $this->call('POST', 'user/linked-devices/revoke', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ], ['device_id' => $deviceId])->assertStatus(200);

        // ...and it must be gone from the list, not shown struck-through. The
        // history screen listed revoked rows, which read as "the sign-out did
        // nothing".
        $after = $this->call('GET', 'user/linked-devices', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ]);
        $after->assertStatus(200);
        $this->assertTrue($this->json($after)->status);
        $this->assertCount(0, $this->json($after)->data);
    }

    public function testRevokedDeviceTokenStopsWorkingImmediately(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));

        $desktopToken = $json->data->token;
        $deviceId     = (int) JWT::decode($desktopToken, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        // The desktop works to begin with.
        $before = $this->call('GET', 'user/linked-devices', ['Authorization' => 'Bearer ' . $desktopToken]);
        $before->assertStatus(200);

        // A phone revokes it.
        $revoked = $this->call('POST', 'user/linked-devices/revoke', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ], ['device_id' => $deviceId]);
        $revoked->assertStatus(200);
        $this->assertSame('revoked', $this->json($revoked)->data->device->status);

        // The already-issued token is now dead, not merely hidden.
        $after = $this->call('GET', 'user/linked-devices', ['Authorization' => 'Bearer ' . $desktopToken]);
        $after->assertStatus(401);
        $this->assertFalse($this->json($after)->status);
    }

    public function testUserCannotRevokeTheDeviceItIsSignedInAs(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $desktopToken = $json->data->token;
        $deviceId     = (int) JWT::decode($desktopToken, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        // Revoking itself is logout, not "log out a device" — the endpoint must
        // say so rather than silently killing the caller's session.
        $result = $this->call('POST', 'user/linked-devices/revoke', [
            'Authorization' => 'Bearer ' . $desktopToken,
        ], ['device_id' => $deviceId]);
        $result->assertStatus(409);
        $this->assertSame('cannot_revoke_current_device', $this->json($result)->data->reason);

        // Still usable.
        $this->call('GET', 'user/linked-devices', ['Authorization' => 'Bearer ' . $desktopToken])
            ->assertStatus(200);
    }

    public function testRevokeAllOthersKeepsTheCurrentDesktop(): void
    {
        // Two desktops linked to Alice.
        $deviceIds = [];
        for ($i = 0; $i < 2; $i++) {
            $session  = $this->createSession();
            $this->approve($session);
            $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
                'pairing_ref'   => $session['pairing_ref'],
                'claim_secret' => $session['claim_secret'],
                'challenge'     => $session['challenge'],
            ]));
            $deviceIds[] = (int) JWT::decode($json->data->token, new Key(self::JWT_SECRET, 'HS256'))->device_id;
        }

        $currentToken = $this->jwtFor($this->userA, self::MOBILE_A, $deviceIds[0]);

        $this->assertSame([1, 2], $deviceIds, 'two desktops should be linked with ids 1 and 2');

        // Both desktops really are linked and active at this point.
        $linked = $this->db->table('user_devices')->orderBy('device_id', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $linked, 'both desktops should be linked');
        $this->assertSame('active', $linked[0]['status']);
        $this->assertSame('active', $linked[1]['status']);

        $result = $this->call('POST', 'user/linked-devices/revoke', [
            'Authorization' => 'Bearer ' . $currentToken,
        ], ['all_others' => '1']);
        $result->assertStatus(200);
        $this->assertSame([$deviceIds[1]], $this->json($result)->data->revoked);

        // The current desktop keeps working; the other one is dead.
        $this->call('GET', 'user/linked-devices', ['Authorization' => 'Bearer ' . $currentToken])
            ->assertStatus(200);
        $this->call('GET', 'user/linked-devices', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A, $deviceIds[1]),
        ])->assertStatus(401);
    }

    public function testRenameDeviceUpdatesOnlyItsOwnRow(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $deviceId = (int) JWT::decode($json->data->token, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        $result = $this->call('POST', 'user/linked-devices/rename', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ], ['device_id' => $deviceId, 'device_name' => 'Work Station']);
        $result->assertStatus(200);

        // The rename must report the row it actually changed, not some other one.
        $device = $this->json($result)->data->device;
        $this->assertSame($deviceId, (int) $device->device_id);
        $this->assertSame('Work Station', $device->device_name);
    }

    public function testRenameAnotherUsersDeviceIsRefused(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $deviceId = (int) JWT::decode($json->data->token, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        $result = $this->call('POST', 'user/linked-devices/rename', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userB, self::MOBILE_B),
        ], ['device_id' => $deviceId, 'device_name' => 'Hijacked']);
        $result->assertStatus(400);
        $this->assertSame('not_found', $this->json($result)->data->reason);
    }

    public function testLogoutRevokesTheCallingDesktop(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $desktopToken = $json->data->token;

        $logout = $this->call('POST', 'user/linked-devices/logout', [
            'Authorization' => 'Bearer ' . $desktopToken,
        ]);
        $logout->assertStatus(200);

        // The token cannot be reused after signing out.
        $this->call('GET', 'user/linked-devices', ['Authorization' => 'Bearer ' . $desktopToken])
            ->assertStatus(401);
    }

    /**
     * A revoked desktop must be rejected on EVERY authenticated route, not only
     * on the linked-devices endpoints. This is the regression that made a remote
     * sign-out cosmetic: the linked-devices routes re-checked the device, so the
     * other controllers (which only validated the JWT signature) kept serving a
     * revoked desktop. `user/dashboard-details` calls check_jwt_authentication()
     * directly and touches only the users table, so it isolates the central
     * check from the device-management controller.
     */
    public function testRevokedDeviceTokenIsRejectedOnAnUnrelatedRoute(): void
    {
        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $desktopToken = $json->data->token;
        $deviceId     = (int) JWT::decode($desktopToken, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        // The route works while the device is active.
        $before = $this->call('GET', 'user/dashboard-details', ['Authorization' => 'Bearer ' . $desktopToken]);
        $before->assertStatus(200);
        $this->assertTrue($this->json($before)->status);

        // A phone revokes it.
        $this->call('POST', 'user/linked-devices/revoke', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ], ['device_id' => $deviceId])->assertStatus(200);

        // The same already-issued token is rejected here too.
        $after = $this->call('GET', 'user/dashboard-details', ['Authorization' => 'Bearer ' . $desktopToken]);
        $after->assertStatus(200);
        $this->assertFalse($this->json($after)->status);
        $this->assertSame('Authentication failed', $this->json($after)->message);
    }

    /**
     * The socket server's handshake check. A revoked device is reported inactive
     * so the socket refuses to reconnect it with an old-but-valid JWT.
     */
    public function testDeviceStatusEndpointReportsRevocation(): void
    {
        putenv('INTERNAL_CRON_KEY=unit-internal-key');

        $session  = $this->createSession();
        $this->approve($session);
        $json     = $this->json($this->call('POST', 'user/desktop-pairing/claim', [], [
            'pairing_ref'   => $session['pairing_ref'],
            'claim_secret' => $session['claim_secret'],
            'challenge'     => $session['challenge'],
        ]));
        $deviceId = (int) JWT::decode($json->data->token, new Key(self::JWT_SECRET, 'HS256'))->device_id;

        // Active to begin with.
        $active = $this->call('GET', 'user/device-status?device_id=' . $deviceId, [
            'X-Internal-Key' => 'unit-internal-key',
        ]);
        $active->assertStatus(200);
        $this->assertTrue($this->json($active)->data->active);

        // A phone revokes it.
        $this->call('POST', 'user/linked-devices/revoke', [
            'Authorization' => 'Bearer ' . $this->jwtFor($this->userA, self::MOBILE_A),
        ], ['device_id' => $deviceId])->assertStatus(200);

        $revoked = $this->call('GET', 'user/device-status?device_id=' . $deviceId, [
            'X-Internal-Key' => 'unit-internal-key',
        ]);
        $revoked->assertStatus(200);
        $this->assertFalse($this->json($revoked)->data->active);

        // A wrong internal key is refused, so the endpoint is not public.
        $this->call('GET', 'user/device-status?device_id=' . $deviceId, [
            'X-Internal-Key' => 'wrong-key',
        ])->assertStatus(401);

        putenv('INTERNAL_CRON_KEY');
    }

    // ------------------------------------------------------------- rate limit

    public function testRateLimiterRefusesRequestsAboveCapacity(): void
    {
        $service = new DevicePairingService();
        // A unique bucket key so this never collides with a previous run's
        // cached token bucket (the cache outlives the process).
        $key = 'unit-' . bin2hex(random_bytes(8));

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(
                $service->allowRequest('unit', $key, 3, 60),
                "request {$i} should be within budget",
            );
        }

        $this->assertFalse(
            $service->allowRequest('unit', $key, 3, 60),
            'the request past capacity must be refused',
        );
    }

    public function testCreatePairingReturns429WhenTheLimiterRefuses(): void
    {
        // The limiter is off under the testing environment by default; turn it on
        // so the controller wiring is exercised, and always turn it back off.
        DevicePairingController::$enforceRateLimit = true;

        try {
            $refused = 0;
            for ($i = 0; $i < 40; $i++) {
                $result = $this->call('POST', 'user/desktop-pairing/create', [], [
                    'device_name'  => 'Loop Laptop',
                    'platform'     => 'windows',
                    'claim_secret' => bin2hex(random_bytes(24)),
                ]);
                if ($result->response()->getStatusCode() === 429) {
                    $refused++;
                }
            }

            $this->assertGreaterThan(0, $refused, 'create must eventually answer 429 once the budget is spent');
        } finally {
            DevicePairingController::$enforceRateLimit = false;
        }
    }
}

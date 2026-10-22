<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;

/**
 * Ownership + persistence contract for the contact directory endpoints.
 *
 * The desktop's Viewe-contact/edit-contact flow needs a way to UPDATE and
 * DELETE a saved contact, which the deployed `contact` group did not have.
 * `contact/update-contact` and `contact/delete-contact` are new routes, so this
 * suite locks down the properties that make them safe to ship:
 *
 *   - updates are scoped by `c_id AND c_user_id` (a valid caller cannot touch
 *     another user's row);
 *   - the mobile number is read-only once saved (it is the server-side handle
 *     `check-user-by-number` and `create-room` match on);
 *   - deletion is scoped the same way and physically removes the row;
 *   - `add-contact`'s number check stays GLOBAL (a number belongs to one row
 *     across the whole table, not per caller) — change that and dedupe breaks.
 *
 * The stub tables follow the same minimal real-shape pattern as the
 * OwnershipAuthorization suite (users + contacts), dropped unguarded +
 * resetDataCache() per AGENTS.md.
 *
 * @internal
 */
final class ContactOwnershipFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const JWT_SECRET = 'unit-test-contact-ownership-secret';

    /** @var array|null original 'default' group config, restored in tearDown */
    private ?array $savedDefaultGroup = null;

    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        parent::setUp();
        helper('token_helper');
        helper('user_helper');
        $this->setEnv('JWT_SECRET', self::JWT_SECRET);

        // The consumer Models hardcode DBGroup 'default'; point it at the same
        // SQLite 'tests' group so the end-to-end flow hits the stub tables.
        $dbConfig = config('Database');
        $this->savedDefaultGroup = $dbConfig->default;
        $dbConfig->default       = $dbConfig->tests;

        // Drop any cached 'default' connection object before this suite makes
        // its own SQLite one (see OwnershipAuthorizationFeatureTest for why).
        $clearDefault = function (): void {
            $ref       = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
            $ref->setAccessible(true);
            $instances = $ref->getValue();
            unset($instances['default']);
            $ref->setValue($instances);
        };
        $clearDefault();

        $this->db = \Config\Database::connect();
        $prefix   = $this->db->DBPrefix;

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'contacts');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        $this->createUsersTable();
        $this->createContactsTable();

        $this->db->table('users')->insert([
            'user_first_name' => 'Alice',
            'user_mobile_num' => '100001',
        ]);
        $this->userA = (int) $this->db->insertID();
        $this->db->table('users')->insert([
            'user_first_name' => 'Bob',
            'user_mobile_num' => '100002',
        ]);
        $this->userB = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'contacts');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        if ($this->savedDefaultGroup !== null) {
            config('Database')->default = $this->savedDefaultGroup;
        }

        $ref          = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $ref->setAccessible(true);
        $instances    = $ref->getValue();
        unset($instances['default']);
        $ref->setValue($instances);

        parent::tearDown();
    }

    private function setEnv(string $name, string|false $value): void
    {
        putenv($name . '=' . $value);
    }

    private function createUsersTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'users ('
            . 'user_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'user_first_name TEXT DEFAULT NULL,'
            . 'user_last_name TEXT DEFAULT NULL,'
            . 'user_mobile_num TEXT DEFAULT NULL,'
            . 'u_is_deleted INTEGER DEFAULT 0'
            . ')');
    }

    private function createContactsTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'contacts ('
            . 'c_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'c_unique_id INTEGER,'
            . 'c_first_name TEXT DEFAULT NULL,'
            . 'c_last_name TEXT DEFAULT NULL,'
            . 'c_mobile_number TEXT DEFAULT NULL,'
            . 'c_email_address TEXT DEFAULT NULL,'
            . 'c_company TEXT DEFAULT NULL,'
            . 'c_notes TEXT DEFAULT NULL,'
            . 'c_user_id TEXT DEFAULT NULL'
            . ')');
    }

    private function jwtFor(int $userId, string $mobile): string
    {
        $now = time();

        return JWT::encode([
            'sub'             => (string) $userId,
            'user_id'         => (string) $userId,
            'user_mobile_num' => $mobile,
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
        $_GET         = $get;
        $_REQUEST     = array_merge($get, $params);
        $_POST        = $params;

        try {
            $this->request = $this->setupRequest($method, $uri);
            $this->request->setMethod($method);
            $this->request->setGlobal('get', $get);
            $this->request->setGlobal('request', array_merge($get, $params));
            $this->request->setGlobal('post', $params);
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

    private function insertContact(int $ownerId, string $mobile, string $firstName = 'Pria'): int
    {
        $this->db->table('contacts')->insert([
            'c_unique_id'     => mt_rand(100000, 999999),
            'c_first_name'    => $firstName,
            'c_last_name'     => 'Sharma',
            'c_mobile_number' => $mobile,
            'c_email_address' => 'pria@example.com',
            'c_company'       => 'Acme',
            'c_notes'         => '',
            'c_user_id'       => (string) $ownerId,
        ]);

        return (int) $this->db->insertID();
    }

    public function testAddContactPersistsScopedToCaller(): void
    {
        $result = $this->call(
            'POST',
            'contact/add-contact',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            ['first_name' => 'Neo', 'last_name' => 'Kapoor', 'mobile_number' => '987650001'],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status);

        $row = $this->db->table('contacts')
            ->where('c_mobile_number', '987650001')
            ->get()
            ->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame((string) $this->userA, (string) $row['c_user_id']);
        $this->assertSame('Neo', $row['c_first_name']);
    }

    public function testAddContactRejectsGlobalDuplicateNumber(): void
    {
        // A saves the number first...
        $this->insertContact($this->userA, '987650002');

        // ...then B tries the SAME number. The dedupe is global (a number can
        // only live in one contacts row across every user), so B must be
        // rejected even though A and B are different accounts.
        $result = $this->call(
            'POST',
            'contact/add-contact',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userB, '100002')],
            ['first_name' => 'Mallory', 'mobile_number' => '987650002'],
        );

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Contact already registered', $json->message);
    }

    public function testFetchContactsOnlyReturnsOwnRows(): void
    {
        $this->insertContact($this->userA, '987650003', 'Ani');
        $this->insertContact($this->userA, '987650004', 'Bela');
        $this->insertContact($this->userB, '987650005', 'Cara');

        $result = $this->call(
            'GET',
            'contact/fetch-contacts',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
        );

        $this->assertTrue($this->json($result)->status);
        $found = $this->json($result);
        $numbers = array_map(
            fn ($row) => (string) ($row->c_mobile_number ?? ''),
            is_array($found->contacts) ? $found->contacts : [],
        );
        $this->assertContains('987650003', $numbers);
        $this->assertContains('987650004', $numbers);
        $this->assertNotContains('987650005', $numbers);
    }

    public function testOwnerCanUpdateOwnContact(): void
    {
        $contactId = $this->insertContact($this->userA, '987650006', 'Old');

        $result = $this->call(
            'POST',
            'contact/update-contact',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            ['c_id' => (string) $contactId, 'first_name' => 'New', 'company' => 'Initech', 'notes' => 'from desktop'],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status);

        $row = $this->db->table('contacts')->where('c_id', $contactId)->get()->getRowArray();
        $this->assertSame('New', $row['c_first_name']);
        $this->assertSame('Initech', $row['c_company']);
        $this->assertSame('from desktop', $row['c_notes']);
        // The mobile number is the row's server-side handle and must not move.
        $this->assertSame('987650006', $row['c_mobile_number']);
    }

    public function testNonOwnerCannotUpdateOthersContact(): void
    {
        $contactId = $this->insertContact($this->userA, '987650007');

        $result = $this->call(
            'POST',
            'contact/update-contact',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userB, '100002')],
            ['c_id' => (string) $contactId, 'first_name' => 'Hacked'],
        );

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Contact not found', $json->message);

        $row = $this->db->table('contacts')->where('c_id', $contactId)->get()->getRowArray();
        $this->assertNotNull($row, 'a denied update must not delete or blank the row');
        $this->assertNotSame('Hacked', $row['c_first_name']);
    }

    public function testNonOwnerCannotDeleteOthersContact(): void
    {
        $contactId = $this->insertContact($this->userA, '987650008');

        $result = $this->call(
            'POST',
            'contact/delete-contact',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userB, '100002')],
            ['c_id' => (string) $contactId],
        );

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Contact not found', $json->message);

        $row = $this->db->table('contacts')->where('c_id', $contactId)->get()->getRowArray();
        $this->assertNotNull($row, 'a denied delete must leave the row intact');
    }

    public function testOwnerCanDeleteOwnContact(): void
    {
        $contactId = $this->insertContact($this->userA, '987650009');

        $result = $this->call(
            'POST',
            'contact/delete-contact',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            ['c_id' => (string) $contactId],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertSame('Contact Deleted Successfully', $json->message);

        $row = $this->db->table('contacts')->where('c_id', $contactId)->get()->getRowArray();
        $this->assertNull($row, 'the owned contact must be physically gone');
    }
}
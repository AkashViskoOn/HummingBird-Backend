<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;

/**
 * Cross-user ownership authorization on the consumer API.
 *
 * The auth-enforcement sweep proves an anonymous caller is rejected; this
 * suite proves the harder property — that a VALID, authenticated user cannot
 * reach another user's resources. Both calls carry a correctly signed JWT; the
 * only difference is whose room/message they target:
 *
 *   - B fetching messages in A's private room        → 403 (not a member)
 *   - B deleting A's message (delete_for=everyone)   → 403 (not a member)
 *   - B deleting A's message as "delete for me"      → 403 (not a member)
 *   - the room owner replaying B's delete claim      → 403 (not sender)
 *   - A (a member) fetching the same room            → 200 (control)
 *
 * The tables are the minimal real-shape stubs the consumer controllers read
 * (users, rooms, messages) — same pattern the Connect delivery suite uses.
 * They are dropped unguarded + resetDataCache() per AGENTS.md: a tableExists()
 * guard would read a stale dataCache and skip the DROP, leaking rows across
 * tests in the process.
 *
 * @internal
 */
final class OwnershipAuthorizationFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const JWT_SECRET = 'unit-test-ownership-secret';

    /** @var array|null original 'default' group config, restored in tearDown */
    private ?array $savedDefaultGroup = null;

    private int $userA;
    private int $userB;
    private int $roomAB;
    private int $msgFromA;

    protected function setUp(): void
    {
        parent::setUp();
        helper('token_helper');
        helper('user_helper');
        $this->setEnv('JWT_SECRET', self::JWT_SECRET);

        // The consumer controllers' Models hardcode DBGroup 'default', which
        // resolves to MySQLi even under ENVIRONMENT=testing (only a null group
        // auto-selects the SQLite 'tests' group). Point 'default' at the same
        // SQLite group so the end-to-end flow hits the stub tables.
        $dbConfig = config('Database');
        $this->savedDefaultGroup = $dbConfig->default;
        $dbConfig->default       = $dbConfig->tests;

        // Config\Database::connect() caches the connection OBJECT under the
        // group key before the lazy connect fires, so any earlier test class
        // that touched a default-group Model leaves a stale MySQLi object here
        // that would bypass the SQLite remap above. Drop it before this suite
        // makes its own 'default' connection. (tearDown clears it again.)
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

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'messages');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'rooms');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        $this->createUsersTable();
        $this->createRoomsTable();
        $this->createMessagesTable();

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

        $this->db->table('rooms')->insert([
            'r_unique_id' => 'ownr_' . $this->userA,
            'r_name'      => 'Alice Private',
            'r_type'      => '0',
            'r_created_by' => (string) $this->userA,
            'r_users'     => json_encode([(string) $this->userA, (string) $this->userB]),
        ]);
        $this->roomAB = (int) $this->db->insertID();

        $this->db->table('messages')->insert([
            'm_unique_id'    => 'ownm_' . $this->userA,
            'm_room_id'      => (string) $this->roomAB,
            'm_sender_id'    => (string) $this->userA,
            'm_receiver_id'  => (string) $this->userB,
            'm_message'      => 'encrypted-body',
            'm_epoch_time'   => (string) time(),
            'm_users_who_deleted' => '[]',
        ]);
        $this->msgFromA = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'messages');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'rooms');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        if ($this->savedDefaultGroup !== null) {
            config('Database')->default = $this->savedDefaultGroup;
        }

        // The controller Models connected through the shared group key
        // 'default', which caches the SQLite connection in the static
        // CodeIgniter\Database\Config::$instances. Drop that cached instance so
        // a later test class connecting 'default' does not silently reuse a
        // connection whose tables this suite just dropped.
        $ref          = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $ref->setAccessible(true);
        $instances    = $ref->getValue();
        unset($instances['default']);
        $ref->setValue($instances);

        parent::tearDown();
    }

    private function setEnv(string $name, string|false $value): void
    {
        // putenv is enough for JWT_SECRET here; no cross-test leak because
        // setUp always sets it before each test.
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

    private function createRoomsTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'rooms ('
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

    private function createMessagesTable(): void
    {
        $prefix = $this->db->DBPrefix;
        // `m_receiver_id` is NOT NULL to mirror production: a desktop send that
        // omits `receiver_id` used to insert NULL here and throw a constraint
        // error ("An error occurred" bubble) on every send. Groups store '' and
        // direct sends must always derive the peer from real data.
        $this->db->query('CREATE TABLE ' . $prefix . 'messages ('
            . 'm_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'm_unique_id TEXT,'
            . 'm_room_id TEXT,'
            . 'm_sender_id TEXT,'
            . 'm_receiver_id TEXT NOT NULL,'
            . 'm_message TEXT,'
            . 'm_message_type TEXT DEFAULT "0",'
            . 'm_read_status TEXT DEFAULT "1",'
            . 'm_media TEXT DEFAULT "",'
            . 'm_is_scheduled TEXT DEFAULT "0",'
            . 'm_scheduled_at TEXT DEFAULT "0",'
            . 'm_interval TEXT DEFAULT "0",'
            . 'm_count TEXT DEFAULT "0",'
            . 'm_replied_on TEXT DEFAULT NULL,'
            . 'm_users_who_deleted TEXT DEFAULT "[]",'
            . 'm_is_deleted TEXT DEFAULT "0",'
            . 'm_deleted_at TEXT DEFAULT NULL,'
            // RDS-pending column for album grouping: several media sent together
            // share one client-generated id. Present here so the send path's
            // column-existence guard exercises the real (migrated) shape.
            . 'm_group_id TEXT DEFAULT NULL,'
            . 'm_epoch_time TEXT'
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

        // The harness only pushes QUERY_STRING into $_SERVER; getVar() reads
        // the 'request' global (built from $_REQUEST), not $_GET, so populate
        // both superglobals and the request's globals explicitly, then restore
        // afterwards to avoid leaking between tests. Array-style values like
        // message_id=["2"] must be percent-encoded in $uri so parse_str does
        // not reinterpret them as PHP array notation.
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

    public function testNonMemberFetchingPrivateRoomGets403(): void
    {
        // A's room only ever holds A. Hmm — setUp put B in the room so the
        // "not a member" path needs a THIRD user who was never invited.
        $this->db->table('users')->insert([
            'user_first_name' => 'Cara',
            'user_mobile_num' => '100003',
        ]);
        $outsider = (int) $this->db->insertID();

        $result = $this->call(
            'GET',
            'message/fetch-messages?room_id=' . $this->roomAB,
            ['Authorization' => 'Bearer ' . $this->jwtFor($outsider, '100003')],
        );

        $result->assertStatus(403);
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('You are not a member of this room', $json->message);
    }

    public function testMemberCanFetchOwnRoomAsControl(): void
    {
        $result = $this->call(
            'GET',
            'message/fetch-messages?room_id=' . $this->roomAB,
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertStringContainsString('Messages Collected', (string) $json->message);
    }

    public function testNonMemberCannotDeleteForEveryone(): void
    {
        $this->db->table('users')->insert([
            'user_first_name' => 'Cara2',
            'user_mobile_num' => '100004',
        ]);
        $outsider = (int) $this->db->insertID();

        $result = $this->call(
            'GET',
            'message/delete-message?message_id=%5B%22' . $this->msgFromA . '%22%5D&delete_for=1',
            ['Authorization' => 'Bearer ' . $this->jwtFor($outsider, '100004')],
        );

        $result->assertStatus(403, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('You are not a member of this message room', $json->message);
    }

    public function testNonSenderCannotDeleteForEveryone(): void
    {
        // B IS a member of the room but did NOT send the message. delete_for=1
        // (for everyone) is sender-only, so even a valid member must be denied.
        $result = $this->call(
            'GET',
            'message/delete-message?message_id=%5B%22' . $this->msgFromA . '%22%5D&delete_for=1',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userB, '100002')],
        );

        $result->assertStatus(403, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Only the sender can delete a message for everyone', $json->message);
    }

    public function testSenderCanDeleteForEveryoneWithinWindow(): void
    {
        $result = $this->call(
            'GET',
            'message/delete-message?message_id=%5B%22' . $this->msgFromA . '%22%5D&delete_for=1',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
        );

        $result->assertStatus(200);

        $prefix = $this->db->DBPrefix;
        $row    = $this->db->query('SELECT m_is_deleted FROM ' . $prefix . 'messages WHERE m_id = ' . $this->msgFromA)->getRowArray();
        $this->assertSame('1', (string) $row['m_is_deleted']);
    }

    public function testNonMemberCannotDeleteForMe(): void
    {
        $this->db->table('users')->insert([
            'user_first_name' => 'Cara3',
            'user_mobile_num' => '100005',
        ]);
        $outsider = (int) $this->db->insertID();

        $result = $this->call(
            'GET',
            'message/delete-message?message_id=%5B%22' . $this->msgFromA . '%22%5D&delete_for=0',
            ['Authorization' => 'Bearer ' . $this->jwtFor($outsider, '100005')],
        );

        $result->assertStatus(403);
        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('You are not a member of this message room', $json->message);
    }

    public function testDesktopStyleSendWithoutReceiverIdStoresThePeer(): void
    {
        // The desktop signs in and sends into an EXISTING direct room WITHOUT
        // the `receiver_id` field that mobile always sends. Production's
        // messages.m_receiver_id is NOT NULL, so the controller used to insert
        // NULL and throw a constraint error on every desktop send — the "An
        // error occurred" bubble. It must derive the peer from the newest
        // inbound sender (Bob) instead of inserting NULL.
        $this->db->table('messages')->insert([
            'm_unique_id'         => 'ownm_peer',
            'm_room_id'           => (string) $this->roomAB,
            'm_sender_id'         => (string) $this->userB,
            'm_receiver_id'       => (string) $this->userA,
            'm_message'           => 'inbound-from-bob',
            'm_epoch_time'        => (string) time(),
            'm_users_who_deleted' => '[]',
        ]);

        $result = $this->call(
            'POST',
            'message/send-message',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            [
                'room_id'      => (string) $this->roomAB,
                'message_type' => '0',
                'message'      => 'encrypted-desktop-body',
                'is_scheduled' => '0',
            ],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status, 'send must succeed, body: ' . $result->response()->getBody());

        $row = $this->db->table('messages')->where('m_message', 'encrypted-desktop-body')->get()->getRowArray();
        $this->assertNotNull($row, 'the sent message row must exist');
        $this->assertNotNull($row['m_receiver_id'], 'm_receiver_id must never be NULL');
        $this->assertSame((string) $this->userB, (string) $row['m_receiver_id'], 'peer derived from newest inbound sender');
    }

    public function testDesktopStyleSendWithExplicitReceiverIdStoresThePeer(): void
    {
        // The desktop now carries `receiver_id` (the peer's user id) on every
        // direct send exactly like mobile does, so production's NOT NULL
        // `m_receiver_id` is satisfied by the field itself — even against the
        // deployed controller that has no server-side resolution fallback. The
        // explicit id must be stored verbatim.
        $result = $this->call(
            'POST',
            'message/send-message',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            [
                'room_id'      => (string) $this->roomAB,
                'receiver_id'  => (string) $this->userB,
                'message_type' => '0',
                'message'      => 'encrypted-desktop-body-explicit',
                'is_scheduled' => '0',
            ],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status, 'send must succeed, body: ' . $result->response()->getBody());

        $row = $this->db->table('messages')->where('m_message', 'encrypted-desktop-body-explicit')->get()->getRowArray();
        $this->assertNotNull($row, 'the sent message row must exist');
        $this->assertSame((string) $this->userB, (string) $row['m_receiver_id'], 'explicit receiver_id is stored verbatim');
    }

    public function testAlbumGroupIdFromClientIsStoredOnTheRow(): void
    {
        // Desktop sends an album as sequential single-file requests, stamping
        // every one with the SAME client-generated `m_group_id`. The row must
        // carry it so the thread can collapse the members into one bubble.
        $result = $this->call(
            'POST',
            'message/send-message',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            [
                'room_id'      => (string) $this->roomAB,
                'message_type' => '0',
                'message'      => 'encrypted-album-caption',
                'is_scheduled' => '0',
                'm_group_id'   => 'alb-mnpqr789',
            ],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $json = $this->json($result);
        $this->assertTrue($json->status, 'send must succeed, body: ' . $result->response()->getBody());
        $this->assertSame('alb-mnpqr789', (string) $json->msg->m_group_id, 'the response echoes the stored group id');

        $row = $this->db->table('messages')->where('m_message', 'encrypted-album-caption')->get()->getRowArray();
        $this->assertNotNull($row, 'the sent message row must exist');
        $this->assertSame('alb-mnpqr789', (string) $row['m_group_id']);
    }

    public function testAlbumGroupIdIsSanitizedBeforeStorage(): void
    {
        // A hostile/odd client could inject SQL-ish or oversized ids; only
        // [A-Za-z0-9_-] survives and the value is capped at 64 chars.
        $result = $this->call(
            'POST',
            'message/send-message',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            [
                'room_id'      => (string) $this->roomAB,
                'message_type' => '0',
                'message'      => 'encrypted-album-dirty',
                'is_scheduled' => '0',
                'm_group_id'   => "alb/../dr(ok)!",
            ],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $row = $this->db->table('messages')->where('m_message', 'encrypted-album-dirty')->get()->getRowArray();
        $this->assertNotNull($row, 'the sent message row must exist');
        $this->assertSame('albdrok', (string) $row['m_group_id'], 'illegal characters are stripped');
    }

    public function testOrdinarySendStoresNoGroupId(): void
    {
        // A single message carries no album id, so the column stays NULL and the
        // thread renders an ordinary bubble (never a one-tile album).
        $result = $this->call(
            'POST',
            'message/send-message',
            ['Authorization' => 'Bearer ' . $this->jwtFor($this->userA, '100001')],
            [
                'room_id'      => (string) $this->roomAB,
                'message_type' => '0',
                'message'      => 'encrypted-plain-no-group',
                'is_scheduled' => '0',
            ],
        );

        $result->assertStatus(200, 'body: ' . $result->response()->getBody());
        $row = $this->db->table('messages')->where('m_message', 'encrypted-plain-no-group')->get()->getRowArray();
        $this->assertNotNull($row, 'the sent message row must exist');
        $this->assertNull($row['m_group_id'], 'an ordinary send stores no album group id');
    }
}
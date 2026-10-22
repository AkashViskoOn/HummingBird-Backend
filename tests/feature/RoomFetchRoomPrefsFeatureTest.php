<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;

/**
 * `room/fetch-rooms` reads back per-user archive/favorite prefs.
 *
 * Historically `fetchRooms` merged only `unread_count` into each room row, so
 * the desktop client's archive/pin toggles were write-only: truthful right
 * after the click, discarded on the next refetch, and the mapper hard-coded
 * `archived: false`. This suite pins the fix — a room that has a
 * `room_user_prefs` row (a MySQL runtime table created by the toggle endpoints)
 * must come back with `is_archived` / `is_favorited` for the calling user, and
 * a room that never was toggled must omit them rather than guess.
 *
 * The routing fixtures follow `OwnershipAuthorizationFeatureTest`: the
 * controller Models hardcode DBGroup 'default', so that group is remapped onto
 * the SQLite 'tests' group and the stub tables are created with the tests group
 * prefix (`db_`). No migrations, no seeds.
 *
 * @internal
 */
final class RoomFetchRoomPrefsFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const JWT_SECRET = 'unit-test-room-prefs-secret';

    private ?array $savedDefaultGroup = null;

    protected $db;
    private int $userA;
    private int $roomArchived;
    private int $roomPlain;

    protected function setUp(): void
    {
        parent::setUp();
        helper('token_helper');
        helper('user_helper');
        putenv('JWT_SECRET=' . self::JWT_SECRET);

        $dbConfig = config('Database');
        $this->savedDefaultGroup = $dbConfig->default;
        $dbConfig->default       = $dbConfig->tests;

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
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'room_user_prefs');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'users');
        $this->db->resetDataCache();

        $this->createUsersTable();
        $this->createRoomsTable();
        $this->createMessagesTable();
        $this->createRoomUserPrefsTable();

        $this->db->table('users')->insert([
            'user_first_name' => 'Archie',
            'user_mobile_num' => '200001',
        ]);
        $this->userA = (int) $this->db->insertID();

        $this->roomArchived = (int) $this->db->table('rooms')->insert([
            'r_unique_id' => 'pref_archived',
            'r_name'      => 'Archived Room',
            'r_type'      => '0',
            'r_users'     => json_encode([(string) $this->userA]),
        ], true);
        $this->roomPlain = (int) $this->db->table('rooms')->insert([
            'r_unique_id' => 'pref_plain',
            'r_name'      => 'Plain Room',
            'r_type'      => '0',
            'r_users'     => json_encode([(string) $this->userA]),
        ], true);

        $this->db->table('room_user_prefs')->insert([
            'room_id'      => (string) $this->roomArchived,
            'user_id'      => (string) $this->userA,
            'is_archived'  => 1,
            'is_favorited' => 1,
            'marked_unread' => 1,
        ]);
        // A second user's prefs on the SAME room must never leak into A's row.
        $this->db->table('users')->insert([
            'user_first_name' => 'Beryl',
            'user_mobile_num' => '200002',
        ]);
        $userB = (int) $this->db->insertID();
        $this->db->table('room_user_prefs')->insert([
            'room_id'      => (string) $this->roomArchived,
            'user_id'      => (string) $userB,
            'is_favorited' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'messages');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'rooms');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'room_user_prefs');
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
        $this->db->query('CREATE TABLE ' . $prefix . 'messages ('
            . 'm_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'm_room_id TEXT,'
            . 'm_sender_id TEXT,'
            . 'm_receiver_id TEXT,'
            . 'm_message TEXT,'
            . 'm_is_scheduled TEXT DEFAULT "0",'
            . 'm_status TEXT DEFAULT "1",'
            . 'm_seen_by TEXT DEFAULT NULL,'
            . 'm_is_deleted TEXT DEFAULT "0",'
            . 'm_users_who_deleted TEXT DEFAULT "[]",'
            . 'm_created_at TEXT,'
            . 'm_updated_at TEXT'
            . ')');
    }

    private function createRoomUserPrefsTable(): void
    {
        $prefix = $this->db->DBPrefix;
        $this->db->query('CREATE TABLE ' . $prefix . 'room_user_prefs ('
            . 'pref_id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'room_id INTEGER NOT NULL,'
            . 'user_id INTEGER NOT NULL,'
            . 'is_archived INTEGER DEFAULT 0,'
            . 'is_favorited INTEGER DEFAULT 0,'
            . 'marked_unread INTEGER DEFAULT 0,'
            . 'UNIQUE (room_id, user_id)'
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

    private function callRoomFetchRooms(): \CodeIgniter\Test\TestResponse
    {
        Services::reset();

        $uri   = 'room/fetch-rooms?room_type=0';
        $query = parse_url($uri, PHP_URL_QUERY) ?? '';
        parse_str($query, $get);
        $savedGet     = $_GET;
        $savedRequest = $_REQUEST;
        $savedPost    = $_POST;
        $_GET         = $get;
        $_REQUEST     = $get;
        $_POST        = [];

        try {
            $this->request = $this->setupRequest('GET', $uri);
            $this->request->setMethod('GET');
            $this->request->setGlobal('get', $get);
            $this->request->setGlobal('request', $get);
            $this->request->setGlobal('post', []);
            $this->request->setHeader('Authorization', 'Bearer ' . $this->jwtFor($this->userA, '200001'));

            Services::injectMock('request', $this->request);

            $routes = Services::routes()->loadRoutes();
            $routes->setHTTPVerb('GET');

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

public function testArchivedAndFavoritedPrefsAreReadBackPerUser(): void
    {
        $response = $this->callRoomFetchRooms();
        $result   = $this->json($response);

        $response->assertStatus(200, 'body: ' . $response->response()->getBody());
        $this->assertTrue($result->status ?? false, (string) ($result->message ?? 'raw status:false'));

        $rooms = [];
        foreach ($result->rooms ?? [] as $room) {
            $rooms[$room->r_unique_id] = $room;
        }

        $this->assertArrayHasKey('pref_archived', $rooms, 'archived room must be in the list');
        $archived = $rooms['pref_archived'];
        $this->assertTrue((bool) $archived->is_archived, 'is_archived must be read back as true');
        $this->assertTrue((bool) $archived->is_favorited, 'is_favorited must be read back as true');

        $this->assertArrayHasKey('pref_plain', $rooms, 'plain room must be in the list');
        $plain = $rooms['pref_plain'];
        $this->assertFalse(
            property_exists($plain, 'is_archived') || property_exists($plain, 'is_favorited'),
            'a room with no pref row must omit both flags, not guess false',
        );
    }
}
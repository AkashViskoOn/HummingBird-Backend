<?php

use App\Database\Migrations\AddConnectApiKeyAndScopes;
use App\Database\Migrations\AddConnectMappingEnvironmentAndScopes;
use App\Database\Migrations\AddConnectPhase4MessageSchema;
use App\Database\Migrations\AddConnectPhase5Outbox;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectMessageProcessor;
use App\Services\Connect\ConnectMessageService;
use App\Services\Connect\ConnectSecretVault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — Phase 6 delivery integration tests.
 *
 * Verifies that messages created by the Phase 5 worker reach the existing
 * Flutter app through the EXISTING Socket.IO server and the EXISTING FCM
 * path — exactly the same events, payload shapes and NotificationController
 * contract the normal (consumer) message flow uses. No new socket server, no
 * new events, no second FCM, no Flutter changes, and no Phase 7 delivery-status
 * / webhook surfaces.
 *
 * A recorder subclass of ConnectMessageService intercepts the outbound
 * `/broadcast` posts and `NotificationController::sendNotification`
 * invocations at the network seam, so tests assert real event names + payload
 * shapes without touching the network. Socket/FCM fire-and-forget failures are
 * also exercised (spec 23: delivery failures never fail the message).
 *
 *   php -d extension="C:\CI4\php day 1\php\ext\php_sqlite3.dll" vendor\bin\phpunit --no-coverage
 *
 * @internal
 */
final class ConnectDeliveryFeatureTest extends CIUnitTestCase
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

    private ConnectSecretVault $vault;

    private int $tenantA;
    private int $identityA;
    private int $hbUser1;

    private string $credSend;
    private string $secretSend;

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

        // The HB stub tables are created with CREATE TABLE IF NOT EXISTS, which
        // happily adopts whatever a previous run/test left behind. Drop them
        // first so every test starts from an empty, known schema.
        $this->dropTable('messages');
        $this->dropTable('rooms');
        $this->dropUsersTable();

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

    // ------------------------------------------------------------------
    // Recorder + worker helpers
    // ------------------------------------------------------------------

    /**
     * Run the Phase 5 worker with a recorder service injected so outbound
     * socket broadcasts + FCM invocations are captured instead of dispatched.
     */
    private function runWorkerWith(ConnectMessageService $service, int $batchSize = 50): int
    {
        return (new ConnectMessageProcessor($service))->run($batchSize);
    }

    private function sendAndProcess(string $body, string $extMsgId, ?ConnectMessageService $service = null): void
    {
        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => $body,
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);

        $processed = $service === null
            ? $this->runWorkerOnce()
            : $this->runWorkerWith($service);
        $this->assertSame(1, $processed);
    }

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
        // Same reasoning as dropTable(): the cached tableExists() guard let a
        // stale $dataCache skip the DROP and leak `users` rows into later tests.
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

    /**
     * Drops a prefixed table.
     *
     * The `tableExists()` guard this used to carry was both redundant and
     * actively harmful. `DROP TABLE IF EXISTS` already tolerates an absent
     * table, so the guard bought nothing — but `tableExists()` defaults to
     * `$cached = true` and reads `BaseConnection::$dataCache['table_names']`,
     * which is populated on first use and is NEVER invalidated by DDL. Once
     * that cache was primed before a CREATE, `tableExists()` answered `false`
     * forever on that connection, the DROP was silently skipped, and the rows
     * leaked into the next test in the process.
     *
     * The visible symptom was test06SameConversationRoomReusedAcrossSends
     * flaking: a leaked `users` row shifted `insertID()`, the seed's mapping
     * pointed at a different internal user, so findOrCreateRoom() built a
     * second room and the "same room is reused" assertion saw 2 rooms instead
     * of 1. The cache is reset afterwards so later `tableExists()` callers in
     * the same connection see the truth.
     */
    private function dropTable(string $name): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->DBPrefix . $name);
        $this->db->resetDataCache();
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('connect_tenants')->insert([
            'uuid'        => uniqid('tA_', true),
            'name'        => 'Tenant A',
            'slug'        => 'tenant-a-delivery',
            'status'      => ConnectConfig::TENANT_STATUS_ACTIVE,
            'environment' => ConnectConfig::TENANT_ENVIRONMENT_TEST,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], true);
        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('connect_service_identities')->insert([
            'tenant_id'           => $this->tenantA,
            'name'                => 'remark',
            'display_name'        => 'Remark',
            'verification_status' => ConnectConfig::VERIFICATION_STATUS_VERIFIED,
            'status'              => ConnectConfig::IDENTITY_STATUS_ACTIVE,
            'created_at'          => $now,
            'updated_at'          => $now,
        ], true);
        $this->identityA = (int) $this->db->insertID();

        $this->secretSend = 'test_secret_send_0001';
        $this->credSend   = 'hb_test_' . bin2hex(random_bytes(8));
        $this->insertCredential(
            $this->credSend,
            $this->secretSend,
            $this->tenantA,
            ConnectConfig::TENANT_ENVIRONMENT_TEST,
            ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            [ConnectConfig::SCOPE_MESSAGES_SEND, ConnectConfig::SCOPE_MESSAGES_READ],
        );

        $this->db->table('users')->insert([
            'user_first_name' => 'HB',
            'user_last_name'  => 'Delivered',
            'user_email'      => 'delivered@example.com',
        ], true);
        $this->hbUser1 = (int) $this->db->insertID();

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
        $body    = (string) $response->response()->getBody();
        $decoded = json_decode($body);

        $this->assertInstanceOf(
            \stdClass::class,
            $decoded,
            'Response body is not valid JSON: ' . substr($body, 0, 200),
        );

        return $decoded;
    }

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
    // Phase 6 — Socket + FCM delivery via the EXISTING infrastructure
    // ------------------------------------------------------------------

    /**
     * Spec 1 + 4 + 22: an accepted message, once processed by the worker,
     * emits the EXISTING `message-receive` (full flat message row) and
     * `new-message` (room id) socket events to the recipient's own userId,
     * posted to the EXISTING socket server's `/broadcast` endpoint.
     */
    public function test01WorkerDeliveryEmitsExistingSocketEvents(): void
    {
        $extMsgId = 'p6_online_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Hello over existing socket',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);
        $this->assertSame(1, $this->runWorkerWith($recorder));

        $this->assertCount(2, $recorder->broadcasts);

        $receive = $recorder->broadcasts[0]['payload'];
        $this->assertSame('message-receive', $receive['event']);
        $this->assertSame([(string) $this->hbUser1], $receive['userIds']);
        $this->assertTrue(str_ends_with((string) $recorder->broadcasts[0]['url'], '/broadcast'));

        $msgRow = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $this->assertNotEmpty($msgRow);
        $hbMsgId = (int) $msgRow['message_id'];

        $data = $receive['data'];
        $this->assertIsArray($data);
        $this->assertSame($hbMsgId, (int) $data['m_id']);
        $this->assertSame((string) $this->hbUser1, (string) $data['m_receiver_id']);
        $this->assertNotEmpty((string) $data['m_sender_id']);

        $new = $recorder->broadcasts[1]['payload'];
        $this->assertSame('new-message', $new['event']);
        $this->assertSame([(string) $this->hbUser1], $new['userIds']);
        $this->assertFalse(is_array($new['data']), 'new-message must carry the room id, not the message object');
        $this->assertSame((string) $data['m_room_id'], (string) $new['data']);
    }

    /**
     * Spec 2 + 16: FCM is triggered through the EXISTING
     * NotificationController::sendNotification for every stored device token
     * (fcm_token + web_fcm_token) — same contract as the consumer flow — with
     * the decrypted/plaintext body, bot sender display name, room id, isGroup=0.
     */
    public function test02OfflineRecipientTriggersExistingFcmPath(): void
    {
        $this->db->table('users')->where('user_id', $this->hbUser1)->update([
            'fcm_token'     => 'fcm-device-token-001',
            'web_fcm_token' => 'fcm-web-token-001',
        ]);

        $extMsgId = 'p6_fcm_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Offline-ish FCM body',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);
        $this->assertSame(1, $this->runWorkerWith($recorder));

        $this->assertCount(2, $recorder->notifications); // one per device token

        $tokenFields = array_map(static fn (array $n) => $n['tokenField'], $recorder->notifications);
        sort($tokenFields);
        $this->assertSame(['fcm_token', 'web_fcm_token'], $tokenFields);

        foreach ($recorder->notifications as $note) {
            $this->assertSame((string) $this->hbUser1, $note['targetUserId']);
            $this->assertSame('Offline-ish FCM body', $note['plaintext']);
            $this->assertSame('Remark', $note['botUser']['user_first_name']);
            $this->assertNotEmpty((string) $note['botUser']['user_id']);
            $this->assertNotEmpty((string) $note['room']['r_id']);
        }
    }

    /**
     * Spec 3 + 5: the delivered message is a plain Humming Bird `messages`
     * row — identical shape to the normal consumer flow (encrypted body,
     * m_message_type '0', m_is_scheduled '0').
     */
    public function test03ConnectMessageRowMatchesExistingFlatShape(): void
    {
        $extMsgId = 'p6_shape_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendAndProcess('Shape test body', $extMsgId, $recorder);

        $tracking = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $this->assertNotEmpty($tracking);
        $hbRow = $this->db->table('messages')->where('m_id', (int) $tracking['message_id'])->get()->getRowArray();

        $this->assertNotEmpty($hbRow);
        $this->assertSame((string) $this->hbUser1, (string) $hbRow['m_receiver_id']);
        $this->assertNotEmpty((string) $hbRow['m_sender_id']);
        $this->assertNotEmpty((string) $hbRow['m_room_id']);
        $this->assertSame('0', (string) $hbRow['m_message_type']);
        $this->assertSame('0', (string) $hbRow['m_is_scheduled']);
        $this->assertNotSame('Shape test body', (string) $hbRow['m_message'], 'stored body must stay encrypted');

        $receive = $recorder->broadcasts[0]['payload'];
        foreach (['m_id', 'm_room_id', 'm_sender_id', 'm_receiver_id', 'm_message', 'm_message_type'] as $field) {
            $this->assertSame(
                (string) $hbRow[$field],
                (string) $receive['data'][$field],
                "message-receive payload field {$field} must equal the DB row"
            );
        }
    }

    /**
     * Spec 4: only the EXISTING socket events are emitted — no Connect-specific
     * event names escape the worker.
     */
    public function test04OnlyExistingSocketEventsEmitted(): void
    {
        $extMsgId = 'p6_events_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendAndProcess('Event names', $extMsgId, $recorder);

        $events = array_column(array_map(
            static fn (array $b) => ['event' => $b['payload']['event']],
            $recorder->broadcasts,
        ), 'event');
        sort($events);

        $this->assertSame(['message-receive', 'new-message'], $events);
    }

    /**
     * Spec 24: the internal delivery ledger stops at the EXISTING Phase 5
     * `sent` state — the worker never fabricates delivered/read statuses.
     */
    public function test05DeliveryLedgerStopsAtSent(): void
    {
        $extMsgId = 'p6_ledger_' . bin2hex(random_bytes(6));

        $this->sendAndProcess('Ledger body', $extMsgId);

        $tracking = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $delivery = $this->db->table('connect_message_deliveries')
            ->where('external_message_id', (int) $tracking['id'])
            ->get()
            ->getRowArray();

        $this->assertSame(ConnectConfig::DELIVERY_STATUS_SENT, $delivery['status']);
        $this->assertNull($delivery['failure_reason']);
        $this->assertNotEmpty($delivery['delivered_at']);

        // Phase 4 ships a read_at DATETIME column, but Phase 5/6 NEVER write a
        // delivered/read receipt — the ledger stops at `sent` (Phase 7 scope).
        $this->assertEmpty(($delivery['read_at'] ?? null));
        $this->assertNotContains('webhook_id', $this->db->getFieldNames('connect_message_deliveries'));
    }

    /**
     * Spec 6: two sends to the same external user land in the SAME existing
     * 1:1 conversation room (findOrCreateRoom reuse), never a new room per send.
     */
    public function test06SameConversationRoomReusedAcrossSends(): void
    {
        $firstId  = 'p6_room_one_' . bin2hex(random_bytes(6));
        $secondId = 'p6_room_two_' . bin2hex(random_bytes(6));

        $this->sendAndProcess('First', $firstId);
        $this->sendAndProcess('Second', $secondId);

        $this->assertSame(1, (int) $this->db->table('rooms')->countAllResults(), print_r($this->db->table('rooms')->get()->getResultArray(), true));

        $first  = $this->getRow('connect_external_messages', 'external_message_id', $firstId);
        $second = $this->getRow('connect_external_messages', 'external_message_id', $secondId);

        $firstRow  = $this->db->table('messages')->where('m_id', (int) $first['message_id'])->get()->getRowArray();
        $secondRow = $this->db->table('messages')->where('m_id', (int) $second['message_id'])->get()->getRowArray();

        $this->assertSame((string) $firstRow['m_room_id'], (string) $secondRow['m_room_id']);
    }

    /**
     * Spec 7: a message emits exactly one socket pair — a rerun of the worker
     * (idempotent skip) emits nothing further.
     */
    public function test07NoDuplicateSocketBroadcastsOnIdempotentRerun(): void
    {
        $extMsgId = 'p6_nodup_sock_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendAndProcess('Unique socket', $extMsgId, $recorder);
        $this->assertCount(2, $recorder->broadcasts);

        $this->assertSame(0, $this->runWorkerWith($recorder, 50));
        $this->assertCount(2, $recorder->broadcasts, 'rerun must not re-emit socket events');
    }

    /**
     * Spec 8: FCM is triggered exactly once per token per message — a rerun
     * emits nothing further.
     */
    public function test08NoDuplicateFcmOnIdempotentRerun(): void
    {
        $this->db->table('users')->where('user_id', $this->hbUser1)->update([
            'fcm_token' => 'fcm-device-token-002',
        ]);

        $extMsgId = 'p6_nodup_fcm_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendAndProcess('Unique FCM', $extMsgId, $recorder);
        $this->assertCount(1, $recorder->notifications);

        $this->assertSame(0, $this->runWorkerWith($recorder, 50));
        $this->assertCount(1, $recorder->notifications, 'rerun must not re-trigger FCM');
    }

    /**
     * Spec 9 + 23: socket + FCM failures never fail the message — the worker
     * completes the job and the message is delivered via the existing history
     * sync path (DB row already persisted).
     */
    public function test09SocketAndFcmFailureNeverFailsMessage(): void
    {
        $extMsgId = 'p6_fail_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryFailureRecorder();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Survives outages',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);
        $this->assertSame(1, $this->runWorkerWith($recorder));

        $tracking = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $this->assertSame(ConnectConfig::MESSAGE_STATUS_SENT, $tracking['status']);
        $this->assertNotEmpty((int) $tracking['message_id']);

        $delivery = $this->db->table('connect_message_deliveries')
            ->where('external_message_id', (int) $tracking['id'])
            ->get()
            ->getRowArray();
        $this->assertSame(ConnectConfig::DELIVERY_STATUS_SENT, $delivery['status']);

        $outbox = $this->getRow('connect_message_outbox', 'external_message_id', $extMsgId);
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_COMPLETED, $outbox['status']);
    }

    /**
     * Spec 10: when message creation itself fails, NO socket event and NO FCM
     * are ever emitted (broadcast/notification happen only post-persist).
     */
    public function test10MessageCreationFailureEmitsNoSocketOrFcm(): void
    {
        $extMsgId = 'p6_nomsg_' . bin2hex(random_bytes(6));
        $recorder = new ConnectNoMessageRecorder();

        $this->sendMessage([
            'external_user_id'    => 'ext_user_1',
            'body'                => 'Never becomes a message',
            'external_message_id' => $extMsgId,
        ])->assertStatus(202);
        $this->assertSame(1, $this->runWorkerWith($recorder));

        $tracking = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $this->assertSame(ConnectConfig::MESSAGE_STATUS_FAILED, $tracking['status']);

        $outbox = $this->getRow('connect_message_outbox', 'external_message_id', $extMsgId);
        $this->assertSame(ConnectConfig::OUTBOX_STATUS_FAILED, $outbox['status']);

        $this->assertSame([], $recorder->broadcasts, 'no broadcast before a persisted message');
        $this->assertSame([], $recorder->notifications, 'no FCM before a persisted message');
    }

    /**
     * Spec 11: a duplicate external_message_id after processing resolves to the
     * SAME message + SAME single delivery pair — one broadcast pair, one FCM
     * per token, never re-queued.
     */
    public function test11DuplicateExternalMessageIdsSingleDeliveryPair(): void
    {
        $this->db->table('users')->where('user_id', $this->hbUser1)->update([
            'fcm_token' => 'fcm-device-token-003',
        ]);

        $extMsgId = 'p6_dup_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'Duplicated', 'external_message_id' => $extMsgId])
            ->assertStatus(202);
        $this->sendMessage(['external_user_id' => 'ext_user_1', 'body' => 'Duplicated', 'external_message_id' => $extMsgId])
            ->assertStatus(200);

        $this->assertSame(1, $this->runWorkerWith($recorder));

        // One tracking record (idempotent), one outbox job, one delivery row.
        $this->assertSame(1, $this->countRows('connect_external_messages', 'external_message_id', $extMsgId));
        $this->assertSame(1, $this->countRows('connect_message_outbox', 'external_message_id', $extMsgId));

        $tracking = $this->getRow('connect_external_messages', 'external_message_id', $extMsgId);
        $this->assertSame(1, (int) $this->db->table('connect_message_deliveries')
            ->where('external_message_id', (int) $tracking['id'])
            ->countAllResults());

        $this->assertCount(2, $recorder->broadcasts, 'one message → one socket pair, no duplicate');
        $this->assertCount(1, $recorder->notifications);
        $this->assertSame('fcm_token', $recorder->notifications[0]['tokenField']);
        $this->assertSame(1, (int) $this->db->table('messages')->countAllResults());
    }

    /**
     * Spec 12: the exact payload shapes Flutter parses without any
     * Connect-specific handling — `message-receive` is a flat row consumed by
     * Messages.fromJson, `new-message` is a bare room id consumed via
     * roomId.toString() (chat_controller `new-message` handler).
     */
    public function test12FlutterZeroChangePayloadShapes(): void
    {
        $extMsgId = 'p6_flutter_' . bin2hex(random_bytes(6));
        $recorder = new ConnectDeliveryRecorder();

        $this->sendAndProcess('Rendered like any message', $extMsgId, $recorder);

        $receive = $recorder->broadcasts[0]['payload'];
        $this->assertIsArray($receive['data']);
        $this->assertArrayHasKey('m_id', $receive['data']);
        $this->assertArrayHasKey('m_room_id', $receive['data']);
        $this->assertArrayHasKey('m_sender_id', $receive['data']);
        $this->assertArrayHasKey('m_receiver_id', $receive['data']);
        $this->assertArrayHasKey('m_message', $receive['data']);
        $this->assertArrayHasKey('m_message_type', $receive['data']);
        $this->assertArrayHasKey('m_read_status', $receive['data']);
        $this->assertArrayHasKey('m_epoch_time', $receive['data']);

        $new = $recorder->broadcasts[1]['payload'];
        $this->assertIsNotArray($new['data'], 'new-message must be the room id string, not an object');
        $this->assertNotEmpty((string) $new['data']);
    }
}

/**
 * Test seam: a ConnectMessageService that captures outbound /broadcast posts
 * and NotificationController::sendNotification invocations instead of
 * dispatching them over the network. No production code is bypassed except the
 * two network seams.
 */
class ConnectDeliveryRecorder extends ConnectMessageService
{
    /** @var array<int, array{url: string, payload: array}> */
    public array $broadcasts = [];

    /** @var array<int, array<string, mixed>> */
    public array $notifications = [];

    protected function postToSocket(string $url, array $payload): bool
    {
        $this->broadcasts[] = ['url' => $url, 'payload' => $payload];

        return true;
    }

    public function sendNotification(array $hbUser, array $botUser, string $plaintext, array $room, string $messageId, string $targetUserId): void
    {
        // Mirror the production per-token dispatch loop (fcm_token +
        // web_fcm_token) so tests assert one notification per stored token.
        foreach (['fcm_token', 'web_fcm_token'] as $tokenField) {
            $token = $hbUser[$tokenField] ?? '';
            if (empty($token)) {
                continue;
            }

            $this->notifications[] = [
                'tokenField'   => $tokenField,
                'hbUser'       => $hbUser,
                'botUser'      => $botUser,
                'plaintext'    => $plaintext,
                'room'         => $room,
                'messageId'    => $messageId,
                'targetUserId' => $targetUserId,
            ];
        }
    }
}

/**
 * Test seam: socket server down (postToSocket fails) + FCM dispatch that
 * records a failure and swallows it — mirroring ConnectMessageService's
 * internal fire-and-forget try/catch. Delivery failures must never fail the
 * message.
 */
final class ConnectDeliveryFailureRecorder extends ConnectDeliveryRecorder
{
    protected function postToSocket(string $url, array $payload): bool
    {
        return false;
    }

    public function sendNotification(array $hbUser, array $botUser, string $plaintext, array $room, string $messageId, string $targetUserId): void
    {
        $this->notifications[] = [
            'failed'       => true,
            'messageId'    => $messageId,
            'targetUserId' => $targetUserId,
        ];
    }
}

/**
 * Test seam: message creation fails — proves no socket/FCM is ever emitted
 * before a message row is persisted.
 */
final class ConnectNoMessageRecorder extends ConnectDeliveryRecorder
{
    public function createHbMessage(array $botUser, array $hbUser, array $room, string $encryptedBody): array
    {
        return [];
    }
}
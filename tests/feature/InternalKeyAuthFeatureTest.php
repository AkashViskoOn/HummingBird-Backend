<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;

/**
 * The internal-key gate: the two cron endpoints accept the backend↔socket
 * `X-Internal-Key` (and the status one also a JWT). Documented failure modes
 * are load-bearing — if `INTERNAL_CRON_KEY` is unset the scheduled-message
 * path silently never sends — so the lockout behaviour is pinned here exactly
 * as it is for the consumer JWT surface.
 *
 * @internal
 */
final class InternalKeyAuthFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FeatureTestTrait;

    private const KEY = 'unit-test-internal-cron-key';

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private array $touched = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The direct run() harness does not preload Config\Autoload helpers
        // and StatusController relies on the autoloaded token_helper (it does
        // not call helper('token') like ScheduleController does) — load the
        // global functions explicitly so check_jwt_authentication() resolves.
        helper('token_helper');
        $this->setEnv('JWT_SECRET', 'unit-test-jwt-secret');
        $this->setEnv('INTERNAL_CRON_KEY', self::KEY);
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $name => $original) {
            if ($original === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name . '=' . $original);
            }
        }
        $this->touched = [];

        parent::tearDown();
    }

    private function setEnv(string $name, string|false $value): void
    {
        if (! array_key_exists($name, $this->touched)) {
            $current            = getenv($name);
            $this->touched[$name] = $current === false ? false : (string) $current;
        }

        if ($value === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name . '=' . $value);
        }
    }

    private function call(string $uri, string $method, array $headers = []): \CodeIgniter\Test\TestResponse
    {
        Services::reset();

        $this->request = $this->setupRequest($method, $uri);
        $this->request->setMethod($method);
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

        Services::reset();

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
     * @return array<string, array{0:string,1:string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'scheduled message dispatch' => ['message/send-scheduled-messages', 'GET'],
            'status cleanup'             => ['status/cleanup-expired', 'POST'],
        ];
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testDisableWhenKeyEnvMissing(string $uri, string $method): void
    {
        $this->setEnv('INTERNAL_CRON_KEY', false);

        $result = $this->call($uri, $method);

        $result->assertStatus(503);

        $json = $this->json($result);
        $this->assertFalse($json->status);
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testRejectWhenKeyHeaderMissing(string $uri, string $method): void
    {
        $result = $this->call($uri, $method);

        $result->assertStatus(401);

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Authorization Failed', $json->message);
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testRejectWhenKeyWrong(string $uri, string $method): void
    {
        $result = $this->call($uri, $method, ['X-Internal-Key' => 'wrong-cron-key']);

        $result->assertStatus(401);

        $json = $this->json($result);
        $this->assertFalse($json->status);
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testAcceptWhenKeyCorrect(string $uri, string $method): void
    {
        $result = $this->call($uri, $method, ['X-Internal-Key' => self::KEY]);

        // The key opens the door; the endpoint then touches the database (a
        // real run on RDS will actually process rows). The assertion that
        // matters for the auth gate is: never 401/503, never "Authorization
        // Failed". body status false is fine — it reflects the empty SQLite
        // test DB, not an auth failure.
        $this->assertNotSame('Authorization Failed', $this->json($result)->message);
    }

    public function testMessageEndpointDoesNotAcceptJwtInsteadOfKey(): void
    {
        $now   = time();
        $token = JWT::encode(
            ['sub' => 'u-test-1', 'user_id' => 'u-test-1', 'iat' => $now, 'exp' => $now + 3600],
            'unit-test-jwt-secret',
            'HS256',
        );

        // The message endpoint gate is key-only (no check_jwt_authentication);
        // a JWT must NOT undo the internal-key requirement. (The status
        // endpoint intentionally ALSO accepts a JWT, so it is excluded here.)
        $result = $this->call(
            'message/send-scheduled-messages',
            'GET',
            ['Authorization' => 'Bearer ' . $token],
        );

        $json = $this->json($result);
        $this->assertSame('Authorization Failed', $json->message);
    }

    public function testScheduledEndpointReachesProcessingBranchWhenAuthorised(): void
    {
        // Regression guard for the "no m_id → dispatch all due" branch: with a
        // valid key and an empty body the endpoint must not 401 — it should
        // reach the processing branch and answer a status envelope (the empty
        // SQLite test DB makes it a count-of-zero / no-rows answer, not an
        // auth rejection).
        $result = $this->call(
            'message/send-scheduled-messages',
            'GET',
            ['X-Internal-Key' => self::KEY],
        );

        $result->assertStatus(200);

        $this->assertNotSame(
            'Authorization Failed',
            $this->json($result)->message,
            'a valid internal key must pass the gate',
        );
    }
}
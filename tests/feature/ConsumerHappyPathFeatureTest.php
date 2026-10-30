<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * End-to-end happy path for one consumer endpoint: prove that a real signed
 * JWT passes `check_jwt_authentication()` and reaches a successful envelope,
 * not merely that unauthenticated requests are rejected.
 *
 * `schedule/categories` is deliberately the probe: it returns a static list
 * and touches no database, so the only thing between a request and `status:
 * true` is the auth gate. If the helper, the env secret wiring, or the header
 * parsing ever regress, this test catches it in seconds instead of on-device.
 *
 * @internal
 */
final class ConsumerHappyPathFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SECRET = 'unit-test-consumer-happy-path-secret';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('JWT_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        parent::tearDown();
    }

    /**
     * Same direct `run()` approach as the Health and Connect suites: the
     * repo's CI4 bundle re-renders JSON through HTML/TestResponse when driven
     * from FeatureTestTrait::call(), so we drive CodeIgniter::run() directly.
     */
    private function call(string $uri, ?string $token = null): \CodeIgniter\Test\TestResponse
    {
        Services::reset();

        $this->request = $this->setupRequest('GET', $uri);
        $this->request->setMethod('GET');
        if ($token !== null) {
            $this->request->setHeader('Authorization', 'Bearer ' . $token);
        }

        Services::injectMock('request', $this->request);

        $routes = Services::routes()->loadRoutes();
        $routes->setHTTPVerb('GET');

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

    private function makeToken(array $claims = []): string
    {
        $now  = time();
        $base = array_merge([
            'sub' => 'u-test-1',
            'user_id' => 'u-test-1',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $claims);

        return JWT::encode($base, self::SECRET, 'HS256');
    }

    public function testValidJwtReachesSuccessEnvelope(): void
    {
        $result = $this->call('schedule/categories', $this->makeToken());

        $result->assertOK();

        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertSame('Categories found', $json->message);
        $this->assertSame(
            ['birthday', 'anniversary', 'reminder', 'promotional', 'meeting', 'follow-up', 'greeting', 'emergency', 'custom'],
            $json->data,
        );
    }

    public function testNoTokenIsRejected(): void
    {
        $result = $this->call('schedule/categories');

        $result->assertStatus(200);

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Authorization Failed', $json->message);
    }

    public function testTamperedTokenIsRejected(): void
    {
        // Flip one char in the signature: must fail signature check, not 500.
        $good = $this->makeToken();
        $bad  = substr($good, 0, -1) . ($good[-1] === 'a' ? 'b' : 'a');

        $result = $this->call('schedule/categories', $bad);

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Authorization Failed', $json->message);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $result = $this->call(
            'schedule/categories',
            $this->makeToken(['exp' => time() - 60, 'iat' => time() - 3600]),
        );

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Authorization Failed', $json->message);
    }

    public function testTokenSignedWithWrongSecretIsRejected(): void
    {
        $result = $this->call(
            'schedule/categories',
            JWT::encode(['sub' => 'u-test-1', 'exp' => time() + 3600], 'wrong-secret', 'HS256'),
        );

        $json = $this->json($result);
        $this->assertFalse($json->status);
        $this->assertSame('Authorization Failed', $json->message);
    }

    public function testWrongAudienceClaimStillPasses(): void
    {
        // The consumer JWT helper only verifies signature + exp, so a token
        // carrying extra claims but a valid signature must be accepted. This
        // locks in the CURRENT contract so tightening the helper becomes a
        // deliberate, tested change rather than a silent one.
        $result = $this->call('schedule/categories', $this->makeToken(['aud' => 'phone-app']));

        $result->assertOK();

        $json = $this->json($result);
        $this->assertTrue($json->status);
    }

    public function testConsumerSessionTtlOutlivesNormalAppUsage(): void
    {
        // The app persists `isLoggedIn` across launches and has no refresh
        // endpoint, so phone sessions must not silently die after 24h.
        $token = encode_jwt(['user_id' => 'u-test-1'], CONSUMER_SESSION_TTL);
        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        $this->assertGreaterThan(time() + (29 * 86400), $claims->exp);
    }
}
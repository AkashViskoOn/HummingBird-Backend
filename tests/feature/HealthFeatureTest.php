<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Liveness and readiness probes.
 *
 * The probes are the only unauthenticated surface on the consumer API, so the
 * contract that matters most is that they stay useful without leaking: a
 * missing secret has to be reported *by name* and a secret *value* must never
 * appear in the payload.
 *
 * @internal
 */
final class HealthFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * Env keys mutated by individual tests, restored afterwards so ordering
     * cannot leak between them.
     *
     * @var array<string, string|false>
     */
    private array $touched = [];

    protected function setUp(): void
    {
        parent::setUp();
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
            $current         = getenv($name);
            $this->touched[$name] = $current === false ? false : (string) $current;
        }

        if ($value === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name . '=' . $value);
        }
    }

    /**
     * Drives CodeIgniter::run() against the routes registered in
     * app/Config/Routes.php.
     *
     * FeatureTestTrait::call() is deliberately not used: this repo's CI4
     * bundle re-renders JSON bodies through its HTML/TestResponse pipeline
     * (correct status, HTML-wrapped body) and exhausts memory on a plain GET.
     * The Connect suites use the same direct approach.
     */
    private function call(string $uri): \CodeIgniter\Test\TestResponse
    {
        Services::reset();

        $this->request = $this->setupRequest('GET', $uri);
        $this->request->setMethod('GET');

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

    /**
     * Decodes the envelope. The health payload is intentionally plain, so a
     * body that is not JSON means something replaced the response (an error
     * page, or a route that no longer resolves) and the test should say so
     * rather than fail on a null property.
     */
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

    public function testLivenessAlwaysAnswersOk(): void
    {
        $result = $this->call('health');

        $result->assertOK();

        $json = $this->json($result);
        $this->assertTrue($json->status);
        $this->assertSame('ok', $json->data->status);
    }

    public function testLivenessDoesNotRequireDatabase(): void
    {
        // The whole point of a liveness probe: it stays green while the
        // database is down, so an orchestrator does not kill healthy workers
        // during an outage.
        $this->setEnv('DB_HOSTNAME', '127.0.0.1');
        $this->setEnv('DB_PORT', '1');
        $this->setEnv('DB_DATABASE', 'definitely_not_a_database');
        $this->setEnv('DB_USERNAME', 'nobody');

        $result = $this->call('health');

        $result->assertOK();
    }

    public function testReadinessReportsReadyWhenDependenciesAreUsable(): void
    {
        $this->setEnv('JWT_SECRET', 'unit-test-jwt-secret-value');
        $this->setEnv('INTERNAL_CRON_KEY', 'unit-test-internal-cron-key');

        $result = $this->call('health/ready');

        $result->assertOK();

        $json = $this->json($result);
        $this->assertSame('ok', $json->data->status);
        $this->assertTrue($json->data->checks->database->ok);
        $this->assertTrue($json->data->checks->secrets->ok);
    }

    public function testReadinessFailsAndNamesMissingRequiredSecrets(): void
    {
        $this->setEnv('JWT_SECRET', false);
        $this->setEnv('INTERNAL_CRON_KEY', false);

        $result = $this->call('health/ready');

        $result->assertStatus(503);

        $json = $this->json($result);
        $this->assertSame('degraded', $json->data->status);
        $this->assertFalse($json->data->checks->secrets->ok);

        // Named so an operator knows what to set...
        $this->assertContains('JWT_SECRET', $json->data->checks->secrets->missing);
        $this->assertContains('INTERNAL_CRON_KEY', $json->data->checks->secrets->missing);
    }

    public function testAnEmptySecretCountsAsMissing(): void
    {
        // `JWT_SECRET=` in .env is the realistic mistake: present, but useless.
        $this->setEnv('JWT_SECRET', '   ');

        $result = $this->call('health/ready');

        $result->assertStatus(503);

        $json = $this->json($result);
        $this->assertContains('JWT_SECRET', $json->data->checks->secrets->missing);
    }

    public function testProbesNeverLeakSecretValues(): void
    {
        $this->setEnv('JWT_SECRET', 'super-secret-jwt-value-do-not-leak');
        $this->setEnv('INTERNAL_CRON_KEY', 'super-secret-cron-value-do-not-leak');
        $this->setEnv('GEMINI_API_KEY', 'sk-do-not-leak-me');

        foreach (['health', 'health/ready'] as $uri) {
            $body = (string) $this->call($uri)->response()->getBody();

            $this->assertStringNotContainsString('super-secret-jwt-value-do-not-leak', $body, $uri);
            $this->assertStringNotContainsString('super-secret-cron-value-do-not-leak', $body, $uri);
            $this->assertStringNotContainsString('sk-do-not-leak-me', $body, $uri);
        }
    }

    public function testProbesNeverLeakDatabaseCredentials(): void
    {
        // The dev group is SQLite, so assert on the shape of the payload
        // rather than on a specific driver: no DSN, no user, no password.
        foreach (['health', 'health/ready'] as $uri) {
            $body = (string) $this->call($uri)->response()->getBody();

            $this->assertStringNotContainsString('password', strtolower($body), $uri);
            $this->assertStringNotContainsString('db_password', strtolower($body), $uri);
        }
    }

    public function testOptionalSecretsAreReportedSeparatelyFromRequiredOnes(): void
    {
        $this->setEnv('JWT_SECRET', 'unit-test-jwt-secret-value');
        $this->setEnv('INTERNAL_CRON_KEY', 'unit-test-internal-cron-key');
        $this->setEnv('GEMINI_API_KEY', false);

        $result = $this->call('health/ready');

        $result->assertOK();

        $json = $this->json($result);
        $secrets = $json->data->checks->secrets;

        // A missing optional secret must not make the app "unready".
        $this->assertTrue($secrets->ok);
        $this->assertSame([], $secrets->missing);
        $this->assertContains('GEMINI_API_KEY', $secrets->optional_missing);
    }
}

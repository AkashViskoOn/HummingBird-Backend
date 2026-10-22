<?php

namespace Tests;

use Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /metrics — the unauth Prometheus endpoint.
 *
 * These tests pin the contract that makes the row scoreable: always 200, plain
 * text, DB-free (it must survive a database outage — that is the health-probe
 * rule applied to metrics), secret-free, and counter-bearing. All assertions
 * are relative: the counters are process-local and shared with every other
 * test in the run, so we compare before/after values, never absolutes.
 *
 * @internal
 */
final class MetricsFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * @var array<string, string|false>
     */
    private array $touched = [];

    protected function setUp(): void
    {
        parent::setUp();
        helper('token_helper');
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

    private function call(string $uri, string $method): \CodeIgniter\Test\TestResponse
    {
        Services::reset();

        $this->request = $this->setupRequest($method, $uri);
        $this->request->setMethod($method);

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

    private function metricValue(string $body, string $metric): int
    {
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, $metric . ' ')) {
                $parts = explode(' ', $line);

                return (int) $parts[1];
            }
        }

        $this->fail('metric not found in body: ' . $metric);

        return -1;
    }

    public function testMetricsAnswers200WithoutAnyAuth(): void
    {
        $result = $this->call('metrics', 'GET');

        $result->assertStatus(200);

        $this->assertStringStartsWith(
            'text/plain',
            $result->response()->getHeaderLine('Content-Type'),
        );
    }

    public function testMetricsIsPrometheusTextFormat(): void
    {
        $result = $this->call('metrics', 'GET');
        $body   = (string) $result->response()->getBody();

        $this->assertStringContainsString('# HELP hummingbird_http_requests_total', $body);
        $this->assertStringContainsString('# TYPE hummingbird_http_requests_total counter', $body);
    }

    public function testRequestCounterIncrementsAcrossRequests(): void
    {
        $before = $this->metricValue((string) $this->call('metrics', 'GET')->response()->getBody(), 'hummingbird_http_requests_total');

        $this->call('health', 'GET');

        $after = $this->metricValue((string) $this->call('metrics', 'GET')->response()->getBody(), 'hummingbird_http_requests_total');

        $this->assertGreaterThan($before, $after, 'a real request must bump the total counter');
    }

    public function testStatusCodeCounterTracksResponses(): void
    {
        $this->call('health', 'GET');

        $body = (string) $this->call('metrics', 'GET')->response()->getBody();

        $this->assertMatchesRegularExpression('/hummingbird_http_response_codes_total\{code="200"\} \d+/', $body);
    }

    public function testLatencyHistogramIsPresent(): void
    {
        $body = (string) $this->call('metrics', 'GET')->response()->getBody();

        $this->assertMatchesRegularExpression('/hummingbird_http_request_duration_seconds_bucket\{le="\+Inf"\} \d+/', $body);
        $this->assertMatchesRegularExpression('/hummingbird_http_request_duration_seconds_count \d+/', $body);
        $this->assertMatchesRegularExpression('/hummingbird_http_request_duration_seconds_sum [0-9.]+/', $body);
    }

    public function testUptimeAndMemoryGaugesArePresent(): void
    {
        $body = (string) $this->call('metrics', 'GET')->response()->getBody();

        $this->assertMatchesRegularExpression('/hummingbird_process_uptime_seconds \d+/', $body);
        $this->assertMatchesRegularExpression('/hummingbird_process_memory_bytes \d+/', $body);
    }

    public function testMetricsNeverLeaksSecretsOrDsns(): void
    {
        $this->setEnv('JWT_SECRET', 'bogus-secret-that-must-not-appear');
        $this->setEnv('DB_HOSTNAME', 'db-internal.example.internal');
        $this->setEnv('DB_PASSWORD', 'hunter2');

        $body = (string) $this->call('metrics', 'GET')->response()->getBody();

        $this->assertStringNotContainsString('bogus-secret-that-must-not-appear', $body);
        $this->assertStringNotContainsString('db-internal.example.internal', $body);
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('JWT_SECRET', $body);
        $this->assertStringNotContainsString('DB_HOSTNAME', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
    }

    public function testMetricsIsUsableWhileDatabaseIsUnreachable(): void
    {
        // A metrics endpoint that 500s when the DB is down is worthless: the
        // DB being down is precisely when you need it. Point the DB group at a
        // guaranteed-dead socket and prove /metrics still answers.
        $this->setEnv('DB_HOSTNAME', '203.0.113.254');
        $this->setEnv('DB_USERNAME', 'metrics-probe');
        $this->setEnv('DB_PASSWORD', 'down');
        $this->setEnv('DB_DATABASE', 'metrics-probe');
        $this->setEnv('DB_PORT', '3310');

        $result = $this->call('metrics', 'GET');

        $result->assertStatus(200);
        $this->assertStringStartsWith(
            'text/plain',
            $result->response()->getHeaderLine('Content-Type'),
        );
    }

    public function testRequestLogAppendsPerRequestToOverridePath(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'hb_metrics_');
        $this->setEnv('METRICS_LOG_PATH', $logPath);

        $this->call('health', 'GET');

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines, 'a completed request must append a log line');

        $last = json_decode((string) end($lines));
        $this->assertInstanceOf(\stdClass::class, $last, 'log lines must be valid JSON');
        $this->assertSame('GET', $last->method);
        $this->assertSame('/health', $last->path);
        $this->assertSame(200, $last->status);
        $this->assertArrayHasKey('duration_ms', (array) $last);
        $this->assertArrayHasKey('memory_bytes', (array) $last);

        @unlink($logPath);
    }

    public function testRequestLogNeverContainsSecrets(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'hb_metrics_');
        $this->setEnv('METRICS_LOG_PATH', $logPath);
        $this->setEnv('JWT_SECRET', 'topsecret-value-must-not-leak');
        $this->setEnv('DB_PASSWORD', 'dbpass-must-not-leak');

        $this->call('metrics', 'GET');

        $raw = (string) file_get_contents($logPath);

        $this->assertStringNotContainsString('topsecret-value-must-not-leak', $raw);
        $this->assertStringNotContainsString('dbpass-must-not-leak', $raw);

        @unlink($logPath);
    }
}
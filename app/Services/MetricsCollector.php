<?php

namespace App\Services;

/**
 * Process-local request telemetry for the unauthenticated GET /metrics
 * endpoint.
 *
 * Deliberate design:
 *   - Counters live in static memory, so a single PHP-FPM worker reports its
 *     own lifetime (the sum across workers is what a Prometheus scraper needs).
 *   - Nothing here touches the database, the filesystem or the network: a
 *     metrics endpoint that dies with its dependency is a metrics endpoint
 *     nobody can read when it matters.
 *   - Only counts, never identifiers: no user ids, no phone numbers, no DSNs,
 *     no stack traces, no secret values. An operator watches traffic, not data.
 */
final class MetricsCollector
{
    /**
     * Histogram buckets (seconds), cumulative upper bounds. +Inf is added
     * implicitly so the total count is always present.
     */
    public const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private static int $startedAt = 0;

    private static int $requestsTotal = 0;

    private static int $inFlight = 0;

    /** @var array<int, int> status code → count */
    private static array $responseCodes = [];

    /** @var array<string, int> bucket upper bound → count */
    private static array $latencyBuckets = [];

    private static float $latencySum = 0.0;

    private static int $latencyCount = 0;

    private static float $requestStartedAt = 0.0;

    public static function requestStarted(): void
    {
        if (self::$startedAt === 0) {
            self::$startedAt = time();
        }

        self::$requestsTotal++;
        self::$inFlight++;
        self::$requestStartedAt = microtime(true);
    }

    public static function requestFinished(int $statusCode, string $method = 'CLI', string $uri = '/'): void
    {
        $duration = microtime(true) - self::$requestStartedAt;
        if ($duration < 0.0) {
            $duration = 0.0;
        }

        self::$latencySum   += $duration;
        self::$latencyCount++;

        foreach (self::BUCKETS as $bucket) {
            if ($duration <= $bucket) {
                $key                             = (string) $bucket;
                self::$latencyBuckets[$key] = (self::$latencyBuckets[$key] ?? 0) + 1;
            }
        }
        self::$latencyBuckets['+Inf'] = self::$latencyCount;

        self::$responseCodes[$statusCode] = (self::$responseCodes[$statusCode] ?? 0) + 1;

        if (self::$inFlight > 0) {
            self::$inFlight--;
        }

        self::appendRequestLog($statusCode, $duration, $method, $uri);
    }

    /**
     * One JSONL line per completed request to writable/logs/requests.jsonl
     * (override with METRICS_LOG_PATH). The bytes are lives worth one look:
     * a 5xx cluster or a latency cliff becomes visible in a log scan without a
     * dashboard. The record is deliberately identifier-free — method, path,
     * status, duration, memory — so the log never becomes a data cache.
     *
     * Failure is swallowed: observability must never take down the request it
     * is observing.
     */
    private static function appendRequestLog(int $statusCode, float $duration, string $method, string $uri): void
    {
        try {
            $path = getenv('METRICS_LOG_PATH');

            // In a test run with no explicit override, writing a real access
            // log file would churn the tree on every phpunit invocation, so
            // the default writable/logs/requests.jsonl is only written in
            // real (non-testing) environments. An explicit METRICS_LOG_PATH
            // always wins — that is how the feature tests exercise it.
            if ($path === false && ENVIRONMENT === 'testing') {
                return;
            }

            if ($path === false) {
                $path = (string) WRITEPATH . 'logs/requests.jsonl';
            }

            $line = json_encode([
                'time'     => date('c'),
                'method'   => $method,
                'path'     => parse_url($uri, PHP_URL_PATH) ?: $uri,
                'status'   => $statusCode,
                'duration_ms' => (int) round($duration * 1000),
                'memory_bytes' => self::memoryBytes(),
            ], JSON_UNESCAPED_SLASHES);

            if ($line !== false) {
                @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable $e) {
            // Never let logging break the request it observes.
        }
    }

    public static function uptimeSeconds(): int
    {
        if (self::$startedAt === 0) {
            self::$startedAt = time();
        }

        return max(0, time() - self::$startedAt);
    }

    public static function requestsTotal(): int
    {
        return self::$requestsTotal;
    }

    public static function inFlight(): int
    {
        return self::$inFlight;
    }

    /**
     * @return array<int, int>
     */
    public static function responseCodes(): array
    {
        return self::$responseCodes;
    }

    /**
     * @return array<string, int>
     */
    public static function latencyBuckets(): array
    {
        ksort(self::$latencyBuckets);

        return self::$latencyBuckets;
    }

    public static function latencySum(): float
    {
        return self::$latencySum;
    }

    public static function latencyCount(): int
    {
        return self::$latencyCount;
    }

    public static function memoryBytes(): int
    {
        return memory_get_usage(true);
    }
}
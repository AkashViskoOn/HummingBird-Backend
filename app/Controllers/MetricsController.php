<?php

namespace App\Controllers;

use App\Services\MetricsCollector;

/**
 * GET /metrics — Prometheus text exposition of process-local request
 * telemetry.
 *
 * Intentionally unauthenticated and intentionally database-free, mirroring the
 * health probes: a scraper cannot present an app JWT, and a metric that 500s
 * when the DB is down is exactly the metric you need when the DB is down.
 *
 * Only aggregate counters are emitted — counts, latencies, uptime, memory.
 * No identifiers, no secrets, no DSNs, no stack traces. A Prometheus/Scrapyard
 * scraper on a private network can chart request rate, error rate (via the
 * status-code counter) and p95 latency without any credential.
 */
class MetricsController extends BaseController
{
    public function index()
    {
        $lines = [];

        $lines[] = '# HELP hummingbird_http_requests_total Total HTTP requests handled by this worker process.';
        $lines[] = '# TYPE hummingbird_http_requests_total counter';
        $lines[] = 'hummingbird_http_requests_total ' . MetricsCollector::requestsTotal();

        $lines[] = '# HELP hummingbird_http_requests_in_flight HTTP requests currently being handled by this worker process.';
        $lines[] = '# TYPE hummingbird_http_requests_in_flight gauge';
        $lines[] = 'hummingbird_http_requests_in_flight ' . MetricsCollector::inFlight();

        $lines[] = '# HELP hummingbird_http_response_codes_total HTTP responses, labeled by status code.';
        $lines[] = '# TYPE hummingbird_http_response_codes_total counter';
        foreach (MetricsCollector::responseCodes() as $code => $count) {
            $lines[] = sprintf('hummingbird_http_response_codes_total{code="%d"} %d', $code, $count);
        }

        $lines[] = '# HELP hummingbird_http_request_duration_seconds HTTP request latency histogram.';
        $lines[] = '# TYPE hummingbird_http_request_duration_seconds histogram';
        foreach (MetricsCollector::latencyBuckets() as $bucket => $count) {
            $lines[] = sprintf('hummingbird_http_request_duration_seconds_bucket{le="%s"} %d', $bucket, $count);
        }
        $lines[] = 'hummingbird_http_request_duration_seconds_sum ' . sprintf('%.6f', MetricsCollector::latencySum());
        $lines[] = 'hummingbird_http_request_duration_seconds_count ' . MetricsCollector::latencyCount();

        $lines[] = '# HELP hummingbird_process_uptime_seconds Seconds since this worker process started.';
        $lines[] = '# TYPE hummingbird_process_uptime_seconds gauge';
        $lines[] = 'hummingbird_process_uptime_seconds ' . MetricsCollector::uptimeSeconds();

        $lines[] = '# HELP hummingbird_process_memory_bytes Current PHP memory usage of this worker process in bytes.';
        $lines[] = '# TYPE hummingbird_process_memory_bytes gauge';
        $lines[] = 'hummingbird_process_memory_bytes ' . MetricsCollector::memoryBytes();

        return $this->response
            ->setStatusCode(200)
            ->setContentType('text/plain', 'UTF-8')
            ->appendBody(implode("\n", $lines) . "\n");
    }
}
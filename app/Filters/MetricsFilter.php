<?php

namespace App\Filters;

use App\Services\MetricsCollector;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Global before/after hook that feeds the process-local request telemetry
 * served by GET /metrics.
 *
 * Pure bookkeeping: increments a counter before, a status-code + latency
 * bucket after. It never blocks, never throws and never reads a secret, so it
 * is safe on every route including the health probes.
 */
class MetricsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        MetricsCollector::requestStarted();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $method = strtoupper((string) $request->getMethod());
        $uri    = (string) $request->getUri();

        MetricsCollector::requestFinished((int) $response->getStatusCode(), $method, $uri);

        return null;
    }
}
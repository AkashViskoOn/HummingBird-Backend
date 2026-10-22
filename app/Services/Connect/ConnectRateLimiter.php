<?php

namespace App\Services\Connect;

use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Connect as ConnectConfig;

/**
 * Humming Bird Connect — rate-limit enforcement (Connect APIs only).
 *
 * The ConnectApiAuthFilter runs this after a successful authentication, so
 * every authenticated Connect request (messages, webhooks, users, status)
 * passes through here. Unauthenticated requests never consume a unit.
 *
 * Phase 8 extends the Phase 2 foundation:
 *   - fixed windows: per-second / per-minute / per-hour (general, every
 *     category) +
 *   - per-category per-minute caps (e.g. a tighter budget for messages.send) +
 *   - atomic enforcement via ConnectRateLimitStoreInterface::reserve() —
 *     a compare-and-set claim, so simultaneous requests can never overshoot
 *     the window (the old audit-row count lagged by one request).
 *   - a ConnectRateLimitDecision result that feeds RateLimit-* / Retry-After
 *     response headers.
 *
 * Scope is (tenant, environment, credential, category, window). All windows
 * are fixed — the reset instant is the bucket end. When the atomic store is
 * unavailable (schema not migrated yet) the store returns a legacy
 * audit-row count and the limiter degrades gracefully.
 */
class ConnectRateLimiter
{
    private ConnectRateLimitStoreInterface $store;
    private ConnectConfig $config;

    public function __construct(?ConnectRateLimitStoreInterface $store = null, ?ConnectConfig $config = null)
    {
        $this->store  = $store ?? new DatabaseRateLimitStore();
        $this->config = $config ?? config(ConnectConfig::class);
    }

    public function setStore(ConnectRateLimitStoreInterface $store): void
    {
        $this->store = $store;
    }

    /**
     * Enforce every configured window for the authenticated context/category.
     *
     * @param string $category endpoint category ('request' when unknown)
     * @param int    $now      injectable clock for deterministic tests
     */
    public function check(ConnectTenantContext $context, string $category = 'request', ?int $now = null): ConnectRateLimitDecision
    {
        if (! $this->config->rateLimitEnabled) {
            return new ConnectRateLimitDecision(true, null, null, 0, 0, null);
        }

        $now = $now ?? time();

        $windows = [];

        if ($this->config->rateLimitPerSecond !== null && $this->config->rateLimitPerSecond > 0) {
            $windows[] = [
                'scope'  => 'all.' . $this->config->rateLimitPerSecond . '.s',
                'window' => 1,
                'limit'  => $this->config->rateLimitPerSecond,
            ];
        }

        if ($this->config->rateLimitPerMinute > 0) {
            $windows[] = [
                'scope'  => 'all.60.m',
                'window' => 60,
                'limit'  => $this->config->rateLimitPerMinute,
            ];
        }

        if ($this->config->rateLimitPerHour > 0) {
            $windows[] = [
                'scope'  => 'all.3600.h',
                'window' => 3600,
                'limit'  => $this->config->rateLimitPerHour,
            ];
        }

        $categoryMinute = (int) ($this->config->rateLimitCategories[$category] ?? 0);
        if ($categoryMinute > 0) {
            $windows[] = [
                'scope'  => 'cat.' . $category . '.60',
                'window' => 60,
                'limit'  => $categoryMinute,
            ];
        }

        if (count($windows) === 0) {
            return new ConnectRateLimitDecision(true, null, null, 0, 0, null);
        }

        $best = null; // most constrained window for header reporting

        foreach ($windows as $window) {
            $bucketEpoch = (int) floor($now / $window['window']) * $window['window'];
            $resetAt     = $bucketEpoch + $window['window'];
            $bucketStart = date('Y-m-d H:i:s', $bucketEpoch);

            try {
                // Peek first: a window that is already at capacity is denied
                // WITHOUT claiming. reserve() never returns more than $limit (the
                // CAS clamps at capacity), so a post-claim count alone cannot tell
                // "this request filled the last slot" (allowed) from "already
                // full" (denied) — the peek disambiguates the two.
                $peek = $this->store->peek(
                    $context->tenantId(),
                    $context->credentialDbId(),
                    $context->environment(),
                    'rate',
                    $window['scope'],
                    $window['window'],
                    $bucketStart,
                );

                if ($peek >= $window['limit']) {
                    return new ConnectRateLimitDecision(
                        false,
                        ConnectConfig::ERROR_RATE_LIMIT_EXCEEDED,
                        $window['limit'],
                        0,
                        $resetAt,
                        max(1, $resetAt - $now),
                    );
                }

                // Capacity existed at peek time → this request may claim a unit.
                $count = $this->store->reserve(
                    $context->tenantId(),
                    $context->credentialDbId(),
                    $context->environment(),
                    'rate',
                    $window['scope'],
                    $window['window'],
                    $bucketStart,
                    $window['limit'],
                );

                // In a tight race a concurrent claim can fill the window between
                // peek and reserve; the request is still admitted (it arrived
                // while capacity existed) but the reported remaining reflects the
                // actual post-claim count.
                $remaining = max(0, $window['limit'] - $count);
            } catch (DatabaseException $e) {
                // Counter store exploded despite a positive availability probe
                // (e.g. an externally dropped table and a stale CI4 table cache).
                // Fail open when configured — never take the API down on a
                // rate-limit store failure.
                if (! $this->config->rateLimitFailOpen) {
                    throw $e;
                }

                log_message('error', 'Connect rate-limit store failed (fail-open): ' . $e->getMessage());

                return new ConnectRateLimitDecision(true, null, null, 0, 0, null);
            }

            if ($best === null || $remaining < $best['remaining']) {
                $best = [
                    'limit'     => $window['limit'],
                    'remaining' => $remaining,
                    'resetAt'   => $resetAt,
                ];
            }
        }

        return new ConnectRateLimitDecision(
            true,
            null,
            $best['limit'],
            $best['remaining'],
            $best['resetAt'],
            null,
        );
    }
}
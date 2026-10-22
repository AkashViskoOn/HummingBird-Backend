<?php

namespace App\Services\Connect;

/**
 * Humming Bird Connect — rate-limit / quota counter store abstraction.
 *
 * Phase 2 shipped a read-only fixed-window counter over `connect_api_audit_logs`
 * (countRecent) that lagged by one request and was not concurrency-safe.
 * Phase 8 replaces it with an atomic compare-and-set bucket store over
 * `connect_rate_limit_counters` (see AddConnectPhase8RateLimitsAndQuotas):
 *
 *   reserve()  — atomically claim ONE unit inside a bucket unless the bucket
 *                is already at capacity. Returns the post-claim usage count.
 *                Callers treat `count <= limit` as allowed. This is the same
 *                CAS discipline as the Phase 5 outbox (UPDATE guarded by
 *                affectedRows) and works identically on MySQL (row lock) and
 *                SQLite (serialized writer).
 *
 *   peek()     — read the current usage without claiming a unit (used for
 *                quota pre-checks so rejected/idempotent requests never
 *                consume budget, and for header stamping).
 *
 *   available()— whether the atomic bucket table exists yet. The default
 *                store deliberately falls back to the legacy audit-row count
 *                for rate windows when it does not, so a rollout that outruns
 *                its migration degrades gracefully instead of failing closed.
 */
interface ConnectRateLimitStoreInterface
{
    /**
     * Whether the atomic counter table is present for this connection.
     * False ⇒ callers should use the legacy/non-blocking path (fail open).
     */
    public function available(): bool;

    /**
     * Read the current usage count for a bucket without claiming a unit.
     *
     * @param string $bucketStart window/period start in 'Y-m-d H:i:s'
     */
    public function peek(
        int $tenantId,
        int $credentialId,
        string $environment,
        string $kind,
        string $scopeCategory,
        int $windowSeconds,
        string $bucketStart,
    ): int;

    /**
     * Atomically claim one unit inside a bucket unless it is already at
     * capacity. Returns the post-claim usage count; never exceeds $limit
     * (the caller interprets `count <= limit` as allowed).
     *
     * @param string $bucketStart window/period start in 'Y-m-d H:i:s'
     */
    public function reserve(
        int $tenantId,
        int $credentialId,
        string $environment,
        string $kind,
        string $scopeCategory,
        int $windowSeconds,
        string $bucketStart,
        int $limit,
    ): int;
}
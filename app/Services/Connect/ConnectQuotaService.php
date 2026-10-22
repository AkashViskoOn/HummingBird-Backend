<?php

namespace App\Services\Connect;

use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — usage quotas for accepted Connect messages.
 *
 * Phase 8 adds a durable meter for the billing-relevant operation: ONE unit
 * per ACCEPTED Connect message send (an operation that reached the queue
 * transaction). Accounting rules:
 *
 *   - Idempotent retries (same external_message_id) NEVER consume a unit —
 *     they return before the quota path runs.
 *   - Validation / mapping / identity rejections NEVER consume a unit — the
 *     quota peek runs AFTER those checks, and only the in-transaction consume
 *     reserves budget.
 *   - Delivery fan-out (Socket.IO per device, FCM per token, webhook retries)
 *     is free — one accepted message = one unit.
 *
 * Periods are calendar bounds in server-local time (UTC for the straightforward
 * date functions the app already uses): month = YYYY-MM-01, day = YYYY-MM-DD.
 * The unit key is (tenant, environment, credential, period); by default the
 * credential part is 0 so every API key of a tenant shares ONE quota — exactly
 * like the tenant's shared service identity (the message flow always selects
 * the tenant's first active identity, never a per-credential one). Set
 * $quotaCredentialScoped to isolate per credential.
 *
 * Enforcement is atomic and rollback-safe: ConnectMessageService::queue() calls
 * consumeMessage() INSIDE its message transaction, so if the tracking/outbox
 * insert fails and the transaction rolls back, the quota unit rolls back too
 * (the counter UPDATE joins the same DB transaction).
 *
 * Failure behaviour is configurable: $quotaFailOpen=true (default) logs and
 * allows the message when the counter store errors so billing infrastructure
 * can never take messaging down; =false rethrows so the send fails (caught by
 * the queue transaction as a 500). When the counter table is missing entirely,
 * quota is simply not enforced (fail-open) and headers are not emitted.
 */
class ConnectQuotaService
{
    private const KIND = 'quota';

    private ConnectRateLimitStoreInterface $store;
    private ConnectConfig $config;

    public function __construct(?ConnectRateLimitStoreInterface $store = null, ?ConnectConfig $config = null)
    {
        $this->store  = $store ?? new DatabaseRateLimitStore();
        $this->config = $config ?? config(ConnectConfig::class);
    }

    public function store(): ConnectRateLimitStoreInterface
    {
        return $this->store;
    }

    /**
     * Read-only peek: true when ANY enabled window is already exhausted.
     * Never consumes — call before expensive message processing and before
     * the consume, so rejected/idempotent requests use no budget.
     */
    public function isOverQuota(ConnectTenantContext $context): bool
    {
        return $this->overLimitWindow($context) !== null;
    }

    /**
     * Fast-path guard for the send flow: stamp headers, then throw a 429
     * QUOTA_EXCEEDED when a peek shows the budget is already exhausted.
     *
     * @throws ConnectMessageException
     */
    public function ensureAvailable(ConnectTenantContext $context): void
    {
        $over = $this->overLimitWindow($context);

        if ($over !== null) {
            $this->stampHeadersFor($context);
            Services::response()->setHeader(
                ConnectConfig::HEADER_RETRY_AFTER,
                (string) max(1, $over['resetAt'] - time()),
            );

            throw new ConnectMessageException(
                ConnectConfig::ERROR_QUOTA_EXCEEDED,
                'Connect message quota exceeded.',
                429,
            );
        }
    }

    /**
     * Atomically claim one unit from every enabled quota window, INSIDE the
     * caller's DB transaction so a rolled-back send refunds the unit. Throws
     * 429 QUOTA_EXCEEDED when any enabled window is at capacity.
     *
     * @throws ConnectMessageException
     */
    public function consumeMessage(ConnectTenantContext $context): void
    {
        if (! $this->config->quotaEnabled) {
            return;
        }

        if (! $this->store->available()) {
            log_message('error', 'Connect quota counters table missing — quotas not enforced (fail-open).');

            return;
        }

        $credential = $this->credentialKey($context);
        $overPeriod = null;
        $overReset  = 0;

        // Month first: rejecting on the monthly window must not spend a daily
        // unit. When both are enabled a rejected daily send still counts one
        // month unit (documented).
        foreach (['month', 'day'] as $period) {
            $limit = (int) $this->config->messageQuotaPerMonth;
            if ($period === 'day') {
                $limit = (int) $this->config->messageQuotaPerDay;
            }
            if ($limit <= 0) {
                continue;
            }

            $window = $this->windowFor($context, $period);

            try {
                // A unit may only be claimed while capacity remains, so peek
                // (not reserve) decides denial. The send that fills the LAST
                // unit is still accepted: reserve() clamps at capacity, so a
                // post-claim count can never signal "over" by itself.
                $usage = $this->store->peek(
                    $context->tenantId(),
                    $credential,
                    $context->environment(),
                    self::KIND,
                    $window['scope'],
                    $window['window'],
                    $window['bucketStart'],
                );

                if ($usage >= $limit) {
                    $overPeriod = $period;
                    $overReset  = $window['resetAt'];

                    break; // never spend the remaining windows on a rejected send
                }

                $this->store->reserve(
                    $context->tenantId(),
                    $credential,
                    $context->environment(),
                    self::KIND,
                    $window['scope'],
                    $window['window'],
                    $window['bucketStart'],
                    $limit,
                );
            } catch (DatabaseException $e) {
                if (! $this->config->quotaFailOpen) {
                    throw $e;
                }
                log_message('error', 'Connect quota reserve failed (fail-open): ' . $e->getMessage());
            }
        }

if ($overPeriod !== null) {
                $this->stampHeadersFor($context);
            Services::response()->setHeader(
                ConnectConfig::HEADER_RETRY_AFTER,
                (string) max(1, $overReset - time()),
            );

            throw new ConnectMessageException(
                ConnectConfig::ERROR_QUOTA_EXCEEDED,
                'Connect message quota exceeded.',
                429,
            );
        }

        $this->stampHeadersFor($context);
    }

    /**
     * Stamp X-Connect-Quota-Limit / -Remaining / -Reset / -Period for the most
     * constrained enabled window. No-op when quotas are disabled or the
     * counter table is unavailable.
     */
    public function stampHeadersFor(ConnectTenantContext $context): void
    {
        if (! $this->config->quotaEnabled) {
            return;
        }
        if (! $this->store->available()) {
            return;
        }

        $credential = $this->credentialKey($context);
        $best       = null;

        foreach ($this->activeWindows() as $period => $limit) {
            $window = $this->windowFor($context, $period);

            $count = $this->store->peek(
                $context->tenantId(),
                $credential,
                $context->environment(),
                self::KIND,
                $window['scope'],
                $window['window'],
                $window['bucketStart'],
            );

            $remaining    = max(0, $limit - $count);
            $currentBest = $best;

            if ($currentBest === null || $remaining < $currentBest['remaining']) {
                $best = [
                    'limit'     => $limit,
                    'remaining' => $remaining,
                    'resetAt'   => $window['resetAt'],
                    'period'    => $period,
                ];
            }
        }

        if ($best === null) {
            return;
        }

        Services::response()
            ->setHeader(ConnectConfig::QUOTA_HEADER_LIMIT, (string) $best['limit'])
            ->setHeader(ConnectConfig::QUOTA_HEADER_REMAINING, (string) $best['remaining'])
            ->setHeader(ConnectConfig::QUOTA_HEADER_RESET, (string) $best['resetAt'])
            ->setHeader(ConnectConfig::QUOTA_HEADER_PERIOD, $best['period']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The FIRST enabled window already at/above capacity (month first, then
     * day), or null when every enabled window still has budget. Mirrors the
     * order of the consume loop so the peek guard and the atomic consume
     * never disagree about which window is constraining.
     *
     * @return array{period: string, resetAt: int}|null
     */
    private function overLimitWindow(ConnectTenantContext $context): ?array
    {
        if (! $this->config->quotaEnabled) {
            return null;
        }

        if (! $this->store->available()) {
            log_message('error', 'Connect quota counters table missing — quotas not enforced (fail-open).');

            return null;
        }

        $credential = $this->credentialKey($context);

        foreach ($this->activeWindows() as $period => $limit) {
            $window = $this->windowFor($context, $period);

            $count = $this->store->peek(
                $context->tenantId(),
                $credential,
                $context->environment(),
                self::KIND,
                $window['scope'],
                $window['window'],
                $window['bucketStart'],
            );

            if ($count >= $limit) {
                return [
                    'period'  => $period,
                    'resetAt' => $window['resetAt'],
                ];
            }
        }

        return null;
    }

    /**
     * Calendar windows. Month is required-to-exist heading into billing; day
     * is optional (0 = disabled). Server-local time, consistent with the rest
     * of the app's date handling.
     */
    private function windowFor(ConnectTenantContext $context, string $period): array
    {
        $now = time();

        if ($period === 'month') {
            $bucketEpoch = (int) strtotime(date('Y-m-01 00:00:00', $now));
            $resetEpoch  = (int) strtotime('first day of next month', $now);

            return [
                'scope'       => 'messages.month',
                'window'      => $resetEpoch - $bucketEpoch,
                'bucketStart' => date('Y-m-d H:i:s', $bucketEpoch),
                'resetAt'     => $resetEpoch,
            ];
        }

        $bucketEpoch = (int) strtotime(date('Y-m-d 00:00:00', $now));
        $resetEpoch  = (int) strtotime('tomorrow', $now);

        return [
            'scope'       => 'messages.day',
            'window'      => $resetEpoch - $bucketEpoch,
            'bucketStart' => date('Y-m-d H:i:s', $bucketEpoch),
            'resetAt'     => $resetEpoch,
        ];
    }

    /**
     * Enabled (limit > 0) periods → limit map. Disabled windows are excluded
     * entirely so they never influence the peek/consume/header decisions
     * (a 0-limit window must not look "exhausted").
     *
     * @return array<string, int>
     */
    private function activeWindows(): array
    {
        $windows = [
            'month' => (int) $this->config->messageQuotaPerMonth,
            'day'   => (int) $this->config->messageQuotaPerDay,
        ];

        return array_filter($windows, static fn (int $limit): bool => $limit > 0);
    }

    private function credentialKey(ConnectTenantContext $context): int
    {
        return $this->config->quotaCredentialScoped ? $context->credentialDbId() : 0;
    }
}
<?php

namespace App\Services\Connect;

/**
 * Humming Bird Connect — result of a rate-limit check.
 *
 * Carries both the allow/deny verdict and the header-friend metadata for the
 * window that was the binding constraint (the most restrictive enabled window
 * on success, the offending window on a 429). Kept intentionally small; the
 * filter consumes it straight into RateLimit-* / Retry-After headers.
 */
class ConnectRateLimitDecision
{
    public function __construct(
        private bool $allowed,
        private ?string $errorCode,
        private ?int $limit,
        private int $remaining,
        private int $resetAt,
        private ?int $retryAfter,
    ) {
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function resetAt(): int
    {
        return $this->resetAt;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
<?php

namespace App\Services\Connect;

use Exception;

/**
 * Humming Bird Connect — worker retryable failure (Phase 5).
 *
 * Thrown by the Connect worker when a job failed for a TEMPORARY reason
 * (transient DB error, deadlock, timeout, infrastructure blip) that should be
 * retried with exponential backoff rather than failed permanently.
 */
class ConnectWorkerRetryableException extends Exception
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

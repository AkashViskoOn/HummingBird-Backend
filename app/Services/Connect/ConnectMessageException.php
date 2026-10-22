<?php

namespace App\Services\Connect;

use RuntimeException;

/**
 * Domain exception for Connect inbound message errors.
 *
 * Carries a machine-readable error code (from Config\Connect) and the
 * corresponding HTTP status so the controller can translate it without
 * knowing domain details.
 */
class ConnectMessageException extends RuntimeException
{
    private string $errorCode;
    private int    $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
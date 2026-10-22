<?php

namespace App\Services\Connect;

use RuntimeException;

/**
 * Humming Bird Connect — domain exception for message-status + webhook errors.
 *
 * Mirrors ConnectMessageException: carries a machine-readable error code (from
 * Config\Connect) and the corresponding HTTP status so controllers translate
 * it without knowing domain details.
 */
class ConnectStatusException extends RuntimeException
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
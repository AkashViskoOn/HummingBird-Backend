<?php

namespace App\Services\Connect;

use RuntimeException;

/**
 * Humming Bird Connect — user-mapping domain error.
 *
 * Carries the stable Connect error code plus the HTTP status the controller
 * should return. Internal database/SQL details are never attached.
 */
class ConnectMappingException extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private int $httpStatus = 409,
    ) {
        parent::__construct($message);
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
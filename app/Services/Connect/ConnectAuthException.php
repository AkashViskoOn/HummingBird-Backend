<?php

namespace App\Services\Connect;

use RuntimeException;

/**
 * Humming Bird Connect — authentication failure signal.
 *
 * Carries a public Connect error code (see Config\Connect::ERROR_*).
 * The message is ALWAYS a safe generic string — never internals.
 */
class ConnectAuthException extends RuntimeException
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

    public function httpStatus(): int
    {
        return \Config\Connect::statusForError($this->errorCode);
    }
}
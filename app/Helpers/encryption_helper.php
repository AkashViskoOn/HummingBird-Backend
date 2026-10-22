<?php

use App\Helpers\EncryptionService;

function encryptMessage(string $message): string
{
    $service = new EncryptionService();
    return $service->encryptMessage($message);
}

function decryptMessage(string $encryptedMessage): string
{
    $service = new EncryptionService();
    return $service->decryptMessage($encryptedMessage);
}

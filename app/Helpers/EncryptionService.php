<?php

namespace App\Helpers;

class EncryptionService
{
    private string $key;
    private const CIPHER = 'aes-256-gcm';
    private const KEY_HASH = 'sha256';

    public function __construct()
    {
        $key = getenv('ENCRYPTION_KEY');
        if ($key === false || $key === '') {
            throw new \RuntimeException(
                'ENCRYPTION_KEY is not set. Refusing to use the insecure default key. '
                . 'Set ENCRYPTION_KEY in the environment before using EncryptionService.'
            );
        }
        $this->key = hash(self::KEY_HASH, $key, true);
    }

    public function encryptMessage(string $message): string
    {
        try {
            $iv = random_bytes(12);
            $tag = '';

            $encrypted = openssl_encrypt(
                $message,
                self::CIPHER,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );

            if ($encrypted === false) {
                throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
            }

            return base64_encode($iv . $tag . $encrypted);
        } catch (\Exception $e) {
            log_message('error', 'Encryption failed: ' . $e->getMessage());
            return '';
        }
    }

    public function decryptMessage(string $encryptedMessage): string
    {
        try {
            $decoded = base64_decode($encryptedMessage, true);
            if ($decoded === false || strlen($decoded) < 28) {
                return $encryptedMessage;
            }

            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $ciphertext = substr($decoded, 28);

            $decrypted = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return $decrypted !== false ? $decrypted : $encryptedMessage;
        } catch (\Exception $e) {
            log_message('error', 'Decryption failed: ' . $e->getMessage());
            return $encryptedMessage;
        }
    }

    public function encryptMultiLayer(string $message): string
    {
        return $this->encryptMessage($message);
    }

    public function decryptMultiLayer(string $encryptedMessage): string
    {
        return $this->decryptMessage($encryptedMessage);
    }
}

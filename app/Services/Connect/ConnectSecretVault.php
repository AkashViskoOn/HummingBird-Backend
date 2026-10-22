<?php

namespace App\Services\Connect;

use RuntimeException;

/**
 * Humming Bird Connect — API secret vault.
 *
 * API secrets are NEVER stored or logged in plaintext. This service encrypts
 * them at rest (AES-256-CBC + random IV + HMAC-SHA256 integrity tag) using a
 * key derived from the CONNECT_SECRET_ENCRYPTION_KEY environment variable
 * (falls back to ENCRYPTION_KEY for environments that already ship it).
 *
 * The stored blob is what ConnectApiCredentialModel.secret_hash holds:
 *   base64(iv) . ':' . base64(hmac . ciphertext)
 *
 * The hmac is a manual hash_hmac('sha256') integrity tag (32 bytes) — the
 * openssl $tag parameter is NOT used because AES-256-CBC is not an AEAD
 * cipher and newer PHP builds reject passing a tag to it.
 */
class ConnectSecretVault
{
    private const CIPHER = 'aes-256-cbc';

    private ?string $key = null;

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $source = getenv('CONNECT_SECRET_ENCRYPTION_KEY') ?: getenv('ENCRYPTION_KEY') ?: '';
        if ($source === '') {
            throw new RuntimeException(
                'CONNECT_SECRET_ENCRYPTION_KEY (or ENCRYPTION_KEY) must be configured to store Connect API secrets.'
            );
        }

        // Derive a fixed-length 32-byte key regardless of env key length.
        $this->key = hash('sha256', $source, true);

        return $this->key;
    }

    /**
     * Encrypt a plaintext secret for storage.
     */
    public function seal(string $plaintext): string
    {
        $iv  = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt Connect API secret.');
        }

        $hmac = hash_hmac('sha256', $ciphertext, $this->key(), true);

        return base64_encode($iv) . ':' . base64_encode($hmac . $ciphertext);
    }

    /**
     * Decrypt a stored secret blob. The result must not be persisted/logged.
     */
    public function open(string $blob): string
    {
        $parts = explode(':', $blob, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Malformed Connect secret blob.');
        }

        $iv  = base64_decode($parts[0], true);
        $raw = base64_decode($parts[1], true);
        if ($iv === false || $raw === false || strlen($iv) !== openssl_cipher_iv_length(self::CIPHER)) {
            throw new RuntimeException('Malformed Connect secret payload.');
        }

        $hmac = substr($raw, 0, 32);
        $ciphertext = substr($raw, 32);

        $expected = hash_hmac('sha256', $ciphertext, $this->key(), true);
        if (! hash_equals($expected, $hmac)) {
            throw new RuntimeException('Connect secret integrity check failed.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt Connect API secret.');
        }

        return $plaintext;
    }
}
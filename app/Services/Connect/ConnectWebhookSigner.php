<?php

namespace App\Services\Connect;

use Config\Connect as ConnectConfig;

/**
 * Humming Bird Connect — outbound webhook signature (Phase 7).
 *
 * Signs the EXACT raw HTTP body so the downstream consumer can verify the
 * webhook really came from Humming Bird and has not been tampered with:
 *
 *   signature = sha256 hex of HMAC-SHA256(secret, timestamp . "." . raw_body)
 *   X-HummingBird-Signature: t=<timestamp>,v1=<signature>
 *   X-HummingBird-Timestamp: <timestamp epoch seconds>
 *   X-HummingBird-Event-Id:  <delivery event UUID>
 *   X-HummingBird-Event:      message.sent
 *
 * The secret is the per-webhook signing secret sealed at rest in
 * connect_webhooks.secret_hash. Timestamp tolerance is shared with the inbound
 * API (`Connect::$apiTimestampTolerance`), so consumers reusing the same guard
 * get the same replay window.
 */
class ConnectWebhookSigner
{
    public const VERSION_PREFIX = 'v1';

    public function sign(string $secret, string $timestamp, string $rawBody): string
    {
        $canonical  = $timestamp . '.' . $rawBody;
        $signature  = hash_hmac(ConnectConfig::HMAC_ALGORITHM, $canonical, $secret, false);

        return 't=' . $timestamp . ',' . self::VERSION_PREFIX . '=' . $signature;
    }

    public function verify(string $secret, string $timestamp, string $rawBody, string $signatureHeader): bool
    {
        $expected = $this->sign($secret, $timestamp, $rawBody);

        return hash_equals($expected, trim($signatureHeader));
    }

    public function isFresh(string $timestamp, ?int $tolerance = null): bool
    {
        $tolerance = $tolerance ?? (int) (config(ConnectConfig::class)->apiTimestampTolerance);

        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $tolerance;
    }
}
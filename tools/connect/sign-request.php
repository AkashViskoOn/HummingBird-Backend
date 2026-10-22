<?php

/**
 * Humming Bird Connect — development request-signing helper.
 *
 * Generates the HMAC-SHA256 signature + headers for a Connect API request so
 * you can test locally with curl / Postman.
 *
 * Usage (POSIX / PowerShell):
 *   php tools/connect/sign-request.php --secret <TEST_SECRET> [options]
 *   $env:TEST_SECRET='...'; php tools/connect/sign-request.php
 *
 * Options:
 *   --method   GET|POST|PUT|PATCH|DELETE   (default GET)
 *   --path     /api/v1/connect/auth/test   (default)
 *   --secret   secret value (or env TEST_SECRET)
 *   --body     raw request body string     (body is hashed exactly as-is)
 *   --body-file path to a file whose exact bytes are the body
 *   --timestamp unix seconds (defaults to now)
 *   --request-id reuse an id (defaults to a fresh req_<random>)
 *
 * NEVER commits real secrets: supply your own TEST_SECRET only.
 */

declare(strict_types=1);

if (getenv('CI_ENVIRONMENT') === 'production') {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') !== 0) {
        continue;
    }
    $parts = explode('=', substr($arg, 2), 2);
    $name  = $parts[0];
    $args[$name] = $parts[1] ?? '';
}

$next = static function (string $name, array &$argv) {
    $value = null;
    while (($key = array_search("--{$name}", $argv, true)) !== false) {
        $value = $argv[$key + 1] ?? null;
        array_splice($argv, $key, 2);
    }
    return $value;
};

$method      = strtoupper($args['method'] ?? $next('method', $argv) ?? 'GET');
$path        = ($args['path'] ?? $next('path', $argv) ?? '/api/v1/connect/auth/test');
$secret      = $args['secret'] ?? $next('secret', $argv) ?? getenv('TEST_SECRET');
$timestamp   = $args['timestamp'] ?? $next('timestamp', $argv) ?? (string) time();
$requestId   = $args['request-id'] ?? $next('request-id', $argv) ?? 'req_' . bin2hex(random_bytes(16));
$bodyFile    = $args['body-file'] ?? $next('body-file', $argv);
$body        = $bodyFile !== null ? file_get_contents($bodyFile) : ($args['body'] ?? $next('body', $argv) ?? '');

if ($secret === '') {
    fwrite(STDERR, "Missing secret. Pass --secret or set the TEST_SECRET env var.\n");
    exit(1);
}

$bodyHash = hash('sha256', (string) $body);
$canonical = "$method\n$path\n$timestamp\n$requestId\n$bodyHash";
$signature = hash_hmac('sha256', $canonical, $secret, false);

echo "=== Humming Bird Connect — signed request ===\n";
echo "METHOD      : {$method}\n";
echo "PATH        : {$path}\n";
echo "TIMESTAMP   : {$timestamp}\n";
echo "REQUEST_ID  : {$requestId}\n";
echo "BODY_HASH   : {$bodyHash}\n";
echo "--- canonical string ---\n{$canonical}\n";
echo "--- signature (hex) ---\n{$signature}\n";
echo "--- headers ---\n";
echo "X-HummingBird-Key: <YOUR_API_KEY>\n";
echo "X-HummingBird-Signature: {$signature}\n";
echo "X-HummingBird-Timestamp: {$timestamp}\n";
echo "X-HummingBird-Request-Id: {$requestId}\n";
echo "Content-Type: application/json\n";
echo "--- curl example ---\n";
echo "curl -i 'https://localhost:8080{$path}' \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -H 'X-HummingBird-Key: <YOUR_API_KEY>' \\\n";
echo "  -H 'X-HummingBird-Signature: {$signature}' \\\n";
echo "  -H 'X-HummingBird-Timestamp: {$timestamp}' \\\n";
echo "  -H 'X-HummingBird-Request-Id: {$requestId}' \\\n";
if ($body !== '') {
    $escaped = json_encode($body, JSON_UNESCAPED_SLASHES);
    echo "  -d {$escaped}\n";
}
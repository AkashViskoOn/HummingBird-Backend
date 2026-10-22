<?php

namespace App\Services\Connect;

use CodeIgniter\HTTP\IncomingRequest;
use Config\Connect as ConnectConfig;
use App\Models\Connect\ConnectApiCredentialModel;
use App\Models\Connect\ConnectIdempotencyKeyModel;
use App\Models\Connect\ConnectTenantModel;
use Config\Database;

/**
 * Humming Bird Connect — request authentication & tenant identification.
 *
 * Verifies every authenticated Connect request:
 *   headers → api_key lookup → tenant status → credential status →
 *   environment → HTTPS → content-type → timestamp → body hash →
 *   canonical string → HMAC-SHA256 → replay guard → tenant context.
 *
 * On success the authenticated tenant context is stored on this service for
 * the current request; controllers read it via `context()`.
 *
 * The API secret is never logged, never echoed, and only briefly decrypted
 * in memory for the signature comparison.
 */
class ConnectAuthenticationService
{
    private ?ConnectTenantContext $context = null;

    private ?ConnectCredentialService $credentials = null;

    public function __construct(?ConnectCredentialService $credentials = null)
    {
        $this->credentials = $credentials ?? new ConnectCredentialService();
    }

    // ------------------------------------------------------------------
    // Request lifecycle
    // ------------------------------------------------------------------

    /**
     * Authenticate the incoming request or throw ConnectAuthException.
     */
    public function authenticate(IncomingRequest $request): ConnectTenantContext
    {
        $config = config(ConnectConfig::class);

        $headers = $this->extractRequiredHeaders($request, $config);

        $this->verifyRequestId($headers['request_id']);
        $this->verifyTimestamp($headers['timestamp'], $config);
        $this->verifyTransport($request, $config);

        $credential = $this->credentials->findByApiKey($headers['api_key']);
        if ($credential === null) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_INVALID_API_KEY,
                'Invalid API credentials.'
            );
        }

        $tenant = $this->resolveTenant((int) $credential['tenant_id']);

        $this->verifyCredentialState($credential);
        $this->verifyEnvironment($credential, $config);
        $this->verifyBodyHashAndSignature($request, $headers, $credential, $config);

        // Replay guard — consumes a (tenant_id, request_id) uniqueness slot
        // atomically at the database level. A duplicate is a replay.
        $this->claimRequestId($tenant, $headers['request_id'], $request);

        // Best-effort usage marker; never fatal.
        try {
            (new ConnectApiCredentialModel(...$this->modelConn()))->update(
                (int) $credential['id'],
                ['last_used_at' => date('Y-m-d H:i:s')],
            );
        } catch (\Throwable $e) {
            // Ignore — auth outcome must not depend on a usage write.
        }

        $scopes = json_decode((string) ($credential['scopes'] ?? '[]'), true);
        if (! is_array($scopes)) {
            $scopes = [];
        }

        $this->context = new ConnectTenantContext(
            tenantId: (int) $tenant['id'],
            credentialDbId: (int) $credential['id'],
            credentialId: (string) $credential['credential_id'],
            environment: (string) $credential['environment'],
            requestId: $headers['request_id'],
            scopes: $scopes,
            name: isset($credential['name']) ? (string) $credential['name'] : null,
            expiresAt: isset($credential['expires_at']) ? (string) $credential['expires_at'] : null,
            role: isset($credential['role']) ? (string) $credential['role'] : ConnectConfig::CREDENTIAL_ROLE_API,
            allowedTenantIds: $this->decodeAllowedTenants($credential['allowed_tenant_ids'] ?? null),
        );

        return $this->context;
    }

    /**
     * Normalize the credentials.allowed_tenant_ids column. SQLite historically
     * materializes JSON columns as literal strings (or even the string 'NULL');
     * MySQL returns native JSON. null/''/'null'/'[]' mean the credential holds
     * no allowlist — i.e. ALL tenants for an admin.
     *
     * @return int[]|null  null = all tenants; [] = no tenants
     */
    private function decodeAllowedTenants($value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = $value;
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strtolower($trimmed) === 'null' || $trimmed === '[]') {
                return null;
            }
            $decoded = json_decode($trimmed, true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter(array_map('intval', array_values($decoded))));
    }

    public function context(): ?ConnectTenantContext
    {
        return $this->context;
    }

    public function hasContext(): bool
    {
        return $this->context !== null;
    }

    public function setContext(?ConnectTenantContext $context): void
    {
        $this->context = $context;
    }

    // ------------------------------------------------------------------
    // Verification steps
    // ------------------------------------------------------------------

    /**
     * @return array{api_key:string, signature:string, timestamp:string, request_id:string}
     */
    private function extractRequiredHeaders(IncomingRequest $request, $config): array
    {
        $get = static fn (string $name): string => trim((string) $request->getHeader($name)?->getValue());

        $apiKey     = $get(ConnectConfig::HEADER_API_KEY);
        $signature  = $get(ConnectConfig::HEADER_SIGNATURE);
        $timestamp  = $get(ConnectConfig::HEADER_TIMESTAMP);
        $requestId  = $get(ConnectConfig::HEADER_REQUEST_ID);

        if ($apiKey === '' || $signature === '' || $timestamp === '' || $requestId === '') {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_MISSING_AUTH_HEADERS,
                'Missing authentication headers.'
            );
        }

        return [
            'api_key'    => $apiKey,
            'signature'  => $signature,
            'timestamp'  => $timestamp,
            'request_id' => $requestId,
        ];
    }

    private function verifyRequestId(string $requestId): void
    {
        if (strlen($requestId) > ConnectConfig::REQUEST_ID_MAX_LEN
            || ! preg_match('/^[A-Za-z0-9:_-]+$/', $requestId)
            || strpos($requestId, ConnectConfig::REQUEST_ID_PREFIX) !== 0) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_INVALID_REQUEST_ID,
                'Invalid request identifier.'
            );
        }
    }

    private function verifyTimestamp(string $timestamp, $config): void
    {
        if (! ctype_digit($timestamp)) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_REQUEST_EXPIRED,
                'Request timestamp is invalid.'
            );
        }

        $diff = abs(time() - (int) $timestamp);
        if ($diff > $config->apiTimestampTolerance) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_REQUEST_EXPIRED,
                'Request timestamp is outside the accepted window.'
            );
        }
    }

    private function verifyTransport(IncomingRequest $request, $config): void
    {
        if ($config->httpsRequired && ! $request->isSecure()) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_HTTPS_REQUIRED,
                'HTTPS is required for this API.'
            );
        }

        if ($config->enforceJsonContentType && $this->hasBody($request)) {
            $contentType = (string) $request->header('Content-Type')?->getValue();
            if (stripos($contentType, 'application/json') === false) {
                throw new ConnectAuthException(
                    ConnectConfig::ERROR_INVALID_CONTENT_TYPE,
                    'Content-Type must be application/json.'
                );
            }
        }
    }

    private function hasBody(IncomingRequest $request): bool
    {
        $method = strtoupper($request->getMethod(true));

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && trim((string) $request->getBody()) !== '';
    }

    private function resolveTenant(int $tenantId): array
    {
        $tenant = (new ConnectTenantModel(...$this->modelConn()))->find($tenantId);
        if ($tenant === null) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_AUTHENTICATION_FAILED,
                'Authentication failed.'
            );
        }

        if ($tenant['status'] === ConnectConfig::TENANT_STATUS_SUSPENDED) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_TENANT_SUSPENDED,
                'Account access is suspended.'
            );
        }

        if ($tenant['status'] === ConnectConfig::TENANT_STATUS_DISABLED) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_TENANT_DISABLED,
                'Account access is disabled.'
            );
        }

        return $tenant;
    }

    private function verifyCredentialState(array $credential): void
    {
        if (in_array($credential['status'], [
            ConnectConfig::CREDENTIAL_STATUS_REVOKED,
            ConnectConfig::CREDENTIAL_STATUS_ROTATED,
        ], true)) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_CREDENTIAL_REVOKED,
                'API credential is revoked.'
            );
        }

        if ($credential['status'] !== ConnectConfig::CREDENTIAL_STATUS_ACTIVE) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_AUTHENTICATION_FAILED,
                'Authentication failed.'
            );
        }

        if (! empty($credential['expires_at'])
            && strtotime((string) $credential['expires_at']) < time()) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_CREDENTIAL_EXPIRED,
                'API credential has expired.'
            );
        }
    }

    private function verifyEnvironment(array $credential, $config): void
    {
        // The environment encoded in the api_key prefix must match the
        // credential environment — cross-environment keys are rejected.
        $prefixEnv = ConnectConfig::environmentForKeyPrefix((string) $credential['api_key']);

        if ($prefixEnv === null || $prefixEnv !== (string) $credential['environment']) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_ENVIRONMENT_MISMATCH,
                'Credential environment mismatch.'
            );
        }
    }

    private function verifyBodyHashAndSignature(
        IncomingRequest $request,
        array $headers,
        array $credential,
        $config,
    ): void {
        $bodyHash = hash(ConnectConfig::SIGNING_ALGORITHM, (string) $request->getBody());

        $method = strtoupper($request->getMethod(true));
        $path   = '/' . ltrim($request->getPath(), '/');

        $canonical = $this->canonicalString(
            $method,
            $path,
            $headers['timestamp'],
            $headers['request_id'],
            $bodyHash,
        );

        // Decrypt ONLY in memory, never persisted or logged.
        try {
            $secret = (new ConnectSecretVault())->open((string) $credential['secret_hash']);
        } catch (\Throwable $e) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_AUTHENTICATION_FAILED,
                'Authentication failed.'
            );
        }

        $expected = hash_hmac(
            ConnectConfig::HMAC_ALGORITHM,
            $canonical,
            $secret,
            false,
        );

        if (! hash_equals($expected, strtolower(trim($headers['signature'])))) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_INVALID_SIGNATURE,
                'Invalid request signature.'
            );
        }

        // Wipe the transient secret from memory as early as possible.
        unset($secret);
    }

    private function claimRequestId(array $tenant, string $requestId, IncomingRequest $request): void
    {
        $config = config(ConnectConfig::class);

        $canonical = $this->canonicalString(
            strtoupper($request->getMethod(true)),
            '/' . ltrim($request->getPath(), '/'),
            (string) time(),
            $requestId,
            hash(ConnectConfig::SIGNING_ALGORITHM, (string) $request->getBody()),
        );

        try {
            (new ConnectIdempotencyKeyModel(...$this->modelConn()))->insert([
                'tenant_id'       => (int) $tenant['id'],
                'idempotency_key' => $requestId,
                'request_hash'    => hash('sha256', $canonical),
                'resource_type'   => 'auth',
                'status'          => ConnectConfig::IDEMPOTENCY_STATUS_IN_PROGRESS,
                'expires_at'      => date('Y-m-d H:i:s', time() + $config->requestIdTtlSeconds),
            ]);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            throw new ConnectAuthException(
                ConnectConfig::ERROR_REQUEST_REPLAYED,
                'This request has already been processed.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Signing helpers (shared by tools and tests for byte-parity)
    // ------------------------------------------------------------------

    public function canonicalString(
        string $method,
        string $path,
        string $timestamp,
        string $requestId,
        string $bodyHash,
    ): string {
        return $method . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $requestId . "\n"
            . $bodyHash;
    }

    /**
     * Build a valid request signature.
     */
    public static function sign(
        string $method,
        string $path,
        string $timestamp,
        string $requestId,
        string $body,
        string $secret,
    ): string {
        $canonical = (new self())->canonicalString(
            strtoupper($method),
            $path,
            $timestamp,
            $requestId,
            hash(ConnectConfig::SIGNING_ALGORITHM, $body),
        );

        return hash_hmac(ConnectConfig::HMAC_ALGORITHM, $canonical, $secret, false);
    }

    /**
     * Generate a compliant request id (also used by tooling/tests).
     */
    public static function requestId(): string
    {
        return ConnectConfig::REQUEST_ID_PREFIX . bin2hex(random_bytes(16));
    }

    /**
     * Return a spread-able connection pair for constructor injection so the
     * models resolve to the tests group under CI_ENVIRONMENT=testing.
     */
    private function modelConn(): array
    {
        $db = Database::connect(config(Database::class)->defaultGroup);

        return [$db];
    }
}
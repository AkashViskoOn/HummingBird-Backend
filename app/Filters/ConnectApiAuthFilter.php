<?php

namespace App\Filters;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Services\Connect\ConnectAuthException;
use App\Services\Connect\ConnectAuthenticationService;
use App\Services\Connect\ConnectRateLimitDecision;
use App\Services\Connect\ConnectTenantContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — API authentication filter.
 *
 * Runs BEFORE the protected /api/v1/connect/* routes (see Config\Filters)
 * and NEVER touches existing Flutter / JWT / Socket.IO / public APIs.
 *
 * Responsibilities:
 *   - authenticate via ConnectAuthenticationService
 *   - enforce rate limits (Connect API only)
 *   - stamp responses with request id, no-store, and controlled CORS
 *   - append a sanitized row to connect_api_audit_logs
 *
 * Audit rows NEVER contain secrets, signatures, Authorization headers,
 * tokens, or sensitive bodies.
 */
class ConnectApiAuthFilter implements FilterInterface
{
    private ?ConnectTenantContext $context = null;

    // ------------------------------------------------------------------
    // before
    // ------------------------------------------------------------------

    public function before(RequestInterface $request, $arguments = null)
    {
        if (!$request instanceof IncomingRequest) {
            return $request;
        }

        // CORS preflight — let the after() hook answer with controlled headers.
        if ($request->getMethod(true) === 'OPTIONS') {
            return $request;
        }

        $auth = Services::connectauth();
        $auth->setContext(null); // never let a previous (shared) request leak context

        try {
            $this->context = $auth->authenticate($request);
        } catch (ConnectAuthException $e) {
            $this->recordAudit($request, null, $e->httpStatus(), 'denied', $e->errorCode());

            return $this->errorResponse($request, $e->errorCode(), $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Connect auth unexpected: ' . $e->getMessage());
            $this->recordAudit($request, null, 500, 'error', ConnectConfig::ERROR_AUTHENTICATION_FAILED);

            return $this->errorResponse($request, ConnectConfig::ERROR_AUTHENTICATION_FAILED, 'Authentication failed.');
        }

        // Optional per-route scope enforcement: filter('connect-auth', ['scope:messages:send']).
        $requiredScope = $this->extractRequiredScope($arguments);
        if ($requiredScope !== null && ! $this->context->hasScope($requiredScope)) {
            $this->recordAudit($request, $this->context, 403, 'denied', ConnectConfig::ERROR_INSUFFICIENT_SCOPE);

            return $this->errorResponse($request, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.');
        }

        // Phase 9 — admin role gate. Any `admin:` scope additionally requires
        // credential role 'admin': a plain ('api') credential is refused even
        // if the scope string found its way onto the credential.
        if ($requiredScope !== null
            && strpos($requiredScope, 'admin:') === 0
            && ! $this->context->isAdmin()) {
            $this->recordAudit($request, $this->context, 403, 'denied', ConnectConfig::ERROR_INSUFFICIENT_SCOPE);

            return $this->errorResponse($request, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.');
        }

        // Rate-limit (Phase 8 — atomic windows via ConnectRateLimiter). Connect API only.
        $decision = Services::connectratelimiter()->check(
            $this->context,
            $this->categoryForPath($request),
        );

        // Stamp the governing window's header info on every authenticated
        // response (shared response instance — also visible on 429).
        $this->applyRateLimitHeaders($decision);

        if (! $decision->allowed()) {
            $this->recordAudit($request, $this->context, 429, 'denied', $decision->errorCode());

            return $this->errorResponse($request, $decision->errorCode(), 'Rate limit exceeded.');
        }

        return $request;
    }

    // ------------------------------------------------------------------
    // after
    // ------------------------------------------------------------------

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (!$request instanceof IncomingRequest) {
            return $response;
        }

        // Filters are instantiated fresh for each position (before/after), so
        // the context set on `$this` in before() is gone here. The shared auth
        // service still carries it for the duration of the request.
        $context = Services::connectauth()->context();

        // Success audit is recorded here so the real response code is used.
        if ($context !== null) {
            $this->recordAudit(
                $request,
                $context,
                $response->getStatusCode(),
                $response->getStatusCode() < 400 ? 'success' : 'error',
                $response->getStatusCode() < 400 ? null : 'EXECUTION_ERROR',
            );
        }

        return $this->stampResponse($request, $response);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function extractRequiredScope($arguments): ?string
    {
        if (! is_array($arguments)) {
            return null;
        }

        // The scope is delivered as a single argument. CI4's filter argument
        // parser splits each filter spec on ':' keeping only the FIRST two
        // segments (filter name + one argument), so a) multi-part scopes can
        // never travel inside a colon-delimited filter spec and b) even a
        // single colon inside the argument would break the parse. Scope
        // values that contain ':' are therefore percent-encoded (%3A) in the
        // route filter strings (e.g. 'connect-auth:scope=users%3Awrite') and
        // decoded here. The legacy 'scope:' form is retained for single
        // colon-free scopes only.
        foreach ($arguments as $argument) {
            if (is_string($argument) && strpos($argument, 'scope=') === 0) {
                return urldecode(substr($argument, 6));
            }
        }

        // Form 1 (legacy): a single literal argument such as 'scope:messages'.
        foreach ($arguments as $argument) {
            if (is_string($argument) && strpos($argument, 'scope:') === 0) {
                return substr($argument, 6);
            }
        }

        return null;
    }

    private function errorResponse(IncomingRequest $request, string $code, string $message): ResponseInterface
    {
        $response = Services::response();

        $response->setStatusCode(ConnectConfig::statusForError($code));
        $response->setContentType('application/json', 'utf-8');
        $response->setBody(json_encode([
            'success'    => false,
            'error'      => [
                'code'    => $code,
                'message' => $message,
            ],
            'request_id' => $this->safeRequestId($request),
        ], JSON_UNESCAPED_UNICODE));

        return $this->stampResponse($request, $response);
    }

    /**
     * Controlled headers on every Connect response (errors included):
     * request id echo, no-store caching, and OPT-IN CORS — never '*'.
     */
    private function stampResponse(IncomingRequest $request, ResponseInterface $response): ResponseInterface
    {
        $response->setHeader(ConnectConfig::HEADER_REQUEST_ID, $this->safeRequestId($request));
        $response->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        $config = config(ConnectConfig::class);
        $origin = trim((string) $request->getHeader('Origin')?->getValue());
        $allowed = array_filter(array_map('trim', explode(',', (string) $config->corsAllowedOrigins)));

        if ($origin !== '' && ! empty($allowed) && in_array($origin, $allowed, true)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            $response->setHeader('Vary', 'Origin');
            $response->setHeader('Access-Control-Allow-Methods', $config->corsAllowedMethods);
            $response->setHeader('Access-Control-Allow-Headers', $config->corsAllowedHeaders);
            $response->setHeader('Access-Control-Max-Age', '86400');
        }

        return $response;
    }

    /**
     * Map a Connect request path to a rate-limit category. Categories feed the
     * optional per-endpoint caps (ConnectConfig::$rateLimitCategories) on top
     * of the general windows; unknown endpoints simply fall into 'request'.
     */
    private function categoryForPath(IncomingRequest $request): string
    {
        $path = '/' . ltrim($request->getPath(), '/');

        if (strpos($path, '/connect/messages') !== false) {
            if (substr($path, -5) === '/send') {
                return 'messages.send';
            }

            return 'messages';
        }

        if (strpos($path, '/connect/webhooks') !== false) {
            return 'webhooks';
        }

        if (strpos($path, '/connect/users') !== false) {
            return 'users';
        }

        if (strpos($path, '/connect/auth') !== false) {
            return 'auth';
        }

        return 'request';
    }

    /**
     * Stamp the governing rate window's RateLimit-* headers (+ Retry-After on
     * an exhausted window). Applied on the shared response instance so even a
     * 429 body carries them.
     */
    private function applyRateLimitHeaders(ConnectRateLimitDecision $decision): void
    {
        if ($decision->limit() === null) {
            return;
        }

        $response = Services::response();

        $response->setHeader(ConnectConfig::HEADER_RATE_LIMIT_LIMIT, (string) $decision->limit())
            ->setHeader(ConnectConfig::HEADER_RATE_LIMIT_REMAINING, (string) $decision->remaining())
            ->setHeader(ConnectConfig::HEADER_RATE_LIMIT_RESET, (string) $decision->resetAt());

        if (! $decision->allowed() && $decision->retryAfter() !== null) {
            $response->setHeader(ConnectConfig::HEADER_RETRY_AFTER, (string) $decision->retryAfter());
        }
    }

    private function safeRequestId(IncomingRequest $request): string
    {
        $client = trim((string) $request->getHeader(ConnectConfig::HEADER_REQUEST_ID)?->getValue());

        if ($client === '' || strlen($client) > ConnectConfig::REQUEST_ID_MAX_LEN
            || ! preg_match('/^[A-Za-z0-9:_-]+$/', $client)) {
            return ConnectAuthenticationService::requestId();
        }

        return $client;
    }

    /**
     * Sanitized, secret-free audit write. Never stores headers like
     * X-HummingBird-Signature, Authorization, cookies, tokens or bodies.
     */
    private function recordAudit(
        IncomingRequest $request,
        ?ConnectTenantContext $context,
        int $responseCode,
        string $status,
        ?string $errorCode,
    ): void {
        try {
            $path = '/' . ltrim($request->getPath(), '/');

            $model = new ConnectApiAuditLogModel(
                Database::connect(config(Database::class)->defaultGroup),
            );

            $model->insert([
                'tenant_id'     => $context !== null ? $context->tenantId() : null,
                'credential_id' => $context !== null ? $context->credentialDbId() : null,
                'request_id'    => $context !== null ? $context->requestId() : $this->safeRequestId($request),
                'method'        => strtoupper($request->getMethod(true)),
                'endpoint'      => $path,
                'ip_address'    => substr((string) $request->getIPAddress(), 0, 45),
                'user_agent'    => substr(trim((string) $request->getUserAgent()), 0, 255),
                'request_hash'  => hash('sha256', strtoupper($request->getMethod(true)) . '|' . $path . '|' . ($context !== null ? $context->requestId() : '')),
                'response_code' => $responseCode,
                'status'        => $status,
                'error_code'    => $errorCode,
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the API response.
            log_message('error', 'Connect audit write failed: ' . $e->getMessage());
        }
    }
}

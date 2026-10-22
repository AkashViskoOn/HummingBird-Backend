<?php

namespace App\Controllers\Connect;

use App\Controllers\BaseController;
use App\Services\Connect\ConnectStatusException;
use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — shared base for the Phase 9 admin controllers.
 *
 * The connect-auth filter (scope + admin-role gate) already authenticated this
 * request; controllers here always re-check the tenant scope through
 * ConnectAdminService / ConnectAdminListService — a requested tenant id is
 * never trusted on its own.
 *
 * Response envelopes (admin API only):
 *   success: {"success": true,  "data": ...,        "request_id": ...}
 *   error:   {"success": false, "error": {code, message}, "request_id": ...}
 * (matches the filter's auth error shape and AuthController, NOT the consumer
 * `status` envelope).
 */
abstract class AdminApiController extends BaseController
{
    /**
     * The authenticated ConnectTenantContext (stamped by the filter). Null only
     * if the route somehow ran without the auth filter.
     */
    protected function context(): ?ConnectTenantContext
    {
        return Services::connectauth()->context();
    }

    /**
     * OK response.
     */
    protected function ok(ConnectTenantContext $context, array $data, int $httpStatus = 200)
    {
        return $this->response
            ->setStatusCode($httpStatus)
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'success'    => true,
                'data'       => $data,
                'request_id' => $context->requestId(),
            ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Error response — same shape as the filter's auth error so a client only
     * ever parses one error contract.
     */
    protected function error(ConnectTenantContext $context, string $code, string $message, int $httpStatus)
    {
        return $this->response
            ->setStatusCode($httpStatus)
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'success'    => false,
                'error'      => [
                    'code'    => $code,
                    'message' => $message,
                ],
                'request_id' => $context->requestId(),
            ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 401 when the route ran without the connect-auth filter (defensive).
     */
    protected function unauthorized()
    {
        return $this->response
            ->setStatusCode(401)
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'success'    => false,
                'error'      => [
                    'code'    => ConnectConfig::ERROR_AUTHENTICATION_FAILED,
                    'message' => 'Authentication failed.',
                ],
                'request_id' => 'unknown',
            ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Run a handler, translating ConnectStatusException (code + http status) and
     * generic Throwable (500) into the admin error envelope.
     *
     * @param callable(ConnectTenantContext):array $handler
     */
    protected function respond(ConnectTenantContext $context, callable $handler, int $okStatus = 200)
    {
        try {
            $data = $handler($context);

            return $data instanceof \CodeIgniter\HTTP\ResponseInterface
                ? $data
                : $this->ok($context, $data, $okStatus);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect admin handler error: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An unexpected error occurred.', 500);
        }
    }

    /**
     * GET query parameter with a default.
     */
    protected function get(string $key, $default = null)
    {
        $value = $this->request->getGet($key);

        return $value === null ? $default : $value;
    }

    /**
     * Decoded JSON request body (PATCH mutations). Malformed JSON → 400.
     */
    protected function jsonData(ConnectTenantContext $context): array
    {
        $raw = $this->request->getBody();
        $data = $raw === null || $raw === '' ? [] : json_decode($raw, true);

        if (! is_array($data)) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER,
                'Request body must be valid JSON.',
                400,
            );
        }

        return $data;
    }

    /**
     * Require the caller to be an admin credential carrying `$scope` — the
     * filter has already enforced this for the route; this is a defensive
     * double-check that also produces a clean 403 when a scope mismatch is
     * only discovered here.
     */
    protected function requireScope(ConnectTenantContext $context, string $scope): ConnectTenantContext
    {
        if (! $context->isAdmin() || ! $context->hasScope($scope)) {
            throw new ConnectStatusException(
                ConnectConfig::ERROR_INSUFFICIENT_SCOPE,
                'Insufficient API scope.',
                403,
            );
        }

        return $context;
    }
}
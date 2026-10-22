<?php

namespace App\Controllers\Connect;

use App\Controllers\BaseController;
use App\Services\Connect\ConnectMappingException;
use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — user mapping endpoints (Phase 3).
 *
 * POST   /api/v1/connect/users/map      — map external user → HB user
 * GET    /api/v1/connect/users/mapping  — look up a mapping by external user
 * DELETE /api/v1/connect/users/mapping  — unmap (soft delete)
 *
 * Tenant + environment come ONLY from the authenticated credential
 * (filter `connect-auth:scope:users:write`). Never trusts the request body
 * for tenancy. Responses contain no secrets and no internal identifiers
 * beyond the mapped Humming Bird user id.
 */
class UserController extends BaseController
{
    public function map()
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_USERS_WRITE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        $body = $this->jsonBody();

        if ($body === null) {
            return $this->error($context, ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Invalid JSON request body.', 400);
        }

        $externalUserId   = isset($body['external_user_id']) ? (string) $body['external_user_id'] : '';
        $hummingBirdUserId = isset($body['humming_bird_user_id']) ? (string) $body['humming_bird_user_id'] : '';

        try {
            $result = Services::connectusermapping()->map($context, $externalUserId, $hummingBirdUserId);
        } catch (ConnectMappingException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect user map unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result['status'], $result['data']);
    }

    public function mapping()
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_USERS_WRITE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        $externalUserId = (string) Services::request()->getGet('external_user_id');

        try {
            $result = Services::connectusermapping()->lookup($context, $externalUserId);
        } catch (ConnectMappingException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect user lookup unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result['status'], $result['data']);
    }

    public function unmap()
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_USERS_WRITE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        $body = $this->jsonBody();

        if ($body === null) {
            return $this->error($context, ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Invalid JSON request body.', 400);
        }

        $externalUserId = isset($body['external_user_id']) ? (string) $body['external_user_id'] : '';

        try {
            $result = Services::connectusermapping()->unmap($context, $externalUserId);
        } catch (ConnectMappingException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect user unmap unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result['status'], $result['data']);
    }

    /**
     * Parse the raw request body as JSON. Returns an array on success, an
     * empty array for an absent body (so the service reports the mandatory
     * missing field), or null when the body is present but malformed JSON.
     */
    private function jsonBody(): ?array
    {
        $raw = (string) Services::request()->getBody();
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function ok(ConnectTenantContext $context, int $status, array $data)
    {
        return Services::response()
            ->setStatusCode($status)
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'status'     => $status < 400,
                'data'       => $data,
                'message'    => 'ok',
                'request_id' => $context->requestId(),
            ]));
    }

    private function error(ConnectTenantContext $context, string $code, string $message, int $status)
    {
        return Services::response()
            ->setStatusCode($status)
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'status'     => false,
                'data'       => null,
                'message'    => $message,
                'error'      => ['code' => $code, 'message' => $message],
                'request_id' => $context->requestId(),
            ]));
    }
}
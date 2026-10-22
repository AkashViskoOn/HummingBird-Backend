<?php

namespace App\Controllers\Connect;

use App\Controllers\BaseController;
use App\Services\Connect\ConnectMappingException;
use App\Services\Connect\ConnectMessageException;
use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — inbound message endpoints (Phase 4).
 *
 * POST /api/v1/connect/messages/send       — send external message into HB
 * GET  /api/v1/connect/messages/:id        — look up by external_message_id
 *
 * Tenant + environment come ONLY from the authenticated credential
 * (filter `connect-auth`). Never trusts the request body for tenancy.
 * Responses use the consumer envelope:
 *   { "status": true|false, "data": {...}, "request_id": "..." }
 */
class MessageController extends BaseController
{
    /**
     * POST /api/v1/connect/messages/send
     *
     * Body (JSON):
     *   external_user_id      (required)  — platform user id
     *   body                  (required)  — plaintext message body
     *   external_message_id   (optional)  — platform message id (idempotency key)
     *   title                 (optional)  — message title (truncated to 255 chars)
     *   message_uuid          (optional)  — platform UUID
     *   external_event_id     (optional)  — platform event id
     *   metadata              (optional)  — arbitrary JSON metadata
     */
    public function send()
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_MESSAGES_SEND)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        $body = $this->jsonBody();

        if ($body === null) {
            return $this->error($context, ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Invalid JSON request body.', 400);
        }

        try {
            $result = Services::connectmessage()->queue($context, $body);
        } catch (ConnectMessageException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect message send unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result['status'], $result['data']);
    }

    /**
     * GET /api/v1/connect/messages/{external_message_id}
     */
    public function lookup(string $externalMessageId = '')
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_MESSAGES_READ)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if ($externalMessageId === '') {
            $externalMessageId = (string) Services::request()->getGet('external_message_id');
        }

        try {
            $result = Services::connectmessage()->lookup($context, $externalMessageId);
        } catch (ConnectMessageException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect message lookup unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result['status'], $result['data']);
    }

    // ------------------------------------------------------------------
    // Response helpers (mirror UserController envelope)
    // ------------------------------------------------------------------

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
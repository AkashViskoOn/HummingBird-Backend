<?php

namespace App\Controllers\Connect;

use App\Controllers\BaseController;
use App\Services\Connect\ConnectStatusException;
use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — message status endpoints (Phase 7).
 *
 *   GET /api/v1/connect/messages/status/{external_message_id}
 *       → current user-visible status + full transition ledger
 *   GET /api/v1/connect/messages/status/{external_message_id}/events
 *       → append-only transition history
 *
 * Tenant + environment come ONLY from the authenticated credential. Envelope
 * mirrors the Phase 4/5 consumer shape:
 *   { "status": bool, "data": {...}, "message": "ok", "request_id": "..." }
 */
class MessageStatusController extends BaseController
{
    /**
     * GET /api/v1/connect/messages/status/{external_message_id}
     */
    public function show(string $externalMessageId = '')
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_MESSAGES_STATUS)
            && ! $context->hasScope(ConnectConfig::SCOPE_MESSAGES_READ)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if ($externalMessageId === '') {
            $externalMessageId = (string) Services::request()->getGet('external_message_id');
        }

        if ($externalMessageId === '') {
            return $this->error($context, ConnectConfig::ERROR_STATUS_NOT_FOUND, 'external_message_id is required.', 400);
        }

        try {
            $result = Services::connectmessagestatus()->getStatus($context, $externalMessageId);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect status lookup unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result);
    }

    /**
     * GET /api/v1/connect/messages/status/{external_message_id}/events
     */
    public function events(string $externalMessageId = '')
    {
        $context = Services::connectauth()->context();

        if (! $context->hasScope(ConnectConfig::SCOPE_MESSAGES_STATUS)
            && ! $context->hasScope(ConnectConfig::SCOPE_MESSAGES_READ)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if ($externalMessageId === '') {
            $externalMessageId = (string) Services::request()->getGet('external_message_id');
        }

        if ($externalMessageId === '') {
            return $this->error($context, ConnectConfig::ERROR_STATUS_EVENT_NOT_FOUND, 'external_message_id is required.', 400);
        }

        try {
            $result = Services::connectmessagestatus()->getHistory($context, $externalMessageId);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect status history unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result);
    }

    private function ok(ConnectTenantContext $context, array $data)
    {
        return Services::response()
            ->setStatusCode(200)
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'status'     => true,
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
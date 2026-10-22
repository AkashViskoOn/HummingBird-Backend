<?php

namespace App\Controllers\Connect;

use App\Controllers\BaseController;
use App\Services\Connect\ConnectStatusException;
use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — webhook management endpoints (Phase 7).
 *
 *   GET    /api/v1/connect/webhooks          — list
 *   POST   /api/v1/connect/webhooks          — create (returns secret once)
 *   GET    /api/v1/connect/webhooks/{id}     — get
 *   PATCH  /api/v1/connect/webhooks/{id}     — update
 *   DELETE /api/v1/connect/webhooks/{id}     — delete
 *   POST   /api/v1/connect/webhooks/{id}/test — enqueue a webhook.test delivery
 *
 * Tenant + environment come ONLY from the authenticated credential. Envelope
 * mirrors the Phase 4/5 consumer shape:
 *   { "status": bool, "data": {...}, "message": "ok", "request_id": "..." }
 */
class WebhookController extends BaseController
{
    /**
     * GET /api/v1/connect/webhooks
     */
    public function index()
    {
        $context = $this->context();
        if ($context === null) {
            return $this->error($this->nakedContext(), ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if (! $context->hasScope(ConnectConfig::SCOPE_WEBHOOKS_MANAGE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        try {
            $result = Services::connectwebhook()->list($context);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook list unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, ['webhooks' => $result]);
    }

    /**
     * POST /api/v1/connect/webhooks
     */
    public function store()
    {
        $context = $this->context();
        if ($context === null) {
            return $this->error($this->nakedContext(), ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if (! $context->hasScope(ConnectConfig::SCOPE_WEBHOOKS_MANAGE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        $body = $this->jsonBody();
        if ($body === null) {
            return $this->error($context, ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Invalid JSON request body.', 400);
        }

        try {
            $result = Services::connectwebhook()->create($context, $body);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook create unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result, 201);
    }

    /**
     * GET /api/v1/connect/webhooks/{id}
     */
    public function show(int $webhookId)
    {
        $context = $this->context();
        if ($context === null) {
            return $this->error($this->nakedContext(), ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if (! $context->hasScope(ConnectConfig::SCOPE_WEBHOOKS_MANAGE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        try {
            $result = Services::connectwebhook()->get($context, $webhookId);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook get unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, ['webhook' => $result]);
    }

    /**
     * PATCH /api/v1/connect/webhooks/{id}
     */
    public function update(int $webhookId)
    {
        $context = $this->context();
        if ($context === null) {
            return $this->error($this->nakedContext(), ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if (! $context->hasScope(ConnectConfig::SCOPE_WEBHOOKS_MANAGE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        $body = $this->jsonBody();
        if ($body === null) {
            return $this->error($context, ConnectConfig::ERROR_INVALID_REQUEST_BODY, 'Invalid JSON request body.', 400);
        }

        try {
            $result = Services::connectwebhook()->update($context, $webhookId, $body);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook update unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result);
    }

    /**
     * DELETE /api/v1/connect/webhooks/{id}
     */
    public function destroy(int $webhookId)
    {
        $context = $this->context();
        if ($context === null) {
            return $this->error($this->nakedContext(), ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if (! $context->hasScope(ConnectConfig::SCOPE_WEBHOOKS_MANAGE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        try {
            Services::connectwebhook()->delete($context, $webhookId);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook delete unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, ['deleted' => true]);
    }

    /**
     * POST /api/v1/connect/webhooks/{id}/test
     */
    public function test(int $webhookId)
    {
        $context = $this->context();
        if ($context === null) {
            return $this->error($this->nakedContext(), ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        if (! $context->hasScope(ConnectConfig::SCOPE_WEBHOOKS_MANAGE)) {
            return $this->error($context, ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'Insufficient API scope.', 403);
        }

        try {
            $result = Services::connectwebhook()->test($context, $webhookId);
        } catch (ConnectStatusException $e) {
            return $this->error($context, $e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            log_message('error', 'Connect webhook test unexpected: ' . $e->getMessage());

            return $this->error($context, ConnectConfig::ERROR_DATABASE_ERROR, 'An internal error occurred.', 500);
        }

        return $this->ok($context, $result, 202);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function context(): ?ConnectTenantContext
    {
        try {
            return Services::connectauth()->context();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function nakedContext(): ConnectTenantContext
    {
        return new ConnectTenantContext(0, 0, '', 'test', 'req_none');
    }

    private function jsonBody(): ?array
    {
        $raw = trim((string) Services::request()->getBody());

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function ok(ConnectTenantContext $context, array $data, int $status = 200)
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
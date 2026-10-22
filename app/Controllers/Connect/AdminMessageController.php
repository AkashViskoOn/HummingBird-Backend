<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect admin — message metadata viewer (Phase 9).
 *
 * GET /api/v1/connect/admin/messages        (admin:connect:view)
 * GET /api/v1/connect/admin/messages/{id}    (admin:connect:view)
 *
 * Bodies, titles and payloads are NEVER selected — the list is metadata only,
 * and a row outside the admin's tenant allowlist surfaces as a 404.
 */
class AdminMessageController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->messages($ctx, $this->request->getGet());
        });
    }

    public function show($id)
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        $messageId = (int) $id;

        return $this->respond($context, function (ConnectTenantContext $ctx) use ($messageId) {
            $detail = Services::connectadminlist()->messageDetail($ctx, $messageId);

            if ($detail === []) {
                throw new \App\Services\Connect\ConnectStatusException(
                    ConnectConfig::ERROR_MESSAGE_NOT_FOUND,
                    'Message not found.',
                    404,
                );
            }

            return ['message' => $detail];
        });
    }
}
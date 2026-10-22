<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect admin — webhooks (Phase 9).
 *
 * GET   /api/v1/connect/admin/webhooks           (admin:connect:view)
 * GET   /api/v1/connect/admin/webhook-deliveries  (admin:connect:view)
 * PATCH /api/v1/connect/admin/webhooks/{id}/status (admin:connect:manage)
 * PATCH /api/v1/connect/admin/webhooks/{id}        (admin:connect:manage)
 *
 * Signing secrets are never selected. Mutations delegate to the existing
 * ConnectWebhookService (validation + SSRF checks + domain audit) via a
 * delegated target-tenant context — an admin may only touch webhooks inside
 * their allowlist (anything else → 404).
 */
class AdminWebhookController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->webhooks($ctx, $this->request->getGet());
        });
    }

    public function deliveries()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->webhookDeliveries($ctx, $this->request->getGet());
        });
    }

    public function setStatus($id)
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        $this->requireScope($context, ConnectConfig::SCOPE_ADMIN_MANAGE);

        $webhookId = (int) $id;

        return $this->respond($context, function (ConnectTenantContext $ctx) use ($webhookId) {
            $data = $this->jsonData($ctx);

            return Services::connectadmin()->setWebhookStatus(
                $ctx,
                $webhookId,
                (string) ($data['status'] ?? ''),
            );
        });
    }

    public function update($id)
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        $this->requireScope($context, ConnectConfig::SCOPE_ADMIN_MANAGE);

        $webhookId = (int) $id;

        return $this->respond($context, function (ConnectTenantContext $ctx) use ($webhookId) {
            $data = $this->jsonData($ctx);

            return Services::connectadmin()->updateWebhook($ctx, $webhookId, $data);
        });
    }
}
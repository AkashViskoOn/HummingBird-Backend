<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect admin — integrations (service identities) + credentials.
 *
 * GET  /api/v1/connect/admin/integrations                (admin:connect:view)
 * GET  /api/v1/connect/admin/credentials                 (admin:connect:view)
 * PATCH /api/v1/connect/admin/integrations/{id}/status   (admin:connect:manage)
 *
 * Filters: tenant_id (403 outside the admin allowlist), environment, status,
 * plus pagination / sort.
 */
class AdminIntegrationController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->integrations($ctx, $this->request->getGet());
        });
    }

    public function credentials()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->credentials($ctx, $this->request->getGet());
        });
    }

    public function setStatus($id)
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        $this->requireScope($context, ConnectConfig::SCOPE_ADMIN_MANAGE);

        $identityId = (int) $id;

        return $this->respond($context, function (ConnectTenantContext $ctx) use ($identityId) {
            $data = $this->jsonData($ctx);

            return Services::connectadmin()->setIdentityStatus(
                $ctx,
                $identityId,
                (string) ($data['status'] ?? ''),
            );
        });
    }
}
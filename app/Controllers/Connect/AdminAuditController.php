<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Services;

/**
 * Humming Bird Connect admin — audit log viewer (Phase 9).
 *
 * GET /api/v1/connect/admin/audit-logs  (admin:connect:audit)
 *
 * Reads connect_api_audit_logs (which never contains secrets/signatures/bodies)
 * scoped to the admin's tenant allowlist. Requests are filterable by
 * tenant_id, status, error_code, action, response_code, search, date range,
 * plus pagination / sort.
 */
class AdminAuditController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->auditLogs($ctx, $this->request->getGet());
        });
    }
}
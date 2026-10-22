<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Services;

/**
 * Humming Bird Connect admin — rate + quota usage monitor (Phase 9).
 *
 * GET /api/v1/connect/admin/usage  (admin:connect:usage)
 *
 * Reports the CURRENT quota periods (this month / today) and the latest live
 * rate windows per tenant+environment directly from connect_rate_limit_counters.
 */
class AdminUsageController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->usage($ctx, $this->request->getGet());
        });
    }
}
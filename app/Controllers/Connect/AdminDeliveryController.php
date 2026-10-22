<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Services;

/**
 * Humming Bird Connect admin — message delivery-event ledger (Phase 9).
 *
 * GET /api/v1/connect/admin/delivery-events  (admin:connect:view)
 *
 * Reads the append-only connect_message_status ledger (accepted → … → read | failed).
 */
class AdminDeliveryController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            return Services::connectadminlist()->deliveryEvents($ctx, $this->request->getGet());
        });
    }
}
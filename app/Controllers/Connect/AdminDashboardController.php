<?php

namespace App\Controllers\Connect;

use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect admin — dashboard metrics (Phase 9).
 *
 * GET /api/v1/connect/admin/dashboard
 * Scope: admin:connect:overview (admin role required by the filter).
 *
 * Optional GET params date_from / date_to (Y-m-d or Y-m-d H:i:s) bound the
 * message and webhook-delivery counts; every other card is a live count over
 * the admin-scoped tables.
 */
class AdminDashboardController extends AdminApiController
{
    public function index()
    {
        $context = $this->context();

        if ($context === null) {
            return $this->unauthorized();
        }

        return $this->respond($context, function (ConnectTenantContext $ctx) {
            $params  = $this->request->getGet();
            $tenant  = $params['tenant_id'] ?? null;
            $tenantId = null;

            if ($tenant !== null && trim((string) $tenant) !== '') {
                if (! preg_match('/^\d+$/', (string) $tenant)) {
                    throw new \App\Services\Connect\ConnectStatusException(
                        ConnectConfig::ERROR_ADMIN_INVALID_PARAMETER,
                        'tenant_id must be a positive integer.',
                        400,
                    );
                }
                $tenantId = (int) $tenant;
            }

            return Services::connectadmin()->overview(
                $ctx,
                $this->nullableString($params, 'date_from'),
                $this->nullableString($params, 'date_to'),
                $tenantId,
            );
        });
    }

    private function nullableString(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
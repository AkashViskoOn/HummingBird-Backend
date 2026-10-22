<?php

namespace App\Controllers\Connect;

use App\Controllers\BaseController;
use App\Services\Connect\ConnectTenantContext;
use Config\Connect as ConnectConfig;
use Config\Services;

/**
 * Humming Bird Connect — authentication smoke-test endpoint.
 *
 * GET /api/v1/connect/auth/test
 *
 * Development/testing only: verifies the signed request, tenant context and
 * scope, then echoes back ONLY safe context values. Never returns secrets.
 */
class AuthController extends BaseController
{
    public function test()
    {
        /** @var ConnectTenantContext $context */
        $context = Services::connectauth()->context();

        if ($context === null) {
            return $this->response
                ->setStatusCode(401)
                ->setContentType('application/json', 'utf-8')
                ->setBody(json_encode([
                    'success'    => false,
                    'error'      => ['code' => ConnectConfig::ERROR_AUTHENTICATION_FAILED, 'message' => 'Authentication failed.'],
                    'request_id' => 'unknown',
                ]));
        }

        // Scope enforcement lives both here (explicit) and optionally in the
        // filter via filter('connect-auth', ['scope:...']).
        if (! $context->hasScope(ConnectConfig::SCOPE_CONNECT_TEST)) {
            return $this->response
                ->setStatusCode(403)
                ->setContentType('application/json', 'utf-8')
                ->setBody(json_encode([
                    'success'    => false,
                    'error'      => ['code' => ConnectConfig::ERROR_INSUFFICIENT_SCOPE, 'message' => 'Insufficient API scope.'],
                    'request_id' => $context->requestId(),
                ]));
        }

        return $this->response
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode([
                'success'    => true,
                'data'       => [
                    'authenticated' => true,
                    'tenant_id'     => (string) $context->tenantId(),
                    'environment'   => $context->environment(),
                    'scopes'        => $context->scopes(),
                ],
                'request_id' => $context->requestId(),
            ]));
    }
}
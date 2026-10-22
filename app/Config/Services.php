<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Connect API authentication (Phase 2). Holds the authenticated tenant
     * context for the current request.
     */
    public static function connectauth(bool $getShared = true): \App\Services\Connect\ConnectAuthenticationService
    {
        if ($getShared) {
            return static::getSharedInstance('connectauth');
        }

        return new \App\Services\Connect\ConnectAuthenticationService();
    }

    /**
     * Connect API credential management (Phase 2).
     */
    public static function connectcredential(bool $getShared = true): \App\Services\Connect\ConnectCredentialService
    {
        if ($getShared) {
            return static::getSharedInstance('connectcredential');
        }

        return new \App\Services\Connect\ConnectCredentialService();
    }

    /**
     * Connect API rate-limit foundation (Phase 2) — Connect APIs only.
     */
    public static function connectratelimiter(bool $getShared = true): \App\Services\Connect\ConnectRateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('connectratelimiter');
        }

        return new \App\Services\Connect\ConnectRateLimiter();
    }

    /**
     * Connect API external user mapping (Phase 3).
     */
    public static function connectusermapping(bool $getShared = true): \App\Services\Connect\ConnectUserMappingService
    {
        if ($getShared) {
            return static::getSharedInstance('connectusermapping');
        }

        return new \App\Services\Connect\ConnectUserMappingService();
    }

    /**
     * Connect API inbound message delivery (Phase 4).
     */
    public static function connectmessage(bool $getShared = true): \App\Services\Connect\ConnectMessageService
    {
        if ($getShared) {
            return static::getSharedInstance('connectmessage');
        }

        return new \App\Services\Connect\ConnectMessageService();
    }

    /**
     * Connect API Flutter-compatible message cipher (Phase 4).
     */
    public static function connectmessagecipher(bool $getShared = true): \App\Services\Connect\ConnectMessageCipher
    {
        if ($getShared) {
            return static::getSharedInstance('connectmessagecipher');
        }

        return new \App\Services\Connect\ConnectMessageCipher();
    }

    /**
     * Connect API durable outbox service (Phase 5).
     */
    public static function connectmessageoutbox(bool $getShared = true): \App\Services\Connect\ConnectOutboxService
    {
        if ($getShared) {
            return static::getSharedInstance('connectmessageoutbox');
        }

        return new \App\Services\Connect\ConnectOutboxService();
    }

    /**
     * Connect API background message processor (Phase 5 worker).
     */
    public static function connectmessageprocessor(bool $getShared = true): \App\Services\Connect\ConnectMessageProcessor
    {
        if ($getShared) {
            return static::getSharedInstance('connectmessageprocessor');
        }

        return new \App\Services\Connect\ConnectMessageProcessor();
    }

    /**
     * Connect API message status ledger + webhook dispatch (Phase 7).
     */
    public static function connectmessagestatus(bool $getShared = true): \App\Services\Connect\ConnectMessageStatusService
    {
        if ($getShared) {
            return static::getSharedInstance('connectmessagestatus');
        }

        return new \App\Services\Connect\ConnectMessageStatusService();
    }

    /**
     * Connect API webhook registration management (Phase 7).
     */
    public static function connectwebhook(bool $getShared = true): \App\Services\Connect\ConnectWebhookService
    {
        if ($getShared) {
            return static::getSharedInstance('connectwebhook');
        }

        return new \App\Services\Connect\ConnectWebhookService();
    }

    /**
     * Connect API webhook delivery outbox (Phase 7 worker-side).
     */
    public static function connectwebhookdeliveries(bool $getShared = true): \App\Services\Connect\ConnectWebhookDeliveryService
    {
        if ($getShared) {
            return static::getSharedInstance('connectwebhookdeliveries');
        }

        return new \App\Services\Connect\ConnectWebhookDeliveryService();
    }

    /**
     * Connect API usage-quota metering (Phase 8) — tenants' shared message budget.
     */
    public static function connectquota(bool $getShared = true): \App\Services\Connect\ConnectQuotaService
    {
        if ($getShared) {
            return static::getSharedInstance('connectquota');
        }

        return new \App\Services\Connect\ConnectQuotaService();
    }

    /**
     * Connect admin authorization + operations (Phase 9). Resolves the admin's
     * tenant/environment allowlist from the authenticated credential and runs
     * the operator-facing mutations with full audit.
     */
    public static function connectadmin(bool $getShared = true): \App\Services\Connect\ConnectAdminService
    {
        if ($getShared) {
            return static::getSharedInstance('connectadmin');
        }

        return new \App\Services\Connect\ConnectAdminService();
    }

    /**
     * Connect admin dashboard list builders (Phase 9). Tenant-scoped, paginated,
     * secret-masked reads over the existing operational connect_* tables.
     */
    public static function connectadminlist(bool $getShared = true): \App\Services\Connect\ConnectAdminListService
    {
        if ($getShared) {
            return static::getSharedInstance('connectadminlist');
        }

        return new \App\Services\Connect\ConnectAdminListService();
    }
}

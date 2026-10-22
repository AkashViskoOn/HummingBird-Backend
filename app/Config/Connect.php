<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Humming Bird Connect — B2B platform constants.
 *
 * Enum-like values for the Connect subsystem. Controllers/services should
 * reference these constants instead of scattering magic strings.
 *
 * Tenant isolation is mandatory: every Connect query must be scoped with
 * `WHERE tenant_id = <current tenant>` — never query Connect records by id
 * without a tenant filter.
 */
class Connect extends BaseConfig
{
    // ------------------------------------------------------------------
    // Tenants: connect_tenants.status / .environment
    // ------------------------------------------------------------------
    public const TENANT_STATUS_ACTIVE     = 'active';
    public const TENANT_STATUS_SUSPENDED  = 'suspended';
    public const TENANT_STATUS_DISABLED   = 'disabled';

    public const TENANT_ENVIRONMENT_TEST        = 'test';
    public const TENANT_ENVIRONMENT_PRODUCTION  = 'production';

    // ------------------------------------------------------------------
    // API credentials: connect_api_credentials.status
    // ------------------------------------------------------------------
    public const CREDENTIAL_STATUS_ACTIVE   = 'active';
    public const CREDENTIAL_STATUS_REVOKED  = 'revoked';
    public const CREDENTIAL_STATUS_EXPIRED  = 'expired';

    public const CREDENTIAL_ID_PREFIX = 'hb_cred_';
    public const CREDENTIAL_MAX_SECRET_BYTES = 32;

    // Credential role (Phase 9 — admin dashboard). A credential with
    // role 'admin' may carry `admin:connect:*` scopes and (via
    // `allowed_tenant_ids`) sees tenants beyond its own; plain credentials stay
    // 'api' and can never reach the admin surface even if an admin scope is
    // granted (the filter enforces the role gate).
    public const CREDENTIAL_ROLE_API   = 'api';
    public const CREDENTIAL_ROLE_ADMIN = 'admin';

    // ------------------------------------------------------------------
    // Service identities: connect_service_identities.verification_status / .status
    // ------------------------------------------------------------------
    public const VERIFICATION_STATUS_PENDING   = 'pending';
    public const VERIFICATION_STATUS_VERIFIED  = 'verified';
    public const VERIFICATION_STATUS_REJECTED  = 'rejected';

    public const IDENTITY_STATUS_ACTIVE   = 'active';
    public const IDENTITY_STATUS_DISABLED = 'disabled';

    // ------------------------------------------------------------------
    // External users: connect_external_users.status
    // ------------------------------------------------------------------
    public const EXTERNAL_USER_STATUS_ACTIVE = 'active';
    public const EXTERNAL_USER_STATUS_SUSPENDED = 'suspended';

    // ------------------------------------------------------------------
    // User mappings: connect_user_mappings.mapping_status
    // ------------------------------------------------------------------
    public const MAPPING_STATUS_ACTIVE   = 'active';
    public const MAPPING_STATUS_UNMAPPED = 'unmapped';
    public const MAPPING_STATUS_BLOCKED  = 'blocked';
    public const MAPPING_STATUS_MAPPED   = 'mapped'; // Phase 3 — successful mapping

    // ------------------------------------------------------------------
    // External messages: connect_external_messages.message_type / .status
    // ------------------------------------------------------------------
    public const MESSAGE_TYPE_NOTIFICATION     = 'notification';
    public const MESSAGE_TYPE_TRANSACTIONAL    = 'transactional';
    public const MESSAGE_TYPE_REMINDER         = 'reminder';
    public const MESSAGE_TYPE_ALERT            = 'alert';

    public const MESSAGE_STATUS_ACCEPTED    = 'accepted';
    public const MESSAGE_STATUS_QUEUED      = 'queued';
    public const MESSAGE_STATUS_PROCESSING  = 'processing';
    public const MESSAGE_STATUS_SENT        = 'sent';
    public const MESSAGE_STATUS_DELIVERED   = 'delivered';
    public const MESSAGE_STATUS_READ        = 'read';
    public const MESSAGE_STATUS_FAILED      = 'failed';
    public const MESSAGE_STATUS_RETRYING    = 'retrying';

    // Ordered lifecycle for the connect_message_status ledger. A transition to
    // one of these is a forward-only move; the same status is recorded at most
    // once per message (UNIQUE(tenant, environment, external_message_id, status)).
    public const MESSAGE_STATUS_FLOW = [
        self::MESSAGE_STATUS_ACCEPTED,
        self::MESSAGE_STATUS_QUEUED,
        self::MESSAGE_STATUS_PROCESSING,
        self::MESSAGE_STATUS_SENT,
        self::MESSAGE_STATUS_DELIVERED,
        self::MESSAGE_STATUS_READ,
        self::MESSAGE_STATUS_FAILED,
        self::MESSAGE_STATUS_RETRYING,
    ];

    // Only these statuses are ever surfaced to the external platform through
    // the Connect status API. retrying / processing collapse to the last
    // stable user-visible state (the effectiveStatus mapping).
    public const STATUS_API_VISIBLE = [
        self::MESSAGE_STATUS_QUEUED,
        self::MESSAGE_STATUS_SENT,
        self::MESSAGE_STATUS_DELIVERED,
        self::MESSAGE_STATUS_READ,
        self::MESSAGE_STATUS_FAILED,
    ];

    // ------------------------------------------------------------------
    // Phase 5 — outbox: connect_message_outbox.status
    // ------------------------------------------------------------------
    public const OUTBOX_STATUS_PENDING    = 'pending';
    public const OUTBOX_STATUS_PROCESSING = 'processing';
    public const OUTBOX_STATUS_COMPLETED  = 'completed';
    public const OUTBOX_STATUS_RETRYING   = 'retrying';
    public const OUTBOX_STATUS_FAILED     = 'failed';

    // ------------------------------------------------------------------
    // Phase 5 — worker configuration.
    //
    // These are defaults resolved through Connect::$worker* property values
    // (see the constructor / env overrides at the bottom of this class) so
    // production can tune them via environment variables without touching
    // business logic. DELIVERY_MAX_ATTEMPTS below remains the message-level
    // retry ceiling; the worker honours the smaller, more specific *MaxAttempts
    // when both are present.
    // ------------------------------------------------------------------
    public int $workerBatchSize     = 20;     // jobs claimed per worker pass
    public int $workerSleepSeconds  = 2;      // idle sleep between passes
    public int $workerMaxAttempts   = 5;      // outbox max attempts before permanent failure
    public int $workerStaleTimeout  = 300;    // seconds a processing job may be stuck before recovery
    public string $workerIdPrefix   = 'hb-worker';
    public const WORKER_RETRY_DELAYS = [10, 30, 120, 600]; // exponential backoff (seconds)

    // ------------------------------------------------------------------
    // Message deliveries: connect_message_deliveries.status
    // ------------------------------------------------------------------
    public const DELIVERY_STATUS_QUEUED     = 'queued';
    public const DELIVERY_STATUS_PROCESSING = 'processing';
    public const DELIVERY_STATUS_SENT       = 'sent';
    public const DELIVERY_STATUS_DELIVERED  = 'delivered';
    public const DELIVERY_STATUS_READ       = 'read';
    public const DELIVERY_STATUS_FAILED     = 'failed';
    public const DELIVERY_STATUS_RETRYING   = 'retrying';

    public const DELIVERY_MAX_ATTEMPTS = 5;

    // ------------------------------------------------------------------
    // Idempotency: connect_idempotency_keys.status
    // ------------------------------------------------------------------
    public const IDEMPOTENCY_STATUS_COMPLETED = 'completed';
    public const IDEMPOTENCY_STATUS_FAILED    = 'failed';
    public const IDEMPOTENCY_STATUS_IN_PROGRESS = 'in_progress';

    // ------------------------------------------------------------------
    // Webhooks: connect_webhooks.status + event names
    // ------------------------------------------------------------------
    public const WEBHOOK_STATUS_ACTIVE   = 'active';
    public const WEBHOOK_STATUS_DISABLED = 'disabled';

    public const WEBHOOK_EVENT_MESSAGE_ACCEPTED    = 'message.accepted';
    public const WEBHOOK_EVENT_MESSAGE_QUEUED      = 'message.queued';
    public const WEBHOOK_EVENT_MESSAGE_PROCESSING  = 'message.processing';
    public const WEBHOOK_EVENT_MESSAGE_SENT        = 'message.sent';
    public const WEBHOOK_EVENT_MESSAGE_DELIVERED   = 'message.delivered';
    public const WEBHOOK_EVENT_MESSAGE_READ        = 'message.read';
    public const WEBHOOK_EVENT_MESSAGE_FAILED      = 'message.failed';
    public const WEBHOOK_EVENT_MESSAGE_RETRYING    = 'message.retrying';
    public const WEBHOOK_EVENT_WEBHOOK_TEST        = 'webhook.test';

    // The set of user-subscribable message lifecycle events.
    public const WEBHOOK_SUBSCRIBABLE_EVENTS = [
        self::WEBHOOK_EVENT_MESSAGE_ACCEPTED,
        self::WEBHOOK_EVENT_MESSAGE_QUEUED,
        self::WEBHOOK_EVENT_MESSAGE_PROCESSING,
        self::WEBHOOK_EVENT_MESSAGE_SENT,
        self::WEBHOOK_EVENT_MESSAGE_DELIVERED,
        self::WEBHOOK_EVENT_MESSAGE_READ,
        self::WEBHOOK_EVENT_MESSAGE_FAILED,
        self::WEBHOOK_EVENT_MESSAGE_RETRYING,
    ];

    // ------------------------------------------------------------------
    // Webhook deliveries: connect_webhook_deliveries.status
    // ------------------------------------------------------------------
    public const WEBHOOK_DELIVERY_STATUS_PENDING   = 'pending';
    public const WEBHOOK_DELIVERY_STATUS_PROCESSING = 'processing';
    public const WEBHOOK_DELIVERY_STATUS_DELIVERED = 'delivered';
    public const WEBHOOK_DELIVERY_STATUS_FAILED    = 'failed';
    public const WEBHOOK_DELIVERY_STATUS_RETRYING  = 'retrying';

    // Outbound webhook delivery max attempts before permanent failure.
    public const WEBHOOK_MAX_ATTEMPTS = 5;

    // ------------------------------------------------------------------
    // Webhook worker configuration (mirrors the Phase 5 message worker).
    // Overridden via env CONNECT_WEBHOOK_* in the constructor.
    // ------------------------------------------------------------------
    public int $webhookWorkerBatchSize     = 20;
    public int $webhookWorkerSleepSeconds  = 2;
    public int $webhookWorkerMaxAttempts   = 5;
    public int $webhookWorkerStaleTimeout  = 300;
    public const WEBHOOK_RETRY_DELAYS      = [10, 30, 120, 600];

    // Outbound HTTP client timeouts (seconds). connect = handshake.
    public int $webhookConnectTimeout = 4;
    public int $webhookTimeout        = 10;

    // Cap applied to a downstream Retry-After hint so a misbehaving endpoint
    // cannot stall the outbox for days (seconds).
    public int $webhookRetryAfterCap = 300;

    // Max raw payload size recorded on a delivery row (bytes) — protects the
    // ledger from pathological message sizes. Status webhook payloads are
    // fixed-shape (~500 B), so this is a safety valve, not an operating limit.
    // The hash + signed body are always computed over the STORED bytes so a
    // retry re-sends the byte-identical payload.
    public int $webhookMaxStoredPayload = 100000;

    // Retention for terminal (delivered/failed) webhook delivery rows. Rows
    // older than this are purged by `connect:webhook-cleanup`. Keeps the
    // durable outbox from growing forever (queue clean-up).
    public int $webhookDeliveryRetentionDays = 30;

    // ------------------------------------------------------------------
    // Phase 2 — Authentication & tenant isolation
    // ------------------------------------------------------------------
    // API credential status used by ConnectApiCredentialModel. 'rotated'
    // marks a credential superseded by rotateCredential() (immediately
    // invalid, same as revoked on the wire).
    public const CREDENTIAL_STATUS_ROTATED = 'rotated';

    // Request headers accepted by the Connect API.
    public const HEADER_API_KEY      = 'X-HummingBird-Key';
    public const HEADER_SIGNATURE    = 'X-HummingBird-Signature';
    public const HEADER_TIMESTAMP    = 'X-HummingBird-Timestamp';
    public const HEADER_REQUEST_ID   = 'X-HummingBird-Request-Id';

    // Request-ID prefix.
    public const REQUEST_ID_PREFIX   = 'req_';
    public const REQUEST_ID_MAX_LEN  = 64;

    // Replay detection: a request_id fingerprint is stored in
    // connect_idempotency_keys and rejected if seen again within this TTL.
    public int $requestIdTtlSeconds = 3600;

    // Clock-skew tolerance for X-HummingBird-Timestamp (seconds). Single
    // source of truth — do not hardcode 300 anywhere else.
    public int $apiTimestampTolerance = 300;

    // API key environment prefixes. The prefix embeds the environment so a
    // test key can never authenticate as a production key (and vice versa).
    public const KEY_PREFIX_TEST        = 'hb_test_';
    public const KEY_PREFIX_PRODUCTION  = 'hb_live_';

    public static function keyPrefixFor(string $environment): string
    {
        return $environment === self::TENANT_ENVIRONMENT_TEST
            ? self::KEY_PREFIX_TEST
            : self::KEY_PREFIX_PRODUCTION;
    }

    public static function environmentForKeyPrefix(string $apiKey): ?string
    {
        if (strpos($apiKey, self::KEY_PREFIX_TEST) === 0) {
            return self::TENANT_ENVIRONMENT_TEST;
        }
        if (strpos($apiKey, self::KEY_PREFIX_PRODUCTION) === 0) {
            return self::TENANT_ENVIRONMENT_PRODUCTION;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // API scopes (ConnectApiCredentialModel.scopes — JSON array).
    // ------------------------------------------------------------------
    public const SCOPE_MESSAGES_SEND     = 'messages:send';
    public const SCOPE_MESSAGES_READ     = 'messages:read';
    public const SCOPE_MESSAGES_STATUS   = 'messages:status';
    public const SCOPE_USERS_READ        = 'users:read';
    public const SCOPE_USERS_WRITE       = 'users:write';
    public const SCOPE_WEBHOOKS_MANAGE   = 'webhooks:manage';
    public const SCOPE_CONNECT_TEST      = 'connect:test'; // dev auth-check only

    // Default scope granted to a new credential (the auth/test endpoint
    // requires SCOPE_CONNECT_TEST).
    public const SCOPES_DEFAULT = [self::SCOPE_CONNECT_TEST];

    // ------------------------------------------------------------------
    // Phase 9 — Connect admin dashboard scopes. Admin endpoints sit under
    // /api/v1/connect/admin/* (operator-facing) and require BOTH the credential
    // role 'admin' AND one of these scopes — enforced by ConnectApiAuthFilter
    // for every scope string that starts with 'admin:'.
    // ------------------------------------------------------------------
    public const SCOPE_ADMIN_OVERVIEW = 'admin:connect:overview'; // dashboard metrics
    public const SCOPE_ADMIN_VIEW     = 'admin:connect:view';     // integrations/messages/delivery/webhooks lists
    public const SCOPE_ADMIN_USAGE    = 'admin:connect:usage';    // rate + quota monitoring
    public const SCOPE_ADMIN_AUDIT    = 'admin:connect:audit';    // audit log viewer
    public const SCOPE_ADMIN_MANAGE   = 'admin:connect:manage';   // safe mutations (identities/webhooks)

    public const ADMIN_SCOPES = [
        self::SCOPE_ADMIN_OVERVIEW,
        self::SCOPE_ADMIN_VIEW,
        self::SCOPE_ADMIN_USAGE,
        self::SCOPE_ADMIN_AUDIT,
        self::SCOPE_ADMIN_MANAGE,
    ];

    // ------------------------------------------------------------------
    // Connect API error codes (B2B surface only — never over HTTP 403/401
    // for the consumer app). Messages stay generic; never leak internals.
    // ------------------------------------------------------------------
    public const ERROR_AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
    public const ERROR_INVALID_API_KEY       = 'INVALID_API_KEY';
    public const ERROR_INVALID_SIGNATURE     = 'INVALID_SIGNATURE';
    public const ERROR_MISSING_AUTH_HEADERS  = 'MISSING_AUTH_HEADERS';
    public const ERROR_REQUEST_EXPIRED       = 'REQUEST_EXPIRED';
    public const ERROR_REQUEST_REPLAYED      = 'REQUEST_REPLAYED';
    public const ERROR_INVALID_REQUEST_ID    = 'INVALID_REQUEST_ID';
    public const ERROR_CREDENTIAL_REVOKED    = 'CREDENTIAL_REVOKED';
    public const ERROR_CREDENTIAL_EXPIRED    = 'CREDENTIAL_EXPIRED';
    public const ERROR_TENANT_SUSPENDED      = 'TENANT_SUSPENDED';
    public const ERROR_TENANT_DISABLED       = 'TENANT_DISABLED';
    public const ERROR_ENVIRONMENT_MISMATCH  = 'ENVIRONMENT_MISMATCH';
    public const ERROR_INSUFFICIENT_SCOPE    = 'INSUFFICIENT_SCOPE';
    public const ERROR_INVALID_CONTENT_TYPE  = 'INVALID_CONTENT_TYPE';
    public const ERROR_HTTPS_REQUIRED        = 'HTTPS_REQUIRED';
    public const ERROR_RATE_LIMIT_EXCEEDED   = 'RATE_LIMIT_EXCEEDED';
    public const ERROR_QUOTA_EXCEEDED        = 'QUOTA_EXCEEDED'; // Phase 8 — usage quota exhausted (429)

    // Phase 3 — user mapping / external identity domain errors.
    public const ERROR_INVALID_REQUEST_BODY      = 'INVALID_REQUEST_BODY';
    public const ERROR_MISSING_EXTERNAL_USER_ID  = 'MISSING_EXTERNAL_USER_ID';
    public const ERROR_INVALID_EXTERNAL_USER_ID  = 'INVALID_EXTERNAL_USER_ID';
    public const ERROR_MISSING_HUMMING_BIRD_USER_ID = 'MISSING_HUMMING_BIRD_USER_ID';
    public const ERROR_HUMMING_BIRD_USER_NOT_FOUND = 'HUMMING_BIRD_USER_NOT_FOUND';
    public const ERROR_HUMMING_BIRD_USER_INACTIVE  = 'HUMMING_BIRD_USER_INACTIVE';
    public const ERROR_MAPPING_NOT_FOUND           = 'MAPPING_NOT_FOUND';
    public const ERROR_MAPPING_CONFLICT            = 'MAPPING_CONFLICT';
    public const ERROR_USER_ALREADY_MAPPED         = 'USER_ALREADY_MAPPED';
    public const ERROR_MAPPING_ALREADY_REMOVED     = 'MAPPING_ALREADY_REMOVED';
    public const ERROR_IDEMPOTENCY_CONFLICT        = 'IDEMPOTENCY_CONFLICT';
    public const ERROR_DATABASE_ERROR              = 'DATABASE_ERROR';

    // Phase 4 — connect inbound messages.
    public const ERROR_MISSING_EXTERNAL_MESSAGE_ID = 'MISSING_EXTERNAL_MESSAGE_ID';
    public const ERROR_INVALID_EXTERNAL_MESSAGE_ID = 'INVALID_EXTERNAL_MESSAGE_ID';
    public const ERROR_MESSAGE_ALREADY_EXISTS      = 'CONNECT_MESSAGE_ALREADY_EXISTS';
    public const ERROR_MISSING_MESSAGE_BODY        = 'MISSING_MESSAGE_BODY';
    public const ERROR_INVALID_MESSAGE_BODY        = 'INVALID_MESSAGE_BODY';
    public const ERROR_MESSAGE_BODY_TOO_LONG       = 'MESSAGE_BODY_TOO_LONG';
    public const ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND = 'CONNECT_SERVICE_IDENTITY_NOT_FOUND';
    public const ERROR_MESSAGE_NOT_FOUND           = 'CONNECT_MESSAGE_NOT_FOUND';
    public const ERROR_SERVICE_IDENTITY_INACTIVE   = 'SERVICE_IDENTITY_INACTIVE';

    // Phase 6 — connect message status + webhook domain errors.
    public const ERROR_STATUS_NOT_FOUND            = 'CONNECT_STATUS_NOT_FOUND';
    public const ERROR_STATUS_EVENT_NOT_FOUND      = 'CONNECT_STATUS_EVENT_NOT_FOUND';
    public const ERROR_INVALID_WEBHOOK_URL         = 'INVALID_WEBHOOK_URL';
    public const ERROR_WEBHOOK_URL_NOT_ALLOWED     = 'WEBHOOK_URL_NOT_ALLOWED';
    public const ERROR_WEBHOOK_NOT_FOUND           = 'WEBHOOK_NOT_FOUND';
    public const ERROR_WEBHOOK_INVALID_EVENTS      = 'WEBHOOK_INVALID_EVENTS';
    public const ERROR_WEBHOOK_SECRET_TOO_SHORT    = 'WEBHOOK_SECRET_TOO_SHORT';
    public const ERROR_WEBHOOK_NAME_TOO_LONG       = 'WEBHOOK_NAME_TOO_LONG';
    public const ERROR_WEBHOOK_ID_UNAVAILABLE      = 'WEBHOOK_ID_UNAVAILABLE';

    // Phase 5 — worker-internal structured error codes. These classify outbox
    // failures for retry/fail decisions and are recorded on the outbox row +
    // audit log; they are NEVER exposed to external API clients (the worker is
    // not an HTTP surface). Missing mappings / inactive users / bad identity are
    // permanent business rejections already surfaced synchronously by queue();
    // the ones below are the worker-side classifications.
    public const OUTBOX_ERR_LOCK_FAILED            = 'CONNECT_OUTBOX_LOCK_FAILED';
    public const OUTBOX_ERR_STALE_JOB              = 'CONNECT_OUTBOX_STALE_JOB';
    public const OUTBOX_ERR_ALREADY_PROCESSED      = 'CONNECT_MESSAGE_ALREADY_PROCESSED';
    public const OUTBOX_ERR_RETRY_EXHAUSTED        = 'CONNECT_RETRY_EXHAUSTED';
    public const OUTBOX_ERR_EXISTING_MSG_NOT_FOUND = 'CONNECT_EXISTING_MESSAGE_NOT_FOUND';
    public const OUTBOX_ERR_PROCESSING_FAILED      = 'CONNECT_MESSAGE_PROCESSING_FAILED';
    public const OUTBOX_ERR_RECORD_LOST            = 'CONNECT_EXTERNAL_MESSAGE_NOT_FOUND';
    public const OUTBOX_ERR_INVALID_STATE          = 'CONNECT_OUTBOX_INVALID_STATE';

    // External record identifiers (alphanumeric, platform-supplied ids).
    public const EXTERNAL_USER_ID_MAX_LEN = 150;
    public const EXTERNAL_MESSAGE_ID_MAX_LEN = 150;

    // Message bodies carried as Flutter-compatible ciphertext. The ciphertext
    // is base64url so it is safe to store/transport as a single token.
    public const MESSAGE_BODY_MAX_LEN = 12000;
    public const MESSAGE_TITLE_MAX_LEN = 255;

    // Domain audit actions written to connect_api_audit_logs.action.
    public const AUDIT_ACTION_MAPPING_CREATED         = 'CONNECT_USER_MAPPING_CREATED';
    public const AUDIT_ACTION_MAPPING_ALREADY_EXISTS  = 'CONNECT_USER_MAPPING_ALREADY_EXISTS';
    public const AUDIT_ACTION_MAPPING_CONFLICT        = 'CONNECT_USER_MAPPING_CONFLICT';
    public const AUDIT_ACTION_MAPPING_USER_ALREADY    = 'CONNECT_USER_MAPPING_USER_ALREADY_MAPPED';
    public const AUDIT_ACTION_MAPPING_REMOVED         = 'CONNECT_USER_MAPPING_REMOVED';
    public const AUDIT_ACTION_MAPPING_LOOKUP          = 'CONNECT_USER_MAPPING_LOOKUP';
    public const AUDIT_ACTION_MAPPING_ALREADY_REMOVED = 'CONNECT_USER_MAPPING_ALREADY_REMOVED';

    // Phase 4 — connect message inbound audit actions.
    public const AUDIT_ACTION_MESSAGE_ACCEPTED  = 'CONNECT_MESSAGE_ACCEPTED';
    public const AUDIT_ACTION_MESSAGE_DUPLICATE = 'CONNECT_MESSAGE_DUPLICATE';
    public const AUDIT_ACTION_MESSAGE_REJECTED  = 'CONNECT_MESSAGE_REJECTED';
    public const AUDIT_ACTION_MESSAGE_LOOKUP    = 'CONNECT_MESSAGE_LOOKUP';

    // Phase 5 — worker lifecycle audit actions (written with credential_id null).
    public const AUDIT_ACTION_MESSAGE_QUEUED        = 'CONNECT_MESSAGE_QUEUED';
    public const AUDIT_ACTION_MESSAGE_PROCESSING    = 'CONNECT_MESSAGE_PROCESSING';
    public const AUDIT_ACTION_MESSAGE_SENT          = 'CONNECT_MESSAGE_SENT';
    public const AUDIT_ACTION_MESSAGE_RETRYING      = 'CONNECT_MESSAGE_RETRYING';
    public const AUDIT_ACTION_MESSAGE_FAILED        = 'CONNECT_MESSAGE_FAILED';
    public const AUDIT_ACTION_MESSAGE_STALE_RECOVERY = 'CONNECT_MESSAGE_STALE_RECOVERY';

    // Phase 6 — message status + webhook lifecycle audit actions.
    public const AUDIT_ACTION_MESSAGE_STATUS_UPDATED    = 'CONNECT_MESSAGE_STATUS_UPDATED';
    public const AUDIT_ACTION_MESSAGE_STATUS_LOOKUP     = 'CONNECT_MESSAGE_STATUS_LOOKUP';
    public const AUDIT_ACTION_MESSAGE_STATUS_HISTORY    = 'CONNECT_MESSAGE_STATUS_HISTORY';
    public const AUDIT_ACTION_WEBHOOK_CREATED           = 'CONNECT_WEBHOOK_CREATED';
    public const AUDIT_ACTION_WEBHOOK_UPDATED           = 'CONNECT_WEBHOOK_UPDATED';
    public const AUDIT_ACTION_WEBHOOK_DELETED           = 'CONNECT_WEBHOOK_DELETED';
    public const AUDIT_ACTION_WEBHOOK_LISTED            = 'CONNECT_WEBHOOK_LISTED';
    public const AUDIT_ACTION_WEBHOOK_TEST              = 'CONNECT_WEBHOOK_TEST';
    public const AUDIT_ACTION_WEBHOOK_EVENT_QUEUED      = 'CONNECT_WEBHOOK_EVENT_QUEUED';
    public const AUDIT_ACTION_WEBHOOK_DELIVERY_STARTED  = 'CONNECT_WEBHOOK_DELIVERY_STARTED';
    public const AUDIT_ACTION_WEBHOOK_DELIVERY_SUCCEEDED = 'CONNECT_WEBHOOK_DELIVERY_SUCCEEDED';
    public const AUDIT_ACTION_WEBHOOK_DELIVERY_FAILED   = 'CONNECT_WEBHOOK_DELIVERY_FAILED';
    public const AUDIT_ACTION_WEBHOOK_STALE_RECOVERY    = 'CONNECT_WEBHOOK_STALE_RECOVERY';

    // Phase 9 — admin dashboard mutation audit actions. Read/list admin views
    // are already captured by the filter's automatic per-request audit rows.
    public const AUDIT_ACTION_ADMIN_IDENTITY_STATUS_CHANGED = 'CONNECT_ADMIN_IDENTITY_STATUS_CHANGED';

    // Phase 9 — admin parameter/query validation failure (400).
    public const ERROR_ADMIN_INVALID_PARAMETER = 'CONNECT_ADMIN_INVALID_PARAMETER';

    // HTTP recipes for the codes above (documented contract).
    public const ERROR_STATUS_MAP = [
        self::ERROR_AUTHENTICATION_FAILED => 401,
        self::ERROR_INVALID_API_KEY       => 401,
        self::ERROR_INVALID_SIGNATURE     => 401,
        self::ERROR_MISSING_AUTH_HEADERS  => 401,
        self::ERROR_REQUEST_EXPIRED       => 401,
        self::ERROR_REQUEST_REPLAYED      => 401,
        self::ERROR_INVALID_REQUEST_ID    => 401,
        self::ERROR_CREDENTIAL_REVOKED    => 401,
        self::ERROR_CREDENTIAL_EXPIRED    => 401,
        self::ERROR_TENANT_SUSPENDED      => 403,
        self::ERROR_TENANT_DISABLED       => 403,
        self::ERROR_ENVIRONMENT_MISMATCH  => 403,
        self::ERROR_INSUFFICIENT_SCOPE    => 403,
        self::ERROR_INVALID_CONTENT_TYPE  => 415,
        self::ERROR_HTTPS_REQUIRED        => 400,
        self::ERROR_RATE_LIMIT_EXCEEDED   => 429,
        self::ERROR_QUOTA_EXCEEDED        => 429,
        // Phase 3 — user mapping.
        self::ERROR_INVALID_REQUEST_BODY      => 400,
        self::ERROR_MISSING_EXTERNAL_USER_ID  => 400,
        self::ERROR_INVALID_EXTERNAL_USER_ID  => 400,
        self::ERROR_MISSING_HUMMING_BIRD_USER_ID => 400,
        self::ERROR_HUMMING_BIRD_USER_NOT_FOUND => 404,
        self::ERROR_HUMMING_BIRD_USER_INACTIVE  => 422,
        self::ERROR_MAPPING_NOT_FOUND           => 404,
        self::ERROR_MAPPING_CONFLICT            => 409,
        self::ERROR_USER_ALREADY_MAPPED         => 409,
        self::ERROR_MAPPING_ALREADY_REMOVED     => 409,
        self::ERROR_IDEMPOTENCY_CONFLICT        => 409,
        self::ERROR_DATABASE_ERROR              => 500,
        // Phase 4 — connect messages.
        self::ERROR_MISSING_EXTERNAL_MESSAGE_ID => 400,
        self::ERROR_INVALID_EXTERNAL_MESSAGE_ID => 400,
        self::ERROR_MESSAGE_ALREADY_EXISTS      => 409,
        self::ERROR_MISSING_MESSAGE_BODY        => 400,
        self::ERROR_INVALID_MESSAGE_BODY        => 400,
        self::ERROR_MESSAGE_BODY_TOO_LONG       => 413,
        self::ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND => 404,
        self::ERROR_SERVICE_IDENTITY_INACTIVE   => 422,
        self::ERROR_MESSAGE_NOT_FOUND           => 404,
        // Phase 6 — message status + webhooks.
        self::ERROR_STATUS_NOT_FOUND            => 404,
        self::ERROR_STATUS_EVENT_NOT_FOUND      => 404,
        self::ERROR_INVALID_WEBHOOK_URL         => 400,
        self::ERROR_WEBHOOK_URL_NOT_ALLOWED     => 422,
        self::ERROR_WEBHOOK_NOT_FOUND           => 404,
        self::ERROR_WEBHOOK_INVALID_EVENTS      => 400,
        self::ERROR_WEBHOOK_SECRET_TOO_SHORT    => 400,
        self::ERROR_WEBHOOK_NAME_TOO_LONG       => 400,
        // Phase 9 — admin dashboard.
        self::ERROR_ADMIN_INVALID_PARAMETER  => 400,
    ];

    public static function statusForError(string $errorCode): int
    {
        return self::ERROR_STATUS_MAP[$errorCode] ?? 401;
    }

    // ------------------------------------------------------------------
    // Phase 2 — Signature / canonical request
    // ------------------------------------------------------------------
    // canonical = HTTP_METHOD . "\n" . PATH . "\n" . TIMESTAMP . "\n"
    //             . REQUEST_ID . "\n" . sha256(raw body)
    // signature = hash_hmac('sha256', canonical, api_secret, false)
    public const SIGNING_ALGORITHM = 'sha256';
    public const HMAC_ALGORITHM    = 'sha256';

    // ------------------------------------------------------------------
    // Phase 2 — Transport & content policy
    // ------------------------------------------------------------------
    // HTTPS is enforced on the Connect API whenever running in 'production'.
    // Local development / tests stay on HTTP unless explicitly hardened.
    public bool $httpsRequired = true;

    // Require application/json on requests that carry a body.
    public bool $enforceJsonContentType = true;

    // Controlled CORS for the B2B surface. CORS is NOT authentication.
    public string $corsAllowedOrigins = ''; // comma-separated; '' = no CORS headers
    public string $corsAllowedMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    public string $corsAllowedHeaders = 'Content-Type, X-HummingBird-Key, X-HummingBird-Signature, X-HummingBird-Timestamp, X-HummingBird-Request-Id';

    // ------------------------------------------------------------------
    // Phase 2 — Rate-limit foundation (Connect APIs only).
    // 0 / null disables a given window.
    // ------------------------------------------------------------------
    public bool $rateLimitEnabled = true;
    public int $rateLimitPerMinute = 60; // default: 60 requests / minute / tenant
    public ?int $rateLimitPerSecond = null;
    public string $rateLimitWindowUnit = 'seconds'; // fixed window

    // ------------------------------------------------------------------
    // Phase 8 — Rate limits (extended, still Connect APIs only).
    // ------------------------------------------------------------------
    // Optional per-hour window (0 = disabled).
    public int $rateLimitPerHour = 0;

    // Per-category per-minute caps, e.g. ['messages.send' => 100]. Endpoint
    // categories are derived from the request path by ConnectApiAuthFilter
    // (messages.send | messages | webhooks | users | auth | request). General
    // windows (`rateLimitPerSecond/Minute/Hour`) always apply on top of any
    // matching category cap.
    public array $rateLimitCategories = [];

    // Expired 'rate' buckets (older than this, seconds) are purged
    // opportunistically when a fresh bucket is created. 0 disables clean-up.
    public int $rateLimitBucketRetentionSeconds = 3600;

    // Store failure behaviour: true = allow the request + log (never take the
    // B2B surface down), false = fall back to the legacy non-atomic audit-row
    // counter. Both stay safe; only the atomic reserve is skipped.
    public bool $rateLimitFailOpen = true;

    // ------------------------------------------------------------------
    // Phase 8 — Usage quotas (accepted Connect message operations).
    // Scope: (tenant, environment) by default — every API credential of a
    // tenant shares its one quota, exactly like the tenant's shared service
    // identity. Set $quotaCredentialScoped to give every credential its own
    // independent budget.
    // ------------------------------------------------------------------
    public bool $quotaEnabled = true;
    public int $messageQuotaPerMonth = 100000; // monthly safety cap (0 = disabled)
    public int $messageQuotaPerDay = 0;        // optional daily window (0 = disabled)
    public bool $quotaCredentialScoped = false;
    public bool $quotaFailOpen = true;         // counter-store failure → allow + log

    // Standard rate-limit response headers (stamped by ConnectApiAuthFilter).
    public const HEADER_RATE_LIMIT_LIMIT     = 'RateLimit-Limit';
    public const HEADER_RATE_LIMIT_REMAINING = 'RateLimit-Remaining';
    public const HEADER_RATE_LIMIT_RESET     = 'RateLimit-Reset';
    public const HEADER_RETRY_AFTER          = 'Retry-After';

    // Connect usage-quota response headers (stamped by ConnectQuotaService).
    public const QUOTA_HEADER_LIMIT     = 'X-Connect-Quota-Limit';
    public const QUOTA_HEADER_REMAINING = 'X-Connect-Quota-Remaining';
    public const QUOTA_HEADER_RESET     = 'X-Connect-Quota-Reset';
    public const QUOTA_HEADER_PERIOD    = 'X-Connect-Quota-Period';

    // ------------------------------------------------------------------
    // Phase 2 — Audit & logging hygiene.
    // ------------------------------------------------------------------
    // Header values that must NEVER reach logs or audit rows.
    public const REDACTED_HEADERS = [
        self::HEADER_SIGNATURE,
        'Authorization',
        'Cookie',
        'Proxy-Authorization',
        'X-Api-Key',
    ];

    public function __construct()
    {
        parent::__construct();

        if (ENVIRONMENT !== 'production') {
            $this->httpsRequired = false;
        }

        // Phase 5 worker overrides — production tunes these via env vars
        // without touching business logic. Values are clamped to sane bounds.
        $this->workerBatchSize    = (int) (getenv('CONNECT_WORKER_BATCH_SIZE') ?: $this->workerBatchSize);
        $this->workerSleepSeconds = (int) (getenv('CONNECT_WORKER_SLEEP_SECONDS') ?: $this->workerSleepSeconds);
        $this->workerMaxAttempts  = (int) (getenv('CONNECT_WORKER_MAX_ATTEMPTS') ?: $this->workerMaxAttempts);
        $this->workerStaleTimeout = (int) (getenv('CONNECT_WORKER_STALE_TIMEOUT') ?: $this->workerStaleTimeout);

        if ($this->workerBatchSize < 1) {
            $this->workerBatchSize = 1;
        }
        if ($this->workerSleepSeconds < 0) {
            $this->workerSleepSeconds = 0;
        }
        if ($this->workerMaxAttempts < 1) {
            $this->workerMaxAttempts = 1;
        }
        if ($this->workerStaleTimeout < 30) {
            $this->workerStaleTimeout = 30;
        }

        // Phase 6 — webhook worker env overrides (same clamping rules).
        $this->webhookWorkerBatchSize    = (int) (getenv('CONNECT_WEBHOOK_BATCH_SIZE') ?: $this->webhookWorkerBatchSize);
        $this->webhookWorkerSleepSeconds = (int) (getenv('CONNECT_WEBHOOK_SLEEP_SECONDS') ?: $this->webhookWorkerSleepSeconds);
        $this->webhookWorkerMaxAttempts  = (int) (getenv('CONNECT_WEBHOOK_MAX_ATTEMPTS') ?: $this->webhookWorkerMaxAttempts);
        $this->webhookWorkerStaleTimeout = (int) (getenv('CONNECT_WEBHOOK_STALE_TIMEOUT') ?: $this->webhookWorkerStaleTimeout);
        $this->webhookConnectTimeout     = (int) (getenv('CONNECT_WEBHOOK_CONNECT_TIMEOUT') ?: $this->webhookConnectTimeout);
        $this->webhookTimeout            = (int) (getenv('CONNECT_WEBHOOK_TIMEOUT') ?: $this->webhookTimeout);

        if ($this->webhookWorkerBatchSize < 1) {
            $this->webhookWorkerBatchSize = 1;
        }
        if ($this->webhookWorkerSleepSeconds < 0) {
            $this->webhookWorkerSleepSeconds = 0;
        }
        if ($this->webhookWorkerMaxAttempts < 1) {
            $this->webhookWorkerMaxAttempts = 1;
        }
        if ($this->webhookWorkerStaleTimeout < 30) {
            $this->webhookWorkerStaleTimeout = 30;
        }
        if ($this->webhookConnectTimeout < 1) {
            $this->webhookConnectTimeout = 1;
        }
        if ($this->webhookTimeout < 1) {
            $this->webhookTimeout = 1;
        }
        if ($this->webhookRetryAfterCap < 5) {
            $this->webhookRetryAfterCap = 5;
        }

        // Phase 8 — rate-limit + quota env overrides. Empty/absent vars keep
        // the compiled default; explicit '0' disables the corresponding window.
        $this->rateLimitEnabled     = $this->envBool('CONNECT_RATE_LIMIT_ENABLED', $this->rateLimitEnabled);
        $this->rateLimitPerMinute   = $this->envInt('CONNECT_RATE_LIMIT_PER_MINUTE', $this->rateLimitPerMinute);
        $this->rateLimitPerSecond   = $this->envOptInt('CONNECT_RATE_LIMIT_PER_SECOND', $this->rateLimitPerSecond);
        $this->rateLimitPerHour     = $this->envInt('CONNECT_RATE_LIMIT_PER_HOUR', $this->rateLimitPerHour);
        $this->rateLimitFailOpen    = $this->envBool('CONNECT_RATE_LIMIT_FAIL_OPEN', $this->rateLimitFailOpen);
        $this->quotaEnabled         = $this->envBool('CONNECT_QUOTA_ENABLED', $this->quotaEnabled);
        $this->messageQuotaPerMonth = $this->envInt('CONNECT_QUOTA_MESSAGES_PER_MONTH', $this->messageQuotaPerMonth);
        $this->messageQuotaPerDay   = $this->envInt('CONNECT_QUOTA_MESSAGES_PER_DAY', $this->messageQuotaPerDay);
        $this->quotaCredentialScoped = $this->envBool('CONNECT_QUOTA_CREDENTIAL_SCOPED', $this->quotaCredentialScoped);
        $this->quotaFailOpen        = $this->envBool('CONNECT_QUOTA_FAIL_OPEN', $this->quotaFailOpen);
    }

    /**
     * Strict int env override — absent/empty env keeps the current value.
     * '0' is honoured (disables a window) unlike the loose `?:` cast style.
     */
    private function envInt(string $name, int $current): int
    {
        $value = getenv($name);

        return ($value === false || trim($value) === '') ? $current : (int) $value;
    }

    /**
     * Optional int env override for nullable knobs ($rateLimitPerSecond).
     * absent/empty keeps the current value; '0' collapses to null (disabled).
     */
    private function envOptInt(string $name, ?int $current): ?int
    {
        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            return $current;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * Strict bool env override — absent/empty env keeps the current value.
     * Accepts 1/true/yes/on (case-insensitive) as true, everything else false.
     */
    private function envBool(string $name, bool $current): bool
    {
        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            return $current;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
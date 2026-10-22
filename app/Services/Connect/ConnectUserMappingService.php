<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Models\Connect\ConnectExternalUserModel;
use App\Models\Connect\ConnectUserMappingModel;
use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Humming Bird Connect — Phase 3 external user mapping.
 *
 * Maps an external platform user (e.g. Remark's "remark_user_123") to an
 * existing Humming Bird user. Tenant + environment always come from the
 * authenticated ConnectTenantContext — never from the request body.
 *
 * Invariants enforced here AND at the database (see the Phase 3 migration):
 *   - one (tenant, environment, external_user_id) mapping
 *   - one (tenant, environment, humming_bird_user_id) mapping
 * A mapping is immutable until explicitly unmapped; unmapped mappings can be
 * re-established only for the SAME Humming Bird user.
 */
class ConnectUserMappingService
{
    private ConnectUserMappingModel $mappings;
    private ConnectExternalUserModel $externalUsers;
    private UserModel $hummingBirdUsers;
    private ConnectApiAuditLogModel $audit;

    public function __construct(
        ?ConnectUserMappingModel $mappings = null,
        ?ConnectExternalUserModel $externalUsers = null,
        ?UserModel $hummingBirdUsers = null,
        ?ConnectApiAuditLogModel $audit = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->mappings         = $mappings ?? new ConnectUserMappingModel($db);
        $this->externalUsers    = $externalUsers ?? new ConnectExternalUserModel($db);
        $this->hummingBirdUsers = $hummingBirdUsers ?? new UserModel($db);
        $this->audit            = $audit ?? new ConnectApiAuditLogModel($db);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Create (or idempotently return) the external → Humming Bird mapping.
     *
     * @return array{status:int, data:array}
     *
     * @throws ConnectMappingException
     */
    public function map(ConnectTenantContext $context, string $externalUserId, string $hummingBirdUserId): array
    {
        $externalUserId = $this->validateExternalUserId($externalUserId);
        $hbUserId       = $this->validateHummingBirdUserId($hummingBirdUserId);

        $tenantId = $context->tenantId();
        $env      = $context->environment();

        // Fast-path checks before any write.
        $existing = $this->findMapping($tenantId, $env, $externalUserId);

        if ($existing !== null) {
            return $this->resolveExisting($context, $externalUserId, $hbUserId, $existing);
        }

        $reverse = $this->findMappingForHbUser($tenantId, $env, $hbUserId);
        if ($reverse !== null && $this->isMappedStatus($reverse['mapping_status'])
            && (string) $reverse['external_user_id'] !== $externalUserId) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_USER_ALREADY, $externalUserId, (string) $hbUserId, 409, 'denied', ConnectConfig::ERROR_USER_ALREADY_MAPPED);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_USER_ALREADY_MAPPED,
                'Humming Bird user is already mapped to another external user.',
                409,
            );
        }

        // Create public user (if missing) + mapping atomically.
        $db        = $this->db();
        $now       = date('Y-m-d H:i:s');
        $created   = true;

        try {
            $db->transBegin();

            $this->ensureExternalUser($tenantId, $env, $externalUserId, $now);

            $inserted = $this->mappings->insert([
                'tenant_id'            => $tenantId,
                'environment'          => $env,
                'external_user_id'     => $externalUserId,
                'humming_bird_user_id' => (int) $hbUserId,
                'mapping_status'       => ConnectConfig::MAPPING_STATUS_MAPPED,
                'mapped_at'            => $now,
            ]);

            // Model::insert() returns false instead of throwing when the
            // driver reports a constraint failure quietly (this is what CI4
            // does under `testing`). Ignoring that return value made this
            // endpoint answer 201 for a mapping that was never written.
            if ($inserted === false) {
                $error = $this->mappings->db->error();
                $db->transRollback();

                log_message('error', 'Connect mapping insert failed silently; the driver reported a constraint failure instead of throwing.', [
                    'tenant_id'        => $tenantId,
                    'environment'      => $env,
                    'external_user_id' => $externalUserId,
                    'humming_bird_user_id' => (int) $hbUserId,
                    'driver_code'      => $error['code'] ?? null,
                    'driver_message'   => $error['message'] ?? null,
                    'request_id'       => $context->requestId(),
                ]);

                // A concurrent request may have won the race; let the normal
                // conflict resolution decide the outcome.
                $winner = $this->findMapping($tenantId, $env, $externalUserId);
                if ($winner !== null) {
                    return $this->resolveExisting($context, $externalUserId, $hbUserId, $winner);
                }

                $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $hbUserId, 409, 'denied', ConnectConfig::ERROR_MAPPING_CONFLICT);

                throw new ConnectMappingException(
                    ConnectConfig::ERROR_MAPPING_CONFLICT,
                    'Mapping could not be created due to a concurrent request.',
                    409,
                );
            }

            $db->transCommit();
        } catch (DatabaseException $e) {
            $db->transRollback();

            // A concurrent identical/unrelated request won the insert — the
            // unique constraints decide the correct outcome, never an error.
            $winner = $this->findMapping($tenantId, $env, $externalUserId);
            if ($winner !== null) {
                return $this->resolveExisting($context, $externalUserId, $hbUserId, $winner);
            }

            $hbWinner = $this->findMappingForHbUser($tenantId, $env, $hbUserId);
            if ($hbWinner !== null && $this->isMappedStatus($hbWinner['mapping_status'])) {
                $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_USER_ALREADY, $externalUserId, (string) $hbUserId, 409, 'denied', ConnectConfig::ERROR_USER_ALREADY_MAPPED);

                throw new ConnectMappingException(
                    ConnectConfig::ERROR_USER_ALREADY_MAPPED,
                    'Humming Bird user is already mapped to another external user.',
                    409,
                );
            }

            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $hbUserId, 409, 'denied', ConnectConfig::ERROR_MAPPING_CONFLICT);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_MAPPING_CONFLICT,
                'Mapping could not be created due to a concurrent request.',
                409,
            );
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->transRollback();
            }
            log_message('error', 'Connect mapping create failed: ' . $e->getMessage());
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $hbUserId, 500, 'error', ConnectConfig::ERROR_DATABASE_ERROR);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_DATABASE_ERROR,
                'An internal error occurred.',
                500,
            );
        }

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CREATED, $externalUserId, (string) $hbUserId, 201, 'success', null);
        log_message('info', 'Connect user mapping created', [
            'tenant_id' => $tenantId,
            'environment' => $env,
            'external_user_id' => $externalUserId,
            'humming_bird_user_id' => $hbUserId,
            'request_id' => $context->requestId(),
        ]);

        return [
            'status' => 201,
            'data'   => $this->safeData($externalUserId, (string) $hbUserId, ConnectConfig::MAPPING_STATUS_MAPPED, $env),
        ];
    }

    /**
     * Look up an active mapping for the authenticated tenant/environment.
     *
     * @throws ConnectMappingException
     */
    public function lookup(ConnectTenantContext $context, string $externalUserId): array
    {
        $externalUserId = $this->validateExternalUserId($externalUserId);

        $row = $this->findMapping($context->tenantId(), $context->environment(), $externalUserId);

        if ($row === null || ! $this->isMappedStatus($row['mapping_status'])) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_LOOKUP, $externalUserId, null, 404, 'error', ConnectConfig::ERROR_MAPPING_NOT_FOUND);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_MAPPING_NOT_FOUND,
                'Mapping was not found.',
                404,
            );
        }

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_LOOKUP, $externalUserId, (string) $row['humming_bird_user_id'], 200, 'success', null);

        return [
            'status' => 200,
            'data'   => $this->safeData($externalUserId, (string) $row['humming_bird_user_id'], ConnectConfig::MAPPING_STATUS_MAPPED, $context->environment()),
        ];
    }

    /**
     * Soft-unmap an external user. Historical mapping/audit rows are kept.
     *
     * @throws ConnectMappingException
     */
    public function unmap(ConnectTenantContext $context, string $externalUserId): array
    {
        $externalUserId = $this->validateExternalUserId($externalUserId);

        $row = $this->findMapping($context->tenantId(), $context->environment(), $externalUserId);

        if ($row === null) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_LOOKUP, $externalUserId, null, 404, 'error', ConnectConfig::ERROR_MAPPING_NOT_FOUND);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_MAPPING_NOT_FOUND,
                'Mapping was not found.',
                404,
            );
        }

        if (! $this->isMappedStatus($row['mapping_status'])) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_ALREADY_REMOVED, $externalUserId, (string) $row['humming_bird_user_id'], 409, 'denied', ConnectConfig::ERROR_MAPPING_ALREADY_REMOVED);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_MAPPING_ALREADY_REMOVED,
                'Mapping is already removed.',
                409,
            );
        }

        $db  = $this->db();
        $now = date('Y-m-d H:i:s');

        try {
            $db->transBegin();
            $this->mappings
                ->where('id', (int) $row['id'])
                ->where('tenant_id', $context->tenantId())
                ->set('mapping_status', ConnectConfig::MAPPING_STATUS_UNMAPPED)
                ->set('unmapped_at', $now)
                ->update();
            $db->transCommit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->transRollback();
            }
            log_message('error', 'Connect mapping unmap failed: ' . $e->getMessage());
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $row['humming_bird_user_id'], 500, 'error', ConnectConfig::ERROR_DATABASE_ERROR);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_DATABASE_ERROR,
                'An internal error occurred.',
                500,
            );
        }

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_REMOVED, $externalUserId, (string) $row['humming_bird_user_id'], 200, 'success', null);
        log_message('info', 'Connect mapping removed', [
            'tenant_id'   => $context->tenantId(),
            'environment' => $context->environment(),
            'external_user_id' => $externalUserId,
            'humming_bird_user_id' => (string) $row['humming_bird_user_id'],
            'request_id'  => $context->requestId(),
        ]);

        return [
            'status' => 200,
            'data'   => $this->safeData($externalUserId, (string) $row['humming_bird_user_id'], ConnectConfig::MAPPING_STATUS_UNMAPPED, $context->environment()),
        ];
    }

    /**
     * Phase 4 hook: resolve the internal Humming Bird user id for an external
     * user under the authenticated tenant/environment. No throw on miss.
     */
    public function resolveInternalUserId(ConnectTenantContext $context, string $externalUserId): ?int
    {
        $row = $this->findMapping($context->tenantId(), $context->environment(), trim($externalUserId));

        if ($row === null || ! $this->isMappedStatus($row['mapping_status'])) {
            return null;
        }

        return (int) $row['humming_bird_user_id'];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function validateExternalUserId(string $externalUserId): string
    {
        $externalUserId = trim($externalUserId);

        if ($externalUserId === '') {
            throw new ConnectMappingException(
                ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID,
                'External user id is required.',
                400,
            );
        }

        if (strlen($externalUserId) > ConnectConfig::EXTERNAL_USER_ID_MAX_LEN
            || preg_match('/[\x00-\x20\x7F]/', $externalUserId)
            || ! preg_match('/^[\x21-\x7E]+$/', $externalUserId)) {
            throw new ConnectMappingException(
                ConnectConfig::ERROR_INVALID_EXTERNAL_USER_ID,
                'External user id is invalid.',
                400,
            );
        }

        return $externalUserId;
    }

    /**
     * @return int the validated, existing Humming Bird user id
     *
     * @throws ConnectMappingException
     */
    private function validateHummingBirdUserId(string $hummingBirdUserId): int
    {
        $hummingBirdUserId = trim($hummingBirdUserId);

        if ($hummingBirdUserId === '') {
            throw new ConnectMappingException(
                ConnectConfig::ERROR_MISSING_HUMMING_BIRD_USER_ID,
                'Humming Bird user id is required.',
                400,
            );
        }

        if (! ctype_digit($hummingBirdUserId) || strlen($hummingBirdUserId) > 11) {
            throw new ConnectMappingException(
                ConnectConfig::ERROR_HUMMING_BIRD_USER_NOT_FOUND,
                'Humming Bird user was not found.',
                404,
            );
        }

        $user = $this->hummingBirdUsers->find((int) $hummingBirdUserId);

        if ($user === null || $user === []) {
            throw new ConnectMappingException(
                ConnectConfig::ERROR_HUMMING_BIRD_USER_NOT_FOUND,
                'Humming Bird user was not found.',
                404,
            );
        }

        if ((int) ($user['u_is_deleted'] ?? 0) === 1) {
            throw new ConnectMappingException(
                ConnectConfig::ERROR_HUMMING_BIRD_USER_INACTIVE,
                'Humming Bird user is not available for mapping.',
                422,
            );
        }

        return (int) ($user['user_id'] ?? $hummingBirdUserId);
    }

    private function resolveExisting(ConnectTenantContext $context, string $externalUserId, int $hbUserId, array $existing): array
    {
        $tenantId = $context->tenantId();
        $env      = $context->environment();

        $sameUser = (string) $existing['humming_bird_user_id'] === (string) $hbUserId;

        if ($this->isMappedStatus($existing['mapping_status'])) {
            if ($sameUser) {
                $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_ALREADY_EXISTS, $externalUserId, (string) $hbUserId, 200, 'success', null);

                return [
                    'status' => 200,
                    'data'   => $this->safeData($externalUserId, (string) $hbUserId, ConnectConfig::MAPPING_STATUS_MAPPED, $env),
                ];
            }

            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $hbUserId, 409, 'denied', ConnectConfig::ERROR_MAPPING_CONFLICT);

            throw new ConnectMappingException(
                ConnectConfig::ERROR_MAPPING_CONFLICT,
                'External user is already mapped to another Humming Bird user.',
                409,
            );
        }

        // Legacy unmapped row: re-establish ONLY for the same Humming Bird user.
        if ($sameUser) {
            $now = date('Y-m-d H:i:s');
            try {
                $this->db()->transBegin();
                $this->mappings
                    ->where('id', (int) $existing['id'])
                    ->where('tenant_id', $tenantId)
                    ->set('mapping_status', ConnectConfig::MAPPING_STATUS_MAPPED)
                    ->set('mapped_at', $now)
                    ->set('unmapped_at', null)
                    ->update();
                $this->db()->transCommit();
            } catch (Throwable $e) {
                if ($this->db()->inTransaction()) {
                    $this->db()->transRollback();
                }
                $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $hbUserId, 500, 'error', ConnectConfig::ERROR_DATABASE_ERROR);

                throw new ConnectMappingException(
                    ConnectConfig::ERROR_DATABASE_ERROR,
                    'An internal error occurred.',
                    500,
                );
            }

            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_ALREADY_EXISTS, $externalUserId, (string) $hbUserId, 200, 'success', null);

            return [
                'status' => 200,
                'data'   => $this->safeData($externalUserId, (string) $hbUserId, ConnectConfig::MAPPING_STATUS_MAPPED, $env),
            ];
        }

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MAPPING_CONFLICT, $externalUserId, (string) $hbUserId, 409, 'denied', ConnectConfig::ERROR_MAPPING_CONFLICT);

        throw new ConnectMappingException(
            ConnectConfig::ERROR_MAPPING_CONFLICT,
            'External user is already mapped to another Humming Bird user.',
            409,
        );
    }

    private function ensureExternalUser(int $tenantId, string $env, string $externalUserId, string $now): void
    {
        $existing = $this->externalUsers
            ->where('tenant_id', $tenantId)
            ->where('environment', $env)
            ->where('external_user_id', $externalUserId)
            ->first();

        if ($existing !== null && $existing !== []) {
            return;
        }

        try {
            $this->externalUsers->insert([
                'tenant_id'        => $tenantId,
                'environment'      => $env,
                'external_user_id' => $externalUserId,
                'status'           => ConnectConfig::EXTERNAL_USER_STATUS_ACTIVE,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        } catch (DatabaseException $e) {
            // A concurrent request created the same external user first —
            // that's fine, this only creates the record when it is missing.
        }
    }

    private function findMapping(int $tenantId, string $env, string $externalUserId): ?array
    {
        $row = $this->mappings
            ->where('tenant_id', $tenantId)
            ->where('environment', $env)
            ->where('external_user_id', $externalUserId)
            ->first();

        return $row === [] ? null : $row;
    }

    private function findMappingForHbUser(int $tenantId, string $env, int $hbUserId): ?array
    {
        $row = $this->mappings
            ->where('tenant_id', $tenantId)
            ->where('environment', $env)
            ->where('humming_bird_user_id', $hbUserId)
            ->first();

        return $row === [] ? null : $row;
    }

    private function isMappedStatus(string $status): bool
    {
        return $status === ConnectConfig::MAPPING_STATUS_MAPPED
            || $status === ConnectConfig::MAPPING_STATUS_ACTIVE;
    }

    private function safeData(string $externalUserId, string $hbUserId, string $status, string $env): array
    {
        return [
            'external_user_id'     => $externalUserId,
            'humming_bird_user_id' => $hbUserId,
            'status'               => $status,
            'environment'          => $env,
        ];
    }

    /**
     * Secret-free domain audit row. Never logs signatures, secrets, tokens,
     * OTPs or full bodies.
     */
    private function writeAudit(
        ConnectTenantContext $context,
        string $action,
        ?string $externalUserId,
        ?string $hbUserId,
        int $responseCode,
        string $status,
        ?string $errorCode,
    ): void {
        try {
            $request = Services::request();
            $method  = $request instanceof IncomingRequest ? strtoupper($request->getMethod(true)) : null;
            $path    = $request instanceof IncomingRequest ? '/' . ltrim($request->getPath(), '/') : null;
            $ip      = $request instanceof IncomingRequest ? substr((string) $request->getIPAddress(), 0, 45) : null;
            $ua      = $request instanceof IncomingRequest ? substr(trim((string) $request->getUserAgent()), 0, 255) : null;

            $this->audit->insert([
                'tenant_id'            => $context->tenantId(),
                'credential_id'        => $context->credentialDbId(),
                'request_id'           => $context->requestId(),
                'method'               => $method,
                'endpoint'             => $path,
                'ip_address'           => $ip,
                'user_agent'           => $ua,
                'request_hash'         => hash('sha256', ($method ?? '') . '|' . ($path ?? '') . '|' . $context->requestId()),
                'response_code'        => $responseCode,
                'status'               => $status,
                'error_code'           => $errorCode,
                'action'               => $action,
                'external_user_id'     => $externalUserId,
                'humming_bird_user_id' => $hbUserId,
            ]);
        } catch (Throwable $e) {
            // An audit failure must never break the API response.
            log_message('error', 'Connect mapping audit write failed: ' . $e->getMessage());
        }
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}
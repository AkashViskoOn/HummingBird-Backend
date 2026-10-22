<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectApiAuditLogModel;
use App\Models\Connect\ConnectExternalMessageModel;
use App\Models\Connect\ConnectMessageDeliveryModel;
use App\Models\Connect\ConnectMessageOutboxModel;
use App\Models\Connect\ConnectServiceIdentityModel;
use App\Models\MessageModel;
use App\Models\RoomModel;
use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Connect as ConnectConfig;
use Config\Database;
use Config\Services;
use DateTime;
use Throwable;

/**
 * Humming Bird Connect — inbound message acceptance + delivery primitives.
 *
 * Phase 4 created the Humming Bird message SYNCHRONOUSLY inside the HTTP
 * request. Phase 5 decouples that: the API only VALIDATES and DURABLY PERSISTS
 * the accepted operation (tracking record + outbox + delivery row), commits,
 * and returns HTTP 202. The actual Humming Bird message creation, Socket.IO
 * and FCM delivery now happen in the background worker
 * (App\Services\Connect\ConnectMessageProcessor), which reuses the low-level
 * delivery primitives exposed on this class.
 *
 * Tenant + environment come ONLY from the authenticated ConnectTenantContext —
 * never from the request body. The outbox is tenant-scoped.
 *
 * This service remains the source of truth for the Connect integration
 * tracking tables; the existing Humming Bird messages table stays the source
 * of truth for actual chat messages.
 */
class ConnectMessageService
{
    private ConnectExternalMessageModel $messages;
    private ConnectMessageOutboxModel   $outbox;
    private ConnectMessageDeliveryModel $deliveries;
    private ConnectUserMappingService   $mapping;
    private ConnectServiceIdentityModel $serviceIdentities;
    private UserModel                   $users;
    private RoomModel                   $rooms;
    private MessageModel                $hbMessages;
    private ConnectApiAuditLogModel     $audit;
    private ConnectMessageCipher        $cipher;
    private ConnectQuotaService         $quota;

    public function __construct(
        ?ConnectExternalMessageModel $messages = null,
        ?ConnectMessageOutboxModel $outbox = null,
        ?ConnectMessageDeliveryModel $deliveries = null,
        ?ConnectUserMappingService $mapping = null,
        ?ConnectServiceIdentityModel $serviceIdentities = null,
        ?UserModel $users = null,
        ?RoomModel $rooms = null,
        ?MessageModel $hbMessages = null,
        ?ConnectApiAuditLogModel $audit = null,
        ?ConnectMessageCipher $cipher = null,
        ?ConnectQuotaService $quota = null,
    ) {
        $db = Database::connect(config(Database::class)->defaultGroup);

        $this->messages          = $messages ?? new ConnectExternalMessageModel($db);
        $this->outbox            = $outbox ?? new ConnectMessageOutboxModel($db);
        $this->deliveries        = $deliveries ?? new ConnectMessageDeliveryModel($db);
        $this->mapping           = $mapping ?? new ConnectUserMappingService();
        $this->serviceIdentities = $serviceIdentities ?? new ConnectServiceIdentityModel($db);
        $this->users             = $users ?? new UserModel($db);
        $this->rooms             = $rooms ?? new RoomModel($db);
        $this->hbMessages        = $hbMessages ?? new MessageModel($db);
        $this->audit             = $audit ?? new ConnectApiAuditLogModel($db);
        $this->cipher            = $cipher ?? new ConnectMessageCipher();
        $this->quota             = $quota ?? new ConnectQuotaService();
    }

    // ------------------------------------------------------------------
    // Acceptance (async — the API surface)
    // ------------------------------------------------------------------

    /**
     * Validate and DURABLY enqueue an external message. Returns HTTP 202 with
     * status 'queued'. The Humming Bird message is created later by the worker.
     *
     * @return array{status: int, data: array}
     *
     * @throws ConnectMessageException
     */
    public function queue(ConnectTenantContext $context, array $body): array
    {
        $tenantId = $context->tenantId();
        $env      = $context->environment();

        // ---------------------------------------------------------------
        // 1. Validate body
        // ---------------------------------------------------------------
        $externalUserId    = $this->requireString($body, 'external_user_id', ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID, 'External user id is required.', 400);
        $externalUserId    = $this->validateExternalUserId($externalUserId);
        $externalMessageId = isset($body['external_message_id']) ? trim((string) $body['external_message_id']) : '';
        $plaintext         = $this->requireString($body, 'body', ConnectConfig::ERROR_MISSING_MESSAGE_BODY, 'Message body is required.', 400);
        $title             = isset($body['title']) ? trim((string) $body['title']) : null;
        $messageType       = 'text';
        $metadata          = is_array($body['metadata'] ?? null) ? $body['metadata'] : null;

        if ($plaintext === '') {
            throw new ConnectMessageException(ConnectConfig::ERROR_INVALID_MESSAGE_BODY, 'Message body is required.', 400);
        }
        if (strlen($plaintext) > ConnectConfig::MESSAGE_BODY_MAX_LEN) {
            throw new ConnectMessageException(ConnectConfig::ERROR_MESSAGE_BODY_TOO_LONG, 'Message body exceeds the maximum length.', 413);
        }
        if ($externalMessageId !== '' && strlen($externalMessageId) > ConnectConfig::EXTERNAL_MESSAGE_ID_MAX_LEN) {
            throw new ConnectMessageException(ConnectConfig::ERROR_INVALID_EXTERNAL_MESSAGE_ID, 'External message id is too long.', 400);
        }
        if ($title !== null && strlen($title) > ConnectConfig::MESSAGE_TITLE_MAX_LEN) {
            $title = substr($title, 0, ConnectConfig::MESSAGE_TITLE_MAX_LEN);
        }

        // ---------------------------------------------------------------
        // 1b. Idempotency fast-path — a previously accepted external_message_id
        //     (same tenant + environment) returns the SAME logical operation
        //     without creating a new tracking/outbox/message.
        // ---------------------------------------------------------------
        if ($externalMessageId !== '') {
            $existing = $this->messages
                ->where('tenant_id', $tenantId)
                ->where('environment', $env)
                ->where('external_message_id', $externalMessageId)
                ->first();

            if ($existing !== null && $existing !== []) {
                $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_DUPLICATE, null, (string) ($existing['humming_bird_user_id'] ?? ''), 200, 'success', null);
                $this->quota->stampHeadersFor($context);

                return $this->duplicateResponse($context, $existing, $externalMessageId);
            }
        }

        // ---------------------------------------------------------------
        // 1c. Quota pre-check (Phase 8) — a read-only peek AFTER validation and
        //     idempotency, BEFORE the expensive mapping/room/identity work. A
        //     rejected or idempotent request never spends budget. The atomic
        //     consume happens inside the queue transaction below.
        // ---------------------------------------------------------------
        try {
            $this->quota->ensureAvailable($context);
        } catch (ConnectMessageException $e) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, null, $e->httpStatus(), 'denied', $e->errorCode());
            throw $e;
        }

        // ---------------------------------------------------------------
        // 2. Resolve external → HB user
        // ---------------------------------------------------------------
        $hbUserId = $this->mapping->resolveInternalUserId($context, $externalUserId);

        if ($hbUserId === null) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, null, 404, 'denied', ConnectConfig::ERROR_MAPPING_NOT_FOUND);

            throw new ConnectMessageException(
                ConnectConfig::ERROR_MAPPING_NOT_FOUND,
                'External user is not mapped to a Humming Bird user.',
                404,
            );
        }

        $hbUser = $this->users->find($hbUserId);

        if ($hbUser === null || $hbUser === [] || (int) ($hbUser['u_is_deleted'] ?? 0) === 1) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, (string) $hbUserId, 422, 'denied', ConnectConfig::ERROR_HUMMING_BIRD_USER_INACTIVE);

            throw new ConnectMessageException(
                ConnectConfig::ERROR_HUMMING_BIRD_USER_INACTIVE,
                'The mapped Humming Bird user is not available.',
                422,
            );
        }

        // ---------------------------------------------------------------
        // 3. Resolve service identity
        // ---------------------------------------------------------------
        $identity = $this->serviceIdentities
            ->where('tenant_id', $tenantId)
            ->where('status', ConnectConfig::IDENTITY_STATUS_ACTIVE)
            ->orderBy('id', 'ASC')
            ->first();

        if ($identity === null || $identity === []) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, (string) $hbUserId, 404, 'denied', ConnectConfig::ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND);

            throw new ConnectMessageException(
                ConnectConfig::ERROR_CONNECT_SERVICE_IDENTITY_NOT_FOUND,
                'No active service identity found for this tenant.',
                404,
            );
        }

        // ---------------------------------------------------------------
        // 4. Ensure bot user + resolve room (idempotent building blocks)
        // ---------------------------------------------------------------
        $botUser = $this->ensureBotUser($identity, $tenantId);
        $room    = $this->findOrCreateRoom($botUser, $hbUser, $identity);

        // ---------------------------------------------------------------
        // 5. Encrypt body for Flutter rendering (done once here so the worker
        //    never re-encrypts / never needs the plaintext again).
        // ---------------------------------------------------------------
        $encryptedBody = $this->cipher->encryptMessage($plaintext);

        $db = $this->db();
        $now = new DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');

        $trackingId = false;
        $opUuid     = $this->uuid();

        try {
            $db->transBegin();

            // ---------------------------------------------------------
            // 5b. Quota consume (Phase 8) — atomic claim INSIDE the queue
            //     transaction. If any later insert fails and the tx rolls
            //     back, the counter UPDATE rolls back with it. A quota
            //     exhaustion throws 429 here and the send is still rolled
            //     back (no tracking/outbox left behind).
            // ---------------------------------------------------------
            $this->quota->consumeMessage($context);

            // -----------------------------------------------------------
            // 6. Insert tracking record (connect_external_messages)
            // -----------------------------------------------------------
            $trackingId = $this->messages->insert([
                'tenant_id'            => $tenantId,
                'environment'          => $env,
                'service_identity_id'  => (int) $identity['id'],
                'external_user_id'     => $externalUserId,
                'humming_bird_user_id' => (int) $hbUserId,
                'uuid'                 => $opUuid,
                'message_uuid'         => isset($body['message_uuid']) ? (string) $body['message_uuid'] : null,
                'external_event_id'    => isset($body['external_event_id']) ? (string) $body['external_event_id'] : null,
                'external_message_id'  => $externalMessageId ?: null,
                'message_type'         => $messageType,
                'title'                => $title,
                'body'                 => $encryptedBody,
                'payload'              => json_encode(array_merge([
                    'plaintext' => $plaintext,
                ], $metadata ?: [])),
                'status'               => ConnectConfig::MESSAGE_STATUS_QUEUED,
                'room_id'              => (string) $room['r_id'],
                'message_id'           => null,
                'error_code'           => null,
                'error_message'        => null,
                'request_id'           => $context->requestId(),
            ], true);

            // -----------------------------------------------------------
            // 7. Outbox record (durable processing claim)
            // -----------------------------------------------------------
            $this->outbox->insert([
                'uuid'                        => $opUuid,
                'tenant_id'                   => $tenantId,
                'environment'                 => $env,
                'connect_external_message_id' => (int) $trackingId,
                'external_message_id'         => $externalMessageId ?: null,
                'status'                      => ConnectConfig::OUTBOX_STATUS_PENDING,
                'attempts'                    => 0,
                'available_at'                => $nowStr,
                'locked_at'                   => null,
                'locked_by'                   => null,
                'processed_at'                => null,
                'last_error_code'             => null,
                'last_error_message'          => null,
            ]);

            // -----------------------------------------------------------
            // 8. Delivery tracking (queued)
            // -----------------------------------------------------------
            $this->upsertDelivery(
                (int) $trackingId,
                $tenantId,
                (int) $hbUserId,
                ConnectConfig::DELIVERY_STATUS_QUEUED,
                0,
                null,
            );

            $db->transCommit();
        } catch (DatabaseException $e) {
            if ($db->inTransaction()) {
                $db->transRollback();
            }

            // A concurrent request with the SAME external_message_id won the
            // unique insert. Re-read and return the winner idempotently,
            // never a 500.
            if ($externalMessageId !== '') {
                $winner = $this->messages
                    ->where('tenant_id', $tenantId)
                    ->where('environment', $env)
                    ->where('external_message_id', $externalMessageId)
                    ->first();

                if ($winner !== null && $winner !== []) {
                    $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_DUPLICATE, (string) ($winner['external_user_id'] ?? ''), (string) ($winner['humming_bird_user_id'] ?? ''), 200, 'success', null);
                    $this->quota->stampHeadersFor($context);

                    return $this->duplicateResponse($context, $winner, $externalMessageId);
                }
            }

            log_message('error', 'Connect message queue DB error: ' . $e->getMessage());
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, (string) $hbUserId, 500, 'error', ConnectConfig::ERROR_DATABASE_ERROR);

            throw new ConnectMessageException(
                ConnectConfig::ERROR_DATABASE_ERROR,
                'An internal error occurred.',
                500,
            );
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->transRollback();
            }

            // Phase 8 — quota exhaustion must surface as its 429,
            // not be flattened into a generic 500 by this catch-all.
            if ($e instanceof ConnectMessageException) {
                $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, (string) $hbUserId, $e->httpStatus(), 'denied', $e->errorCode());
                throw $e;
            }

            log_message('error', 'Connect message queue failed: ' . $e->getMessage());
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_REJECTED, $externalUserId, (string) $hbUserId, 500, 'error', ConnectConfig::ERROR_DATABASE_ERROR);

            throw new ConnectMessageException(
                ConnectConfig::ERROR_DATABASE_ERROR,
                'An internal error occurred.',
                500,
            );
        }

        // ---------------------------------------------------------------
        // 9. Audit — durably queued (202).
        // ---------------------------------------------------------------
        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_QUEUED, $externalUserId, (string) $hbUserId, 202, 'success', null);

        // ---------------------------------------------------------------
        // 10. Status ledger + webhooks — accepted → queued (best-effort; a
        //     webhook dispatch failure never fails the queued message).
        // ---------------------------------------------------------------
        $this->recordStatusesForQueued((int) $trackingId, $tenantId, $env, $externalMessageId, $hbUserId);

        return [
            'status' => 202,
            'data'   => [
                'tenant_id'               => (string) $tenantId,
                'environment'             => $env,
                'credential_id'           => $context->credentialId(),
                'request_id'              => $context->requestId(),
                'external_user_id'        => $externalUserId,
                'external_message_id'     => $externalMessageId ?: null,
                'humming_bird_user_id'    => (string) $hbUserId,
                'humming_bird_message_id' => null, // worker has not processed it yet
                'status'                  => 'queued',
            ],
        ];
    }

    /**
     * Record the accepted → queued lifecycle after a successful queue.
     * Best-effort: ledger writing and webhook dispatch are isolated from the
     * accept path (spec 25 — dispatch failures never fail the message).
     */
    private function recordStatusesForQueued(int $trackingId, int $tenantId, string $env, string $externalMessageId, int $hbUserId): void
    {
        try {
            $tracking = $this->messages->find($trackingId);
            if ($tracking === null || $tracking === []) {
                return;
            }

            $statusService = new ConnectMessageStatusService();
            $statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_ACCEPTED, 'message accepted by Connect API');
            $statusService->recordFromTracking($tracking, ConnectConfig::MESSAGE_STATUS_QUEUED, 'message durably queued for processing');
        } catch (Throwable $e) {
            log_message('error', 'Connect status recording failed after queue: ' . $e->getMessage());
        }
    }

    /**
     * Look up an external message by its platform-supplied id.
     *
     * @return array{status: int, data: array}
     *
     * @throws ConnectMessageException
     */
    public function lookup(ConnectTenantContext $context, string $externalMessageId): array
    {
        $externalMessageId = trim($externalMessageId);

        if ($externalMessageId === '') {
            throw new ConnectMessageException(
                ConnectConfig::ERROR_INVALID_EXTERNAL_MESSAGE_ID,
                'External message id is required.',
                400,
            );
        }

        $row = $this->messages
            ->where('tenant_id', $context->tenantId())
            ->where('environment', $context->environment())
            ->where('external_message_id', $externalMessageId)
            ->first();

        if ($row === null || $row === []) {
            $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_LOOKUP, null, null, 404, 'error', ConnectConfig::ERROR_MESSAGE_NOT_FOUND);

            throw new ConnectMessageException(
                ConnectConfig::ERROR_MESSAGE_NOT_FOUND,
                'Message not found.',
                404,
            );
        }

        $this->writeAudit($context, ConnectConfig::AUDIT_ACTION_MESSAGE_LOOKUP, (string) ($row['external_user_id'] ?? ''), (string) ($row['humming_bird_user_id'] ?? ''), 200, 'success', null);

        return [
            'status' => 200,
            'data'   => [
                'tenant_id'               => (string) $context->tenantId(),
                'environment'             => $context->environment(),
                'external_message_id'     => $externalMessageId,
                'humming_bird_user_id'    => (string) ($row['humming_bird_user_id'] ?? ''),
                'humming_bird_message_id' => $this->normalizeNullString($row['message_id'] ?? null),
                'status'                  => $this->effectiveStatus($row),
                'room_id'                 => (string) ($row['room_id'] ?? ''),
                'created_at'              => $row['created_at'] ?? '',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Worker delivery primitives (reused by ConnectMessageProcessor)
    // ------------------------------------------------------------------

    /**
     * Create the actual Humming Bird message row (the EXISTING message
     * creation path — direct insert into `messages` via MessageModel, mirroring
     * the consumer MessageController::sendMessage row shape). Returns the row.
     */
    public function createHbMessage(array $botUser, array $hbUser, array $room, string $encryptedBody): array
    {
        $db = $this->db();
        $now = new DateTime();

        $messageId = $this->hbMessages->insert([
            'm_unique_id'    => rand(10000, 999999),
            'm_room_id'      => $room['r_id'],
            'm_sender_id'    => $botUser['user_id'],
            'm_receiver_id'  => (string) $hbUser['user_id'],
            'm_message'      => $encryptedBody,
            'm_message_type' => '0',
            'm_read_status'  => '1',
            'm_media'        => '',
            'm_is_scheduled' => '0',
            'm_scheduled_at' => '0',
            'm_interval'     => '0',
            'm_count'        => '0',
            'm_epoch_time'   => (string) time(),
            'm_status'       => '',
        ], true);

        return $this->hbMessages->find($messageId) ?: [];
    }

    /**
     * FCM notification (fire-and-forget). Reuses the existing NotificationController.
     */
    public function sendNotification(array $hbUser, array $botUser, string $plaintext, array $room, string $messageId, string $targetUserId): void
    {
        try {
            $nc = new \App\Controllers\NotificationController();
            $sent = [];

            foreach (['fcm_token', 'web_fcm_token'] as $tokenField) {
                $token = $hbUser[$tokenField] ?? '';
                if (empty($token)) {
                    continue;
                }

                $sent[] = $tokenField;
                $nc->sendNotification(
                    $token,
                    $botUser['user_first_name'] ?? 'Connect',
                    $plaintext,
                    (string) $botUser['user_id'],
                    (string) $room['r_id'],
                    $botUser['user_mobile_num'] ?? '',
                    '0',
                    $botUser['user_image'] ?? '',
                    '',
                    $room['r_name'] ?? '',
                );
            }

            log_message('info', sprintf(
                'Connect FCM trigger: user=%s message=%s tokens=%s',
                $targetUserId,
                $messageId,
                implode(',', $sent) ?: 'none'
            ));
        } catch (Throwable $e) {
            log_message('error', 'Connect FCM notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Socket broadcast (fire-and-forget) via the existing socket server's
     * HTTP /broadcast endpoint. Socket failure never fails the message — the
     * DB row is already persisted and the existing history sync will deliver it.
     */
    public function broadcastMessage(?array $messageRow, array $targetUserIds): void
    {
        if ($messageRow === null || $messageRow === [] || empty($targetUserIds)) {
            return;
        }

        try {
            $broadcastUrl = getenv('SOCKET_BROADCAST_URL') ?: 'http://localhost:4000/broadcast';

            $messageReceiveOk = $this->postToSocket($broadcastUrl, [
                'event'   => 'message-receive',
                'data'    => $messageRow,
                'userIds' => $targetUserIds,
            ]);

            $newMessageOk = $this->postToSocket($broadcastUrl, [
                'event'   => 'new-message',
                'data'    => $messageRow['m_room_id'] ?? '',
                'userIds' => $targetUserIds,
            ]);

            log_message('info', sprintf(
                'Connect broadcast: message hb_msg=%s room=%s users=%s message-receive=%s new-message=%s',
                (string) ($messageRow['m_id'] ?? ''),
                (string) ($messageRow['m_room_id'] ?? ''),
                implode(',', array_map('strval', $targetUserIds)),
                $messageReceiveOk ? 'ok' : 'failed',
                $newMessageOk ? 'ok' : 'failed'
            ));
        } catch (Throwable $e) {
            log_message('error', 'Connect message broadcast failed: ' . $e->getMessage());
        }
    }

    /**
     * Worker audit write (no HTTP credential context). credential_id is null.
     */
    public function writeWorkerAudit(int $tenantId, string $action, ?string $externalUserId, ?string $hbUserId, ?string $externalMessageId, int $outboxId, int $attempt, string $errorCode = null): void
    {
        try {
            $this->audit->insert([
                'tenant_id'            => $tenantId,
                'credential_id'        => null,
                'request_id'           => null,
                'method'               => 'WORKER',
                'endpoint'             => 'connect-worker',
                'ip_address'           => null,
                'user_agent'           => null,
                'request_hash'         => null,
                'response_code'        => $errorCode ? 500 : 200,
                'status'               => $errorCode ? 'error' : 'success',
                'error_code'           => $errorCode,
                'action'               => $action,
                'external_user_id'     => $externalUserId,
                'humming_bird_user_id' => $hbUserId,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Connect worker audit write failed: ' . $e->getMessage());
        }
    }

    /**
     * Update the delivery-tracking row for a connect external message.
     */
    public function updateDelivery(int $trackingId, int $tenantId, int $hbUserId, string $status, int $attempts, ?string $failureReason): void
    {
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $db = $this->db();
        $existing = $db->table('connect_message_deliveries')
            ->where('external_message_id', $trackingId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        if ($existing === false || $existing === null) {
            $this->upsertDelivery($trackingId, $tenantId, $hbUserId, $status, $attempts, $failureReason);

            return;
        }

        $data = [
            'status'           => $status,
            'attempts'         => $attempts,
            'last_attempt_at'  => $nowStr,
            'failure_reason'   => $failureReason,
            'updated_at'       => $nowStr,
        ];

        if ($status === ConnectConfig::DELIVERY_STATUS_SENT) {
            $data['delivered_at'] = $nowStr;
        }
        if ($status === ConnectConfig::DELIVERY_STATUS_FAILED) {
            $data['failed_at'] = $nowStr;
        }

        $this->deliveries->update((int) $existing['id'], $data);
    }

    private function upsertDelivery(int $trackingId, int $tenantId, int $hbUserId, string $status, int $attempts, ?string $failureReason): void
    {
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $this->deliveries->insert([
            'external_message_id' => $trackingId,
            'tenant_id'           => $tenantId,
            'humming_bird_user_id' => $hbUserId,
            'status'              => $status,
            'attempts'            => $attempts,
            'last_attempt_at'     => $nowStr,
            'failure_reason'      => $failureReason,
        ]);
    }

    // ------------------------------------------------------------------
    // Room / bot management (idempotent building blocks)
    // ------------------------------------------------------------------

    public function findOrCreateRoom(array $botUser, array $hbUser, array $identity): array
    {
        $botId = (string) $botUser['user_id'];
        $hbId  = (string) $hbUser['user_id'];

        $rUsersFirst  = json_encode([$botId, $hbId]);
        $rUsersSecond = json_encode([$hbId, $botId]);

        $existing = $this->rooms
            ->where('r_users', $rUsersFirst)
            ->where('r_user_deleted !=', $rUsersFirst)
            ->where('r_user_deleted !=', $rUsersSecond)
            ->first();

        if ($existing === null || $existing === []) {
            $existing = $this->rooms
                ->where('r_users', $rUsersSecond)
                ->where('r_user_deleted !=', $rUsersFirst)
                ->where('r_user_deleted !=', $rUsersSecond)
                ->first();
        }

        if ($existing !== null && $existing !== []) {
            return $existing;
        }

        $roomName = trim($identity['display_name'] ?? $identity['name'] ?? 'Connect');

        $roomId = $this->rooms->insert([
            'r_unique_id'  => rand(10000, 999999),
            'r_name'       => $roomName,
            'r_type'       => 0,
            'r_avatar'     => $identity['avatar'] ?? '',
            'r_created_by' => $botUser['user_id'],
            'r_users'      => $rUsersFirst,
            'r_admins'     => json_encode([$botId]),
            'r_status'     => 1,
        ], true);

        return $this->rooms->find($roomId) ?: [];
    }

    public function ensureBotUser(array $identity, int $tenantId): array
    {
        $email = 'connect_bot_' . (int) $identity['id'] . '@internal.myhummingbird.net';

        $existing = $this->users
            ->where('user_email', $email)
            ->first();

        if ($existing !== null && $existing !== []) {
            return $existing;
        }

        $displayName = trim(($identity['display_name'] ?? $identity['name'] ?? 'Connect'));
        $firstName   = $displayName !== '' ? $displayName : 'Connect';

        $userId = $this->users->insert([
            'user_first_name' => $firstName,
            'user_last_name'  => 'Bot',
            'user_email'      => $email,
            'u_is_deleted'    => 0,
            'user_image'      => $identity['avatar'] ?? '',
        ], true);

        return $this->users->find($userId) ?: [];
    }

    public function cipher(): ConnectMessageCipher
    {
        return $this->cipher;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Normalize SQLite3 Forge's DEFAULT 'NULL' string quirk.
     *
     * CI4's SQLite3 Forge generates `DEFAULT 'NULL'` (a literal string) for
     * nullable columns where the migration sets `'null' => true` without an
     * explicit default. Any omitted insert field gets the literal string
     * `NULL` instead of SQL NULL. This helper converts those strings back
     * to real PHP null so downstream code (`empty()`, `is_null()`, JSON
     * encoding) works correctly.
     */
    private static function normalizeNullString($value)
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $stripped = ltrim(trim($value, "'"), "'");
            if (in_array($stripped, ['NULL', 'null', ''], true)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Materialize the user-facing processing status of an external-message
     * tracking row (accepted → queued → processing → sent/failed).
     */
    public function effectiveStatus(array $trackingRow): string
    {
        $trackingStatus = $trackingRow['status'] ?? ConnectConfig::MESSAGE_STATUS_ACCEPTED;

        // A completed Humming Bird message id is the strongest signal — the
        // operation reached the existing message system.
        if (! empty(self::normalizeNullString($trackingRow['message_id'] ?? null))) {
            return ConnectConfig::MESSAGE_STATUS_SENT;
        }

        if (in_array($trackingStatus, [
            ConnectConfig::MESSAGE_STATUS_SENT,
            ConnectConfig::MESSAGE_STATUS_DELIVERED,
            ConnectConfig::MESSAGE_STATUS_READ,
        ], true)) {
            return $trackingStatus === ConnectConfig::MESSAGE_STATUS_SENT
                ? ConnectConfig::MESSAGE_STATUS_SENT
                : ConnectConfig::MESSAGE_STATUS_SENT;
        }

        if ($trackingStatus === ConnectConfig::MESSAGE_STATUS_FAILED) {
            return ConnectConfig::MESSAGE_STATUS_FAILED;
        }

        if ($trackingStatus === ConnectConfig::MESSAGE_STATUS_PROCESSING) {
            return ConnectConfig::MESSAGE_STATUS_PROCESSING;
        }

        return ConnectConfig::MESSAGE_STATUS_QUEUED;
    }

    private function duplicateResponse(ConnectTenantContext $context, array $existing, string $externalMessageId): array
    {
        return [
            'status' => 200,
            'data'   => [
                'tenant_id'               => (string) $context->tenantId(),
                'environment'             => $context->environment(),
                'credential_id'           => $context->credentialId(),
                'request_id'              => $context->requestId(),
                'external_user_id'        => (string) ($existing['external_user_id'] ?? ''),
                'external_message_id'     => $externalMessageId,
                'humming_bird_user_id'    => (string) ($existing['humming_bird_user_id'] ?? ''),
                'humming_bird_message_id' => $this->normalizeNullString($existing['message_id'] ?? null),
                'status'                  => $this->effectiveStatus($existing),
            ],
        ];
    }

    private function validateExternalUserId(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ConnectMessageException(
                ConnectConfig::ERROR_MISSING_EXTERNAL_USER_ID,
                'External user id is required.',
                400,
            );
        }

        if (strlen($value) > ConnectConfig::EXTERNAL_USER_ID_MAX_LEN
            || preg_match('/[\x00-\x20\x7F]/', $value)
            || ! preg_match('/^[\x21-\x7E]+$/', $value)) {
            throw new ConnectMessageException(
                ConnectConfig::ERROR_INVALID_EXTERNAL_USER_ID,
                'External user id is invalid.',
                400,
            );
        }

        return $value;
    }

    private function requireString(array $body, string $key, string $errorCode, string $errorMessage, int $httpStatus): string
    {
        if (! isset($body[$key]) || ! is_string($body[$key])) {
            throw new ConnectMessageException($errorCode, $errorMessage, $httpStatus);
        }

        return trim($body[$key]);
    }

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
            $method  = strtoupper($request->getMethod(true));
            $path    = '/' . ltrim($request->getPath(), '/');
            $ip      = substr((string) $request->getIPAddress(), 0, 45);
            $ua      = substr(trim((string) $request->getUserAgent()), 0, 255);

            $this->audit->insert([
                'tenant_id'            => $context->tenantId(),
                'credential_id'        => $context->credentialDbId(),
                'request_id'           => $context->requestId(),
                'method'               => $method,
                'endpoint'             => $path,
                'ip_address'           => $ip,
                'user_agent'           => $ua,
                'request_hash'         => hash('sha256', $method . '|' . $path . '|' . $context->requestId()),
                'response_code'        => $responseCode,
                'status'               => $status,
                'error_code'           => $errorCode,
                'action'               => $action,
                'external_user_id'     => $externalUserId,
                'humming_bird_user_id' => $hbUserId,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Connect message audit write failed: ' . $e->getMessage());
        }
    }

    protected function postToSocket(string $url, array $payload): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $ok = curl_exec($ch) !== false;
        curl_close($ch);

        return $ok;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}

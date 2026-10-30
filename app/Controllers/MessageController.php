<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\MessageModel;
use App\Models\RoomModel;
use App\Models\UserModel;
use DateTime;
use Config\Connect as ConnectConfig;

helper('scheduled_task');

class MessageController extends BaseController
{
    private ?UserModel $userModel = null;
    private ?MessageModel $messageModel = null;
    private ?RoomModel $roomModel = null;

    private function getUserModel(): UserModel
    {
        if ($this->userModel === null) {
            $this->userModel = new UserModel();
        }
        return $this->userModel;
    }

    private function getMessageModel(): MessageModel
    {
        if ($this->messageModel === null) {
            $this->messageModel = new MessageModel();
        }
        return $this->messageModel;
    }

    private function getRoomModel(): RoomModel
    {
        if ($this->roomModel === null) {
            $this->roomModel = new RoomModel();
        }
        return $this->roomModel;
    }

    private function isMessageAccessible(string $userId, array $message): bool
    {
        $roomId = trim((string)($message['m_room_id'] ?? ''));
        if (!ctype_digit($roomId) || (int)$roomId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
            return false;
        }

        $room = $this->getRoomModel()->find((int)$roomId);
        if (!$room) return false;

        $members = array_map('strval', json_decode($room['r_users'] ?? '[]', true) ?? []);
        return in_array($userId, $members, true);
    }

    public function fetchMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Authorization Failed", "room" => []
            ]);
        }

        $user = user();
        if (!$user) {
            return $this->response->setJSON([
                "status" => false, "message" => "User not found", "room" => null
            ]);
        }
        $roomId = $this->request->getVar('room_id');

        if ($roomId === 'new') {
            return $this->response->setJSON([
                "status" => true, "message" => "Welcome to New Message",
                "room" => ["room" => ["messages" => []]]
            ]);
        }

        $roomId = trim((string)$roomId);
        $userId = trim((string)$user['user_id']);
        if (!ctype_digit($roomId) || (int)$roomId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                "status" => false, "message" => "Invalid room or user identifier", "room" => null
            ]);
        }

        $room = $this->getRoomModel()->find((int)$roomId);
        if (!$room) {
            return $this->response->setJSON([
                "status" => false, "message" => "Room does not exist", "room" => null
            ]);
        }

        $roomUserIds = array_map('strval', json_decode($room['r_users'] ?? '[]', true) ?? []);
        if (!in_array($userId, $roomUserIds, true)) {
            return $this->response->setStatusCode(403)->setJSON([
                "status" => false, "message" => "You are not a member of this room", "room" => null
            ]);
        }

        // Batch-fetch all messages for this room (single query)
        $allMessages = $this->getMessageModel()
            ->where('m_room_id', $roomId)
            ->orderBy('m_epoch_time', 'desc')
            ->findAll();

        // Collect unique user IDs to batch-fetch
        $senderIds = [];
        foreach ($allMessages as $msg) {
            if ($msg['m_sender_id'] != $user['user_id']) {
                $senderIds[$msg['m_sender_id']] = true;
            }
        }

        // Batch-fetch senders
        $senders = [];
        if (!empty($senderIds)) {
            $senderRows = $this->getUserModel()
                ->whereIn('user_id', array_keys($senderIds))
                ->findAll();
            foreach ($senderRows as $row) {
                $senders[$row['user_id']] = $row;
            }
        }

        // For private rooms, batch-fetch receiver
        $receiverName = '';
        if ($room['r_type'] == 0) {
            $roomUsers = json_decode($room['r_users'], true) ?? [];
            $receiverId = null;
            foreach ($roomUsers as $uid) {
                if ($uid != $user['user_id']) {
                    $receiverId = $uid;
                    break;
                }
            }
            if ($receiverId) {
                $receiver = $this->getUserModel()->find($receiverId);
                if ($receiver) {
                    $receiverName = trim(($receiver['user_first_name'] ?? '') . ' ' . ($receiver['user_last_name'] ?? ''));
                }
            }
        } else {
            $receiverName = $room['r_name'] ?? '';
        }

        $userId = $isToken->user_id;
        $messages = [];

        foreach ($allMessages as $message) {
            $usersWhoDeleted = json_decode($message['m_users_who_deleted'] ?? '[]', true) ?: [];

            if (in_array($userId, $usersWhoDeleted)) {
                continue;
            }

            if ($message['m_sender_id'] == $user['user_id']) {
                $message['me'] = true;
            } else {
                $sender = $senders[$message['m_sender_id']] ?? null;
                $message['m_sender_name'] = $sender
                    ? trim(($sender['user_first_name'] ?? '') . ' ' . ($sender['user_last_name'] ?? ''))
                    : 'Unknown';
                $message['me'] = false;
            }

            $message['m_receiver_name'] = $receiverName;

            if ($user['user_id'] == $message['m_sender_id'] || $message['m_is_scheduled'] == 0) {
                $messages[] = $message;
            }
        }

        $room['messages'] = $messages;

        return $this->response->setJSON([
            "status" => true, "message" => "Messages Collected", "room" => $room
        ]);
    }

    public function sendMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed"]);
        }

        try {
            $user = user();
            if (!$user) {
                return $this->response->setJSON(['status' => false, 'message' => 'User not found']);
            }
            $mRoomId = $this->request->getVar('room_id');
            $receiverIdInput = $this->request->getVar('receiver_id');

            if ($mRoomId === 'new' || $mRoomId === '0') {
                $receiverIdInput = trim((string)$receiverIdInput);
                $userId = trim((string)$user['user_id']);
                if (!ctype_digit($receiverIdInput) || (int)$receiverIdInput <= 0
                    || !ctype_digit($userId) || (int)$userId <= 0
                    || (int)$receiverIdInput === (int)$userId
                ) {
                    return $this->response->setStatusCode(400)->setJSON([
                        'status' => false,
                        'message' => 'Invalid receiver identifier',
                    ]);
                }

                $roomModel = $this->getRoomModel();
                $userModel = $this->getUserModel();
                $receiver = $userModel->find($receiverIdInput);
                if (!$receiver) {
                    return $this->response->setJSON(['status' => false, 'message' => 'Receiver not found']);
                }

                $userId = $user['user_id'];
                $rUsersFirst = json_encode([$userId, $receiverIdInput]);
                $rUsersSecond = json_encode([$receiverIdInput, $userId]);

                $existingRoom = $roomModel->where('r_users', $rUsersFirst)
                    ->where('r_user_deleted !=', $rUsersFirst)
                    ->where('r_user_deleted !=', $rUsersSecond)
                    ->first();

                if (!$existingRoom) {
                    $existingRoom = $roomModel->where('r_users', $rUsersSecond)
                        ->where('r_user_deleted !=', $rUsersFirst)
                        ->where('r_user_deleted !=', $rUsersSecond)
                        ->first();
                }

                if ($existingRoom) {
                    $mRoomId = $existingRoom['r_id'];
                } else {
                    $roomData = [
                        "r_unique_id" => rand(10000, 999999),
                        "r_name" => trim($receiver['user_first_name'] . ' ' . $receiver['user_last_name']),
                        "r_type" => 0,
                        "r_avatar" => "",
                        "r_created_by" => $userId,
                        "r_users" => json_encode([$userId, $receiverIdInput]),
                        "r_admins" => json_encode([$userId]),
                        "r_status" => 1,
                    ];
                    $mRoomId = $roomModel->insert($roomData, true);
                }
            }

            $room = $this->getRoomModel()->find($mRoomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }

            $roomUserIds = array_map('strval', json_decode($room['r_users'] ?? '[]', true) ?? []);
            $senderId = trim((string)$user['user_id']);
            if (!in_array($senderId, $roomUserIds, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this room',
                ]);
            }

            // `message_type` is a scalar for a single attachment (mobile and
            // every legacy send) and a parallel array for a desktop album
            // (`message_type[]`), where each entry matches `media_file[]` by
            // index. `$mMessageType` keeps the first value for the text path,
            // logging and notifications; `$messageTypes` drives per-row types
            // when files are batched.
            $typeInput = $this->request->getPost('message_type');
            $messageTypes = is_array($typeInput) ? array_values($typeInput) : [];
            $mMessageType = $messageTypes !== [] ? $messageTypes[0] : $typeInput;
            $mMessage = $this->request->getPost('message') ?? '';
            $mIsScheduled = $this->request->getPost('is_scheduled');
            $scheduledAt = $this->request->getPost('scheduled_at') ?? 0;
            if ((string) $mIsScheduled !== '1') {
                // Normal messages must never carry a scheduled timestamp.
                $mIsScheduled = '0';
                $scheduledAt = '0';
            } else {
                // Flutter sends this through FormData too, so an empty value
                // must not reach the numeric `m_scheduled_at` column.
                $scheduledAt = (string) max(0, (int) $scheduledAt);
            }
            // Optional scheduler/reply fields arrive as empty strings from
            // Flutter's multipart FormData (Dio serialises null as ''). MySQL
            // in STRICT_TRANS_TABLES rejects '' for numeric columns, which is
            // why a normal mobile text send could fail while the desktop
            // (which omits absent fields entirely) worked. Normalise here so
            // every client gets the same valid row shape.
            $interval = $this->request->getVar('m_interval');
            $counter = $this->request->getVar('m_count');
            $repliedOn = $this->request->getVar('m_replied_on');

            $interval = ($interval === null || trim((string) $interval) === '')
                ? '0'
                : (string) max(0, (int) $interval);
            $counter = ($counter === null || trim((string) $counter) === '')
                ? '0'
                : (string) max(-1, (int) $counter);
            $repliedOn = trim((string) ($repliedOn ?? ''));
            $repliedOn = ($repliedOn === '' || $repliedOn === '0') ? null : $repliedOn;

            $userBlockList = json_decode($user['u_block_list'] ?? '[]', true) ?: [];

            $mSenderId = $user['user_id'];

            // Batch-fetch all room users once
            $roomUserIds = json_decode($room['r_users'], true) ?? [];
            $allUsers = [];
            if (!empty($roomUserIds)) {
                $usersRows = $this->getUserModel()->whereIn('user_id', $roomUserIds)->findAll();
                foreach ($usersRows as $row) {
                    $allUsers[$row['user_id']] = $row;
                }
            }

            $isPending = ($room['r_is_pending'] ?? 0) == 1;

            // Block check for private rooms (skip for pending rooms)
            if ($room['r_type'] == 0 && !$isPending) {
                // Reset soft-delete
                $room['r_user_deleted'] = '[]';
                $this->getRoomModel()->update($room['r_id'], ['r_user_deleted' => '[]']);

                foreach ($roomUserIds as $uid) {
                    if ($uid == $mSenderId) continue;

                    $reciever = $allUsers[$uid] ?? null;
                    if (!$reciever) continue;

                    $recieverBlockList = json_decode($reciever['u_block_list'] ?? '[]', true) ?: [];

                    if (in_array($uid, $userBlockList)) {
                        return $this->response->setJSON([
                            'status' => false,
                            'message' => 'You blocked ' . ($reciever['user_first_name'] ?? 'user') . ', Unblock user to send message.'
                        ]);
                    }

                    if (in_array($mSenderId, $recieverBlockList)) {
                        return $this->response->setJSON([
                            'status' => false,
                            'message' => 'You are blocked by ' . ($reciever['user_first_name'] ?? 'user') . '.'
                        ]);
                    }

                    if (($reciever['u_is_deleted'] ?? 0) == 1) {
                        return $this->response->setJSON([
                            'status' => false,
                            'message' => 'Receiver deleted their account, You can\'t send them a message.'
                        ]);
                    }
                }
            }

            // Handle media upload. Mobile sends a scalar `media_file`; a desktop
            // album sends `media_file[]` + a parallel `message_type[]`, all of
            // which are stored here (in order) so the rows below can share one
            // album group id.
            $mediaPaths = [];
            $mediaTypes = [];
            if ($mMessageType != 0) {
                $uploadFiles = $this->request->getFileMultiple('media_file');
                if (!is_array($uploadFiles)) {
                    $uploadFiles = [];
                }

                $validFiles = [];
                foreach ($uploadFiles as $candidate) {
                    if ($candidate && $candidate->isValid() && !$candidate->hasMoved()) {
                        $validFiles[] = $candidate;
                    }
                }

                // Scalar upload fallback (legacy/mobile single attachment).
                if (empty($validFiles)) {
                    $single = $this->request->getFile('media_file');
                    if ($single && $single->isValid() && !$single->hasMoved()) {
                        $validFiles[] = $single;
                    }
                }

                if (!empty($validFiles)) {
                    try {
                        $uploadService = new \App\Services\MediaUploadService();
                        foreach ($validFiles as $idx => $uploadFile) {
                            $stored = $uploadService->store($uploadFile, 'files/uploads');
                            $mediaPaths[] = $stored['web_path'];
                            $mediaTypes[] = $messageTypes[$idx] ?? (is_array($typeInput) ? 0 : $mMessageType);
                        }
                    } catch (\Throwable $e) {
                        log_message('error', 'sendMessage media upload failed: ' . $e->getMessage());
                        return $this->response->setJSON([
                            'status' => false,
                            'message' => 'Media upload failed. Please try again.',
                        ]);
                    }
                }
            }

            $now = new DateTime();
            $currentTime = $now->format('Y-m-d H:i:s');

            $mentionInput = $this->request->getVar('m_mentions');
            $mentionIds = [];
            if ($mentionInput) {
                $parsed = json_decode((string) $mentionInput, true);
                if (is_array($parsed)) {
                    $mentionIds = array_values(array_unique(array_filter(
                        array_map('intval', $parsed),
                        fn ($id) => $id > 0
                    )));
                }
            }

            // Story reference (status reply/reaction/forward delivered as a
            // chat message). Stored as plain JSON so the chat can render the
            // WhatsApp-style story preview; only persists when the column exists.
            $storyRefInput = $this->request->getVar('m_story_ref');
            $storyRefValue = null;
            if (!empty($storyRefInput) && \Config\Database::connect()->fieldExists('m_story_ref', 'messages')) {
                $parsedStoryRef = json_decode((string)$storyRefInput, true);
                if (is_array($parsedStoryRef) && !empty($parsedStoryRef['story_id'])) {
                    $storyRefValue = (string)$storyRefInput;
                }
            }

            // Album grouping. A client that sends several media together stamps
            // every row with the SAME client-generated `m_group_id` (one upload
            // request per file, so no single request has to exceed
            // `post_max_size`), which the desktop collapses into one bubble. A
            // client that instead packs N files into one request
            // (`media_file[]`) leaves the id to the server. Single sends send no
            // id and store NULL. The column is RDS-pending, so it only persists
            // when it exists — exactly like `m_status`/`m_mentions`.
            $hasGroupColumn = \Config\Database::connect()->fieldExists('m_group_id', 'messages');
            $clientGroup = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->request->getPost('m_group_id'));
            $groupId = null;
            if ($clientGroup !== '') {
                $groupId = substr($clientGroup, 0, 64);
            } elseif (count($mediaPaths) > 1) {
                $groupId = bin2hex(random_bytes(8));
            }

            $baseEpoch = $scheduledAt != 0 ? (int) $scheduledAt : strtotime($currentTime);
            $rowCount = max(1, count($mediaPaths));

            $messageModel = $this->getMessageModel();
            $messages = [];
            for ($i = 0; $i < $rowCount; $i++) {
                $isFirstRow = ($i === 0);
                $mData = [
                    // A fresh unique id per row: albums insert N rows and
                    // `m_unique_id` is used as a lookup key elsewhere.
                    "m_unique_id" => rand(10000, 999999),
                    "m_room_id" => $room['r_id'],
                    "m_sender_id" => $mSenderId,
                    // `m_receiver_id` is the direct-chat recipient. Mobile always
                    // sends `receiver_id` (the peer's user id; '0' for groups);
                    // Desktop does not send the field at all. Inserting NULL into
                    // the production `NOT NULL` column made EVERY desktop 1:1 send
                    // throw (`Column 'm_receiver_id' cannot be null`) — surfaced
                    // as the opaque "An error occurred". Resolve it from a real
                    // source instead: the client's explicit id when present,
                    // otherwise the newest inbound sender in this room (the
                    // established peer-identity rule — never `r_users`, which may
                    // hold a `contacts.c_id` and not a `users.user_id`), else ''
                    // (the exact shape group rows already store).
                    "m_receiver_id" => $this->resolveMessageReceiverId($room, $mSenderId),
                    // The (encrypted) caption rides on the first row of an album
                    // only, so a grouped render shows it once.
                    "m_message" => $isFirstRow ? $mMessage : '',
                    "m_message_type" => $mediaPaths !== [] ? ($mediaTypes[$i] ?? $mMessageType) : $mMessageType,
                    "m_read_status" => $mIsScheduled == 1 ? 0 : 1,
                    'm_media' => $mediaPaths[$i] ?? '',
                    'm_is_scheduled' => $mIsScheduled,
                    'm_scheduled_at' => $scheduledAt,
                    'm_interval' => $interval,
                    'm_count' => $counter,
                    'm_replied_on' => $repliedOn,
                    // Stagger the epoch by row so album members keep their send
                    // order even when they land in the same second.
                    'm_epoch_time' => $baseEpoch + $i,
                ];

                // `m_status` is a recent RDS-pending column — only persist it when
                // it exists. A missing column would otherwise make every insert
                // throw (`Unknown column`), silently bricking all sends until the
                // schema migration is applied.
                if ($isPending && \Config\Database::connect()->fieldExists('m_status', 'messages')) {
                    $mData['m_status'] = 'pending';
                }

                // Mentions only persist when the column exists — never break a send on a missing column.
                if (!empty($mentionIds) && \Config\Database::connect()->fieldExists('m_mentions', 'messages')) {
                    $mData['m_mentions'] = json_encode($mentionIds);
                }

                if ($storyRefValue !== null) {
                    $mData['m_story_ref'] = $storyRefValue;
                }

                if ($groupId !== null && $hasGroupColumn) {
                    $mData['m_group_id'] = $groupId;
                }

                $savedId = $messageModel->insert($mData, true);
                $savedRow = $savedId ? $messageModel->find($savedId) : null;
                if ($savedRow) {
                    $messages[] = $savedRow;
                }
            }

            if ($messages === []) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message could not be saved']);
            }

            $messageId = $messages[0]['m_id'] ?? null;

            log_message('info', sprintf(
                'sendMessage msg_id=%s type=%s rows=%d group=%s story_ref=%s',
                $messageId,
                $mMessageType,
                count($messages),
                $groupId ?? 'none',
                $storyRefValue ?? 'none'
            ));

            $receiverName = '';
            if ($room['r_type'] == 0) {
                foreach ($roomUserIds as $uid) {
                    if ($uid != $mSenderId) {
                        $r = $allUsers[$uid] ?? null;
                        if ($r) {
                            $receiverName = trim(($r['user_first_name'] ?? '') . ' ' . ($r['user_last_name'] ?? ''));
                        }
                        break;
                    }
                }
            } else {
                $receiverName = $room['r_name'] ?? '';
            }

            foreach ($messages as &$storedMessage) {
                $storedMessage['m_receiver_name'] = $receiverName;
            }
            unset($storedMessage);
            $message = $messages[0];

            // Don't fire FCM pushes for muted rooms (r_status = '0'). Local
            // notifications are suppressed client-side too, so mute keeps the
            // chat badge-only exactly like WhatsApp.
            $isRoomMuted = ($room['r_status'] ?? '1') == '0';

            // Notification delivery is best-effort: the message is already
            // persisted, so a missing ENCRYPTION_KEY, FCM failure or provider
            // outage must never turn a successful send into a `status: false`
            // response (the client then shows "could not be sent" and retries
            // a message that is already in the room).
            if ($mIsScheduled == 0 && !$isPending && !$isRoomMuted) {
                try {
                    $notificationController = new NotificationController();
                    $encryptionHelper = new \App\Helpers\EncryptionService();
                    $decryptedBody = $encryptionHelper->decryptMessage($mMessage);

                    $isGroupRoom = $room['r_type'] != 0;
                    foreach ($roomUserIds as $uid) {
                        if ($uid == $mSenderId) continue;
                        $recipient = $allUsers[$uid] ?? null;
                        if (!$recipient) continue;

                        $this->sendNotificationIfValid(
                            $notificationController, $recipient,
                            $user['user_first_name'], $decryptedBody,
                            $mSenderId, $room['r_id'], $user['user_mobile_num'],
                            $isGroupRoom ? '1' : '0', $user['user_image'] ?? '',
                            $user['user_bio'] ?? '', $room['r_name'] ?? ''
                        );
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'sendMessage notification failed: ' . $e->getMessage());
                }
            }

            return $this->response->setJSON([
                "status" => true,
                "message" => $mIsScheduled == 1 ? "Message Scheduled Successfully" : "Message Sent Successfully",
                // `msg` keeps the historical single-row shape; `msgs` carries
                // every row of an album (length 1 for an ordinary send).
                "msg" => $message,
                "msgs" => $messages,
            ]);

        } catch (\Throwable $e) {
            log_message('error', 'sendMessage failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            // Keep internal/SQL details in the log only. Validation branches
            // above return action-specific copy, which the client can show
            // safely; an unexpected failure must not leak the DB error to UI.
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Message could not be sent. Please try again.',
            ]);
        }
    }

    /**
     * Resolve `m_receiver_id` from a real source so a direct-room insert can
     * never reach the DB as NULL.
     *
     * Order of preference (matches how the ecosystem actually identifies a
     * conversation's other party):
     *  1. the client's explicit `receiver_id` when present (mobile always
     *     sends it — the peer user id, or '0' for a group);
     *  2. the newest inbound sender in this room — the peer-identity rule the
     *     whole codebase already follows, because `r_users` can hold a
     *     `contacts.c_id` rather than a `users.user_id`;
     *  3. '' (the empty-string shape group rows already store) so the column
     *     never receives NULL.
     *
     * @param array<string,mixed> $room
     */
    private function resolveMessageReceiverId(array $room, string $mSenderId): string
    {
        if ((string)($room['r_type'] ?? '') !== '0') {
            return '';
        }

        $explicit = $this->request->getVar('receiver_id');
        if ($explicit !== null && $explicit !== '' && $explicit !== '0') {
            return (string)$explicit;
        }

        $newestInbound = $this->getMessageModel()
            ->where('m_room_id', $room['r_id'])
            ->where('m_sender_id !=', $mSenderId)
            ->orderBy('m_id', 'DESC')
            ->first();
        if (!empty($newestInbound['m_sender_id'])) {
            return (string)$newestInbound['m_sender_id'];
        }

        return '';
    }

    private function sendNotificationIfValid(
        NotificationController $nc, array $recipient, string $title, string $body,
        string $senderId, string $roomId, string $userNumber, string $isGroup,
        string $userProfile, string $userBio, string $groupName
    ): void {
        if (!empty($recipient['fcm_token'])) {
            $nc->sendNotification(
                $recipient['fcm_token'], $title, $body, $senderId, $roomId,
                $userNumber, $isGroup, $userProfile, $userBio, $groupName
            );
        }
        if (!empty($recipient['web_fcm_token'])) {
            $nc->sendNotification(
                $recipient['web_fcm_token'], $title, $body, $senderId, $roomId,
                $userNumber, $isGroup, $userProfile, $userBio, $groupName
            );
        }
    }

    public function fetchMessages_original()
    {
        $isToken = check_jwt_authentication();

        if ($isToken) {
            $user = user();
            $messageModel = new MessageModel();
            $roomModel = new RoomModel();
            $userModel = new UserModel();
            $roomId = $this->request->getVar('room_id');

            if ($roomId == 'new') {
                $room = ["room" => ["messages" => []]];
                return $this->response->setJSON([
                    "status" => true, "message" => "Welcome to New Message", "room" => $room
                ]);
            } else {
                $room = $roomModel->find($roomId);
                if ($room) {
                    $room['messages'] = [];
                    $room['msgs'] = $messageModel->where(['m_room_id' => $roomId])->orderBy('m_epoch_time ', 'desc')->findAll();
                    $usersWhoDeleteadMsg = [];
                    foreach ($room['msgs'] as $message) {
                        $usersWhoDeleteadMsg = json_decode($message['m_users_who_deleted']) ?? [];
                        if (!in_array($isToken->user_id, $usersWhoDeleteadMsg)) {
                            if ($message['m_sender_id'] == $user['user_id']) {
                                $message['me'] = true;
                            } else {
                                $sender = $userModel->find($message['m_sender_id']);
                                $message['m_sender_name'] = $sender['user_first_name'] . ' ' . $sender['user_last_name'];
                                $message['me'] = false;
                            }
                            $receiverName = '';
                            if ($room['r_type'] == 0) {
                                $allRoomUsers = json_decode($room['r_users']);
                                $recieverId = 0;
                                foreach ($allRoomUsers as $userId) {
                                    if ($message['m_sender_id'] != $userId) {
                                        $userModel = new UserModel();
                                        $recieverId = $userId;
                                        $reciever = $userModel->find($recieverId);
                                        if ($reciever) {
                                            $receiverName = $reciever['user_first_name'] . ' ' . $reciever['user_last_name'];
                                        }
                                    }
                                }
                            } else {
                                $receiverName = $room['r_name'];
                            }
                            $message['m_receiver_name'] = $receiverName;
                            if ($user['user_id'] != $message['m_sender_id']) {
                                if ($message['m_is_scheduled'] == 0) {
                                    array_push($room['messages'], $message);
                                }
                            } else {
                                array_push($room['messages'], $message);
                            }
                        }
                    }
                    unset($room['msgs']);
                    return $this->response->setJSON([
                        "status" => true, "message" => "Messages Collected", "room" => $room,
                    ]);
                }
            }
        }
    }

    public function updateMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $mId = trim((string)$this->request->getVar('m_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }

            $newMessage = $this->request->getVar('new_message');
            $messageStatus = $this->request->getVar('message_status');
            $seenBy = $this->request->getVar('seen_by');
            $scheduledAt = $this->request->getVar('scheduled_at');
            $interval = $this->request->getVar('m_interval');
            $count = $this->request->getVar('m_count');

            $messageModel = $this->getMessageModel();
            $msg = $messageModel->find((int)$mId);

            if (!$msg) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $msg)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $isSender = (string)($msg['m_sender_id'] ?? '') === $userId;
            $senderUpdate = $newMessage !== null || $scheduledAt !== null
                || $interval !== null || $count !== null;
            if ($senderUpdate && !$isSender) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'Only the sender can update message content or scheduling',
                ]);
            }
            if ($messageStatus !== null && !in_array((string)$messageStatus, ['0', '1', '2', '3'], true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message status',
                ]);
            }
            if ($seenBy !== null) {
                $seenBy = $userId;
            }

            $updateData = [];

            if (!empty($newMessage)) {
                $updateData['m_message'] = $newMessage;
                $updateData['m_is_edited'] = 1;
            }
            if (!empty($messageStatus)) {
                $updateData['m_read_status'] = $messageStatus;
            }
            if (!empty($seenBy)) {
                $seenList = json_decode($msg['m_seen_by'] ?? '[]', true) ?: [];
                $seenList[] = $seenBy;
                $updateData['m_seen_by'] = json_encode(array_unique($seenList));

                $room = $this->getRoomModel()->find($msg['m_room_id']);
                if ($room && ($room['r_type'] ?? 0) == 1) {
                    $roomUsers = json_decode($room['r_users'] ?? '[]', true) ?: [];
                    $uniqueSeen = count(array_unique($seenList));
                    $updateData['m_read_status'] = ($uniqueSeen >= count($roomUsers) - 1) ? '3' : '2';
                }
            }
            if (!empty($scheduledAt)) {
                $updateData['m_scheduled_at'] = $scheduledAt;
                $updateData['m_epoch_time'] = $scheduledAt;
                $updateData['m_is_scheduled'] = 1;
                $updateData['m_read_status'] = 0;
            }
            if ($interval !== null && $interval !== '') {
                $updateData['m_interval'] = $interval;
            }
            if ($count !== null && $count !== '') {
                $updateData['m_count'] = $count;
            }

            $success = empty($updateData) ? false : $messageModel->update($mId, $updateData);
            $updatedMessage = $messageModel->find($mId);

            // Phase 7 — Connect read/delivery hook. If this message is
            // Connect-originated and the device just reported delivery (2) or
            // read (3) via the EXISTING read-receipt path, feed the Connect
            // status ledger + webhooks. Best-effort: never fails the update.
            $connectReadStatus = (string) ($updatedMessage['m_read_status'] ?? '');
            if ($connectReadStatus === '2' || $connectReadStatus === '3') {
                try {
                    $statusService = \Config\Services::connectmessagestatus();
                    $statusService->recordFromMessageId(
                        (int) $mId,
                        $connectReadStatus === '3' ? ConnectConfig::MESSAGE_STATUS_READ : ConnectConfig::MESSAGE_STATUS_DELIVERED,
                        $connectReadStatus === '3' ? 'recipient read the message' : 'recipient device received the message',
                    );
                } catch (\Throwable $e) {
                    log_message('error', 'Connect read-status hook failed: ' . $e->getMessage());
                }
            }

            return $this->response->setJSON([
                'status' => (bool)$success,
                'message' => $success ? 'Message updated' : 'Nothing to update',
                'msg' => $updatedMessage,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getScheduledMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed"]);
        }

        $userId = $isToken->user_id;
        $scheduledMessages = $this->getMessageModel()
            ->where(['m_sender_id' => $userId, 'm_scheduled_at !=' => '0'])
            ->orderBy('m_scheduled_at', 'ASC')
            ->findAll();

        if (empty($scheduledMessages)) {
            return $this->response->setJSON([
                'status' => true, 'message' => '0 Scheduled Messages Found', 'data' => []
            ]);
        }

        // Batch-fetch all unique room IDs
        $roomIds = array_unique(array_column($scheduledMessages, 'm_room_id'));
        $rooms = [];
        if (!empty($roomIds)) {
            $roomRows = $this->getRoomModel()->whereIn('r_id', $roomIds)->findAll();
            foreach ($roomRows as $r) {
                $rooms[$r['r_id']] = $r;
            }
        }

        $userIdsToFetch = [];
        foreach ($scheduledMessages as $msg) {
            $room = $rooms[$msg['m_room_id']] ?? null;
            if ($room && $room['r_type'] == 0) {
                $roomUsers = json_decode($room['r_users'], true) ?? [];
                foreach ($roomUsers as $uid) {
                    if ($uid != $msg['m_sender_id']) {
                        $userIdsToFetch[$uid] = true;
                    }
                }
            }
        }

        // Batch-fetch all unique user IDs
        $userNames = [];
        if (!empty($userIdsToFetch)) {
            $userRows = $this->getUserModel()->whereIn('user_id', array_keys($userIdsToFetch))->findAll();
            foreach ($userRows as $u) {
                $userNames[$u['user_id']] = trim(($u['user_first_name'] ?? '') . ' ' . ($u['user_last_name'] ?? ''));
            }
        }

        $data = [];
        foreach ($scheduledMessages as $message) {
            // A message the caller deleted must not reappear in their scheduled
            // list — the same rule `fetchMessages` applies to the thread.
            $deletedBy = json_decode($message['m_users_who_deleted'] ?? '[]', true) ?: [];
            if (in_array($userId, $deletedBy)) {
                continue;
            }

            // Legacy rows never got an explicit status — derive it: still
            // scheduled = pending, otherwise it already fired = sent.
            if (empty($message['m_status'])) {
                $message['m_status'] = ($message['m_is_scheduled'] ?? '0') === '1' ? 'pending' : 'sent';
            }
            $room = $rooms[$message['m_room_id']] ?? null;
            if ($room && $room['r_type'] == 0) {
                $roomUsers = json_decode($room['r_users'], true) ?? [];
                $receiverName = '';
                foreach ($roomUsers as $uid) {
                    if ($uid != $message['m_sender_id']) {
                        $receiverName = $userNames[$uid] ?? '';
                        break;
                    }
                }
                $message['m_receiver_name'] = $receiverName;
            } else {
                $message['m_receiver_name'] = $room['r_name'] ?? '';
            }
            $data[] = $message;
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => count($data) . ' Scheduled Messages Found',
            'data' => $data
        ]);
    }

    public function cancelScheduledMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed"]);
        }

        $messageId = $this->request->getGet('message_id');
        try {
            $scheduledMessage = $this->getMessageModel()
                ->where(['m_sender_id' => $isToken->user_id, 'm_id' => $messageId])
                ->first();

            if (!$scheduledMessage) {
                return $this->response->setJSON(['status' => false, 'message' => 'Scheduled Message not found.']);
            }

            $this->getMessageModel()->update($messageId, [
                'm_is_scheduled' => 0,
                'm_status' => 'cancelled',
            ]);
            return $this->response->setJSON(['status' => true, 'message' => 'Message Successfully Canceled']);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $messageIds = $this->request->getVar('message_id');
            $deleteFor = $this->request->getVar('delete_for');
            $messageIdList = json_decode((string)$messageIds, true);
            $messageModel = $this->getMessageModel();
            $userId = trim((string)$isToken->user_id);

            if (!is_array($messageIdList) || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }
            $messageIdList = array_values(array_unique(array_filter(
                array_map('strval', $messageIdList),
                fn($messageId) => ctype_digit($messageId) && (int)$messageId > 0
            )));
            if (empty($messageIdList)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'At least one valid message identifier is required',
                ]);
            }

            foreach ($messageIdList as $messageId) {
                $message = $messageModel->find((int)$messageId);
                if (!$message) continue;
                if (!$this->isMessageAccessible($userId, $message)) {
                    return $this->response->setStatusCode(403)->setJSON([
                        'status' => false,
                        'message' => 'You are not a member of this message room',
                    ]);
                }

                $isSender = (string)$message['m_sender_id'] === $userId;
                if ($deleteFor === '1' && !$isSender) {
                    return $this->response->setStatusCode(403)->setJSON([
                        'status' => false,
                        'message' => 'Only the sender can delete a message for everyone',
                    ]);
                }
                $deleteForEveryone = ($deleteFor === '1');

                if ($deleteForEveryone) {
                    // Delete for Everyone is only available within 1 hour of
                    // the message being sent. Server-side validation uses the
                    // persisted send timestamp (not the client clock) so the
                    // limit cannot be bypassed by changing the device time.
                    $sentEpoch = (int)($message['m_epoch_time'] ?? 0);
                    if ($sentEpoch <= 0 || (time() - $sentEpoch) > 3600) {
                        return $this->response->setJSON([
                            'status' => false,
                            'message' => 'Delete for everyone is only available for 1 hour after sending.',
                        ]);
                    }

                    // WhatsApp-style: keep the row so both sides render
                    // "deleted message" placeholders instead of losing the
                    // message entirely. Fall back to a hard delete when the
                    // soft-delete columns aren't migrated yet.
                    $db = \Config\Database::connect();
                    if ($db->fieldExists('m_is_deleted', 'messages')) {
                        $messageModel->update($messageId, [
                            'm_is_deleted' => '1',
                            'm_deleted_at' => date('Y-m-d H:i:s'),
                        ]);
                    } else {
                        $messageModel->delete($messageId);
                    }
                } else {
                    $userWhoDeleted = json_decode($message['m_users_who_deleted'] ?? '[]', true) ?: [];
                    $userWhoDeleted[] = $userId;
                    $messageModel->update($messageId, [
                        'm_users_who_deleted' => json_encode(array_unique($userWhoDeleted)),
                    ]);

                    $senderIn = in_array($message['m_sender_id'], $userWhoDeleted);
                    $receiverIn = in_array($message['m_receiver_id'], $userWhoDeleted);
                    if ($senderIn && $receiverIn) {
                        $messageModel->delete($messageId);
                    }
                }
            }

            return $this->response->setJSON(['status' => true, 'message' => 'Message Successfully deleted']);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function sendScheduledMessage()
    {
        $internalKey = $this->request->getHeaderLine('X-Internal-Key');
        $expectedKey = getenv('INTERNAL_CRON_KEY');
        if ($expectedKey === false || $expectedKey === '') {
            log_message('error', 'Scheduled message dispatch is not configured');
            return $this->response->setStatusCode(503)->setJSON([
                'status' => false,
                'message' => 'Scheduled message dispatch is unavailable',
            ]);
        }
        if ($internalKey === '' || !hash_equals($expectedKey, $internalKey)) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => false,
                'message' => 'Authorization Failed',
            ]);
        }

        try {
            $mId = $this->request->getVar('m_id');
            $epoch = $this->request->getVar('epoch');
            $isRepeat = $this->request->getVar('repeat');
            $newEpoch = $this->request->getVar('new_epoch') ?: $epoch;

            $messageModel = $this->getMessageModel();
            $userModel = $this->getUserModel();

            if (empty($mId)) {
                $now = time();
                $dueMessages = $messageModel
                    ->where('m_is_scheduled', 1)
                    ->where('m_scheduled_at <=', $now)
                    ->where('m_scheduled_at !=', '0')
                    ->findAll();

                $sent = [];
                foreach ($dueMessages as $message) {
                    $fresh = $messageModel->find($message['m_id']);
                    if (!$fresh || ($fresh['m_is_scheduled'] ?? '0') != '1') continue;
                    $sent[] = $this->dispatchScheduledMessage($message, $messageModel, $userModel);
                }

                $momentController = new \App\Controllers\ScheduledMomentController();
                $momentController->runScheduledProcessing();

                return $this->response->setJSON([
                    'status' => true,
                    'message' => count($sent) . ' scheduled messages processed',
                    'data' => array_values(array_filter($sent)),
                ]);
            }

            $scheduledMessage = $messageModel->find($mId);

            if (!$scheduledMessage) {
                return $this->response->setJSON(['status' => false, 'message' => 'Scheduled message not found']);
            }

            if (!empty($newEpoch)) {
                $scheduledMessage['m_scheduled_at'] = $newEpoch;
            }

            $updatedMessage = $this->dispatchScheduledMessage($scheduledMessage, $messageModel, $userModel, $isRepeat);

            return $this->response->setJSON([
                'status' => true, 'message' => 'Message Sent', 'data' => $updatedMessage
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'sendScheduledMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    private function dispatchScheduledMessage(array $scheduledMessage, $messageModel, $userModel, $repeatOverride = null)
    {
        $messageId = $scheduledMessage['m_id'];
        $messageModel->update($messageId, [
            'm_is_scheduled' => 0,
            'm_read_status' => 1,
            'm_status' => 'sent',
        ]);

        $updatedMessage = $messageModel->find($messageId);
        $sender = $userModel->find($updatedMessage['m_sender_id']);
        $receiver = $userModel->find($updatedMessage['m_receiver_id']);
        $room = $this->getRoomModel()->find($updatedMessage['m_room_id']);

        if ($room) {
            $notificationController = new NotificationController();
            $encryptionHelper = new \App\Helpers\EncryptionService();
            $decryptedBody = $encryptionHelper->decryptMessage($updatedMessage['m_message']);
            $roomUserIds = json_decode($room['r_users'], true) ?? [];
            $updatedMessage['room_user_ids'] = $roomUserIds;
            $allRecipients = [];
            if (!empty($roomUserIds)) {
                $rows = $userModel->whereIn('user_id', $roomUserIds)->findAll();
                foreach ($rows as $r) {
                    $allRecipients[$r['user_id']] = $r;
                }
            }

            foreach ($allRecipients as $uid => $recipient) {
                if ($uid == $updatedMessage['m_sender_id']) continue;
                $this->sendNotificationIfValid(
                    $notificationController, $recipient,
                    $sender['user_first_name'] ?? '', $decryptedBody,
                    $updatedMessage['m_sender_id'], $updatedMessage['m_room_id'],
                    $sender['user_mobile_num'] ?? '', (string)($room['r_type'] ?? 0),
                    $sender['user_image'] ?? '', $sender['user_bio'] ?? '',
                    $room['r_name'] ?? ''
                );
            }
        }

        $interval = (int)($repeatOverride ?? $scheduledMessage['m_interval'] ?? 0);
        $count = (int)($scheduledMessage['m_count'] ?? 0);
        if ($interval > 0 && ($count === -1 || $count > 1)) {
            $nextMessage = $scheduledMessage;
            unset($nextMessage['m_id']);
            $nextMessage['m_unique_id'] = rand(10000, 999999);
            $nextMessage['m_is_scheduled'] = 1;
            $nextMessage['m_read_status'] = 0;
            if (\Config\Database::connect()->fieldExists('m_status', 'messages')) {
                $nextMessage['m_status'] = 'pending';
            }
            $nextMessage['m_scheduled_at'] = $this->nextScheduledEpoch(
                (int)$scheduledMessage['m_scheduled_at'],
                $interval
            );
            $nextMessage['m_epoch_time'] = $nextMessage['m_scheduled_at'];
            if ($count > 1) {
                $nextMessage['m_count'] = (string)($count - 1);
            }
            $messageModel->insert($nextMessage);
        }

        return $updatedMessage;
    }

    private function nextScheduledEpoch(int $currentEpoch, int $interval): int
    {
        switch ($interval) {
            case 1:
                return strtotime('+1 day', $currentEpoch);
            case 2:
                return strtotime('+1 week', $currentEpoch);
            case 3:
                // Month: keep the same day-of-month, clamping to the last day
                // of the target month (Jan 31 → Feb 28) instead of letting
                // strtotime('+1 month') roll on to Mar 3.
                return $this->addClampedMonths($currentEpoch, 1);
            case 4:
                // Year: same logic keeps Feb 29 → Feb 28 on non-leap years.
                return $this->addClampedMonths($currentEpoch, 12);
            default:
                return $currentEpoch;
        }
    }

    private function addClampedMonths(int $epoch, int $months): int
    {
        $year = (int) date('Y', $epoch);
        $month = (int) date('n', $epoch);
        $day = (int) date('j', $epoch);
        $hour = (int) date('G', $epoch);
        $minute = (int) date('i', $epoch);
        $second = (int) date('s', $epoch);

        $targetIndex = $month + $months - 1;
        $targetYear = $year + intdiv($targetIndex, 12);
        $targetMonth = ($targetIndex % 12) + 1;
        $lastDay = (int) date('t', mktime(0, 0, 0, $targetMonth, 1, $targetYear));

        return mktime($hour, $minute, $second, $targetMonth, min($day, $lastDay), $targetYear);
    }

    public function toggleReaction()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $userId = trim((string)$isToken->user_id);
            $input = $this->request->getJSON(true) ?: [];
            $mId = trim((string)($input['m_id'] ?? $this->request->getGetPost('m_id')));
            $emoji = trim((string)($input['emoji'] ?? $this->request->getGetPost('emoji')));

            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0 || $emoji === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid reaction or user identifier',
                ]);
            }

            $message = $this->getMessageModel()->find((int)$mId);
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $message)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $reactions = json_decode($message['m_reactions'] ?? '{}', true) ?: [];
            $existingReaction = null;

            // Remove user from ALL existing reactions (one reaction per user)
            foreach ($reactions as $key => &$userList) {
                $idx = array_search($userId, $userList);
                if ($idx !== false) {
                    $existingReaction = $key;
                    array_splice($userList, $idx, 1);
                    if (empty($userList)) {
                        unset($reactions[$key]);
                    }
                    break;
                }
            }
            unset($userList);

            // If tapped emoji is different from existing, add new reaction
            if ($existingReaction !== $emoji) {
                $reactions[$emoji][] = $userId;
            }

            $this->getMessageModel()->update($mId, ['m_reactions' => json_encode($reactions)]);
            $updatedMessage = $this->getMessageModel()->find($mId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Reaction toggled',
                'msg' => $updatedMessage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'toggleReaction: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function pinMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $mId = trim((string)$this->request->getVar('m_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }

            $message = $this->getMessageModel()->find((int)$mId);
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $message)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $this->getMessageModel()->update($mId, [
                'm_pinned' => '1',
                'm_pinned_at' => time(),
            ]);

            $updatedMessage = $this->getMessageModel()->find($mId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Message pinned',
                'msg' => $updatedMessage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'pinMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function unpinMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $mId = trim((string)$this->request->getVar('m_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }

            $message = $this->getMessageModel()->find((int)$mId);
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $message)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $this->getMessageModel()->update($mId, [
                'm_pinned' => '0',
                'm_pinned_at' => null,
            ]);

            $updatedMessage = $this->getMessageModel()->find($mId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Message unpinned',
                'msg' => $updatedMessage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'unpinMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function starMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $mId = trim((string)$this->request->getVar('m_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }

            $message = $this->getMessageModel()->find((int)$mId);
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $message)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $this->getMessageModel()->update($mId, ['m_starred' => '1']);
            $updatedMessage = $this->getMessageModel()->find($mId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Message starred',
                'msg' => $updatedMessage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'starMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function unstarMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $mId = trim((string)$this->request->getVar('m_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }

            $message = $this->getMessageModel()->find((int)$mId);
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $message)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $this->getMessageModel()->update($mId, ['m_starred' => '0']);
            $updatedMessage = $this->getMessageModel()->find($mId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Message unstarred',
                'msg' => $updatedMessage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'unstarMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function forwardMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            if (!$user) {
                return $this->response->setJSON(['status' => false, 'message' => 'User not found']);
            }
            $userId = trim((string)$user['user_id']);
            $messageIdsInput = $this->request->getPost('message_ids');
            $roomIdsInput = $this->request->getPost('room_ids');

            if (empty($messageIdsInput) || empty($roomIdsInput)) {
                $jsonBody = $this->request->getJSON(true);
                if (is_array($jsonBody)) {
                    $messageIdsInput = $jsonBody['message_ids'] ?? '';
                    $roomIdsInput = $jsonBody['room_ids'] ?? '';
                }
            }

            $messageIds = is_array($messageIdsInput) ? $messageIdsInput : (json_decode((string) $messageIdsInput, true) ?? []);
            $roomIds = is_array($roomIdsInput) ? $roomIdsInput : (json_decode((string) $roomIdsInput, true) ?? []);

            if (!is_array($messageIds) || !is_array($roomIds) || empty($messageIds) || empty($roomIds)) {
                return $this->response->setJSON(['status' => false, 'message' => 'Missing message_ids or room_ids']);
            }

            $messageIds = array_values(array_unique(array_filter(
                array_map('strval', $messageIds),
                fn($messageId) => ctype_digit($messageId) && (int)$messageId > 0
            )));
            $roomIds = array_values(array_unique(array_filter(
                array_map('strval', $roomIds),
                fn($roomId) => ctype_digit($roomId) && (int)$roomId > 0
            )));
            if (!ctype_digit($userId) || (int)$userId <= 0 || empty($messageIds) || empty($roomIds)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message, room, or user identifier',
                ]);
            }

            $messageModel = $this->getMessageModel();
            $roomModel = $this->getRoomModel();
            $forwardedCount = 0;

            foreach ($messageIds as $mId) {
                $sourceMessage = $messageModel->find((int)$mId);
                if (!$sourceMessage) continue;
                if (!$this->isMessageAccessible($userId, $sourceMessage)) {
                    return $this->response->setStatusCode(403)->setJSON([
                        'status' => false,
                        'message' => 'You are not a member of the source message room',
                    ]);
                }

                foreach ($roomIds as $roomId) {
                    $targetRoom = $roomModel->find((int)$roomId);
                    if (!$targetRoom) continue;
                    $targetMembers = array_map('strval', json_decode($targetRoom['r_users'] ?? '[]', true) ?? []);
                    if (!in_array($userId, $targetMembers, true)) {
                        return $this->response->setStatusCode(403)->setJSON([
                            'status' => false,
                            'message' => 'You are not a member of a destination room',
                        ]);
                    }

                    $newMessage = [
                        'm_unique_id' => rand(10000, 999999),
                        'm_room_id' => $roomId,
                        'm_sender_id' => $user['user_id'],
                        'm_receiver_id' => '',
                        'm_message' => $sourceMessage['m_message'],
                        'm_message_type' => $sourceMessage['m_message_type'],
                        'm_read_status' => 1,
                        'm_media' => $sourceMessage['m_media'],
                        'm_is_scheduled' => 0,
                        'm_scheduled_at' => 0,
                        'm_interval' => '0',
                        'm_count' => '0',
                        'm_users_who_deleted' => '[]',
                        'm_replied_on' => null,
                        'm_seen_by' => '[]',
                        'm_epoch_time' => time(),
                        'm_reactions' => '{}',
                        'm_pinned' => '0',
                        'm_starred' => '0',
                        'm_is_edited' => '0',
                        'm_forwarded' => '1',
                        'm_forwarded_from' => $sourceMessage['m_sender_id'],
                        'm_forwarded_from_msg_id' => $sourceMessage['m_unique_id'],
                        'm_pinned_at' => null,
                    ];

                    $messageModel->insert($newMessage);
                    $forwardedCount++;
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => "$forwardedCount messages forwarded",
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'forwardMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function fetchMessageInfo()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $mId = trim((string)$this->request->getVar('m_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($mId) || (int)$mId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid message or user identifier',
                ]);
            }

            $message = $this->getMessageModel()->find((int)$mId);
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            if (!$this->isMessageAccessible($userId, $message)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this message room',
                ]);
            }

            $sender = $this->getUserModel()->find($message['m_sender_id']);
            $message['sender_info'] = $sender ? [
                'user_id' => $sender['user_id'],
                'user_first_name' => $sender['user_first_name'] ?? '',
                'user_last_name' => $sender['user_last_name'] ?? '',
                'user_image' => $sender['user_image'] ?? '',
            ] : null;

            $seenBy = json_decode($message['m_seen_by'] ?? '[]', true) ?: [];
            $seenByInfo = [];
            if (!empty($seenBy)) {
                $users = $this->getUserModel()->whereIn('user_id', $seenBy)->findAll();
                foreach ($users as $u) {
                    $seenByInfo[] = [
                        'user_id' => $u['user_id'],
                        'user_first_name' => $u['user_first_name'] ?? '',
                        'user_last_name' => $u['user_last_name'] ?? '',
                        'user_image' => $u['user_image'] ?? '',
                    ];
                }
            }
            $message['seen_by_info'] = $seenByInfo;

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Message info fetched',
                'data' => $message,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'fetchMessageInfo: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function fetchPinnedMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getVar('room_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($roomId) || (int)$roomId <= 0 || !ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid room or user identifier',
                ]);
            }
            $room = $this->getRoomModel()->find((int)$roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }
            $members = array_map('strval', json_decode($room['r_users'] ?? '[]', true) ?? []);
            if (!in_array($userId, $members, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this room',
                ]);
            }

            $messages = $this->getMessageModel()
                ->where('m_room_id', $roomId)
                ->where('m_pinned', '1')
                ->orderBy('m_pinned_at', 'desc')
                ->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => count($messages) . ' pinned messages found',
                'data' => $messages,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'fetchPinnedMessages: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function fetchStarredMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $userId = $isToken->user_id;

            $messages = $this->getMessageModel()
                ->where('m_sender_id', $userId)
                ->where('m_starred', '1')
                ->orderBy('m_updated_at', 'desc')
                ->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => count($messages) . ' starred messages found',
                'data' => $messages,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'fetchStarredMessages: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}

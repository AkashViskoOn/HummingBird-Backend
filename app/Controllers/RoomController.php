<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ContactModel;
use App\Models\MessageModel;
use App\Models\RoomModel;
use App\Models\UserModel;

helper('room');

class RoomController extends BaseController
{
    private ?RoomModel $roomModel = null;
    private ?UserModel $userModel = null;
    private ?MessageModel $messageModel = null;

    private function getRoomModel(): RoomModel
    {
        if ($this->roomModel === null) $this->roomModel = new RoomModel();
        return $this->roomModel;
    }

    private function getUserModel(): UserModel
    {
        if ($this->userModel === null) $this->userModel = new UserModel();
        return $this->userModel;
    }

    private function getMessageModel(): MessageModel
    {
        if ($this->messageModel === null) $this->messageModel = new MessageModel();
        return $this->messageModel;
    }

    public function createRoom()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed", "room" => null]);
        }

        $user = user();
        $rType = $this->request->getPost('r_type');
        $rUniqueId = rand(10000, 99999);

        if ($rType == 0) {
            $contactId = $this->request->getPost('contact_id');
            if ($contactId && $contactId > 0) {
                $contact = (new ContactModel())->where('c_unique_id', $contactId)->first();
                if ($contact) {
                    $rName = trim($contact['c_first_name'] . ' ' . $contact['c_last_name']);
                    $rUsers = json_encode([$user['user_id'], $contact['c_id']]);
                    $rAvatar = "";
                } else {
                    return $this->response->setJSON(["status" => false, "message" => "Contact not found", "room" => null]);
                }
            } else {
                $participants = json_decode($this->request->getVar('participants'), true) ?? [];
                $usersId = [$user['user_id']];
                if (!empty($participants)) {
                    $existingUsers = $this->getUserModel()
                        ->whereIn('user_mobile_num', $participants)
                        ->findAll();
                    foreach ($existingUsers as $u) {
                        $usersId[] = $u['user_id'];
                    }
                }
                $rName = '';
                $rUsers = json_encode($usersId);
                $rAvatar = "";
            }
        } elseif ($rType == 1) {
            $rName = $this->request->getPost('r_name');
            $participants = json_decode($this->request->getVar('participants'), true) ?? [];
            $usersId = [$user['user_id']];

            if (!empty($participants)) {
                $existingUsers = $this->getUserModel()
                    ->whereIn('user_mobile_num', $participants)
                    ->findAll();
                foreach ($existingUsers as $u) {
                    $usersId[] = $u['user_id'];
                }
            }
            $rUsers = json_encode($usersId);
            $rAvatar = "";
        } else {
            return $this->response->setJSON(["status" => false, "message" => "Invalid room type"]);
        }

        $roomId = $this->getRoomModel()->insert([
            "r_unique_id" => $rUniqueId,
            "r_name" => $rName,
            "r_type" => $rType,
            "r_avatar" => $rAvatar,
            "r_created_by" => $user['user_id'],
            "r_users" => $rUsers,
            "r_admins" => json_encode([$user['user_id']]),
            "r_status" => 1,
        ], true);

        $room = $this->getRoomModel()->find($roomId);

        return $this->response->setJSON([
            "status" => true, "message" => "Room created successfully", "room" => $room
        ]);
    }

    public function fetchRooms()
    {
        try {
            $isToken = check_jwt_authentication();
            if (!$isToken) {
                return $this->response->setJSON(["status" => false, "message" => "Authorization Failed"]);
            }

            $roomType = $this->request->getVar('room_type');
            if (!in_array($roomType, ['0', '1'])) {
                return $this->response->setJSON(["status" => false, "message" => "Invalid room type provided."]);
            }

            $user = user();
            $userId = $user['user_id'];

            $roomModel = $this->getRoomModel();
            $messageModel = $this->getMessageModel();
            $userModel = $this->getUserModel();

            $roomData = $roomModel
                ->where('r_type', $roomType)
                ->like('r_users', '"' . $userId . '"', 'both')
                ->findAll();

            if (empty($roomData)) {
                return $this->response->setJSON([
                    "status" => true, "message" => "Rooms Found Successfully", "rooms" => []
                ]);
            }

            // Collect all room IDs
            $roomIds = array_column($roomData, 'r_id');

            // ── Per-user unread message counts (server-authoritative) ─────────
            // A message counts as unread for the current user when it was sent
            // by someone else, is a real (non-scheduled) delivered message, was
            // not deleted for the user, and the user's id is not in m_seen_by.
            // This is what feeds the "Unread" filter chip + per-tile badges so
            // they stay correct even across cold starts.
            $unreadCounts = [];
            $markedUnreadRooms = [];
            $archivedRooms = [];
            $favoritedRooms = [];
            try {
                $db = \Config\Database::connect();
                $escUserId = $db->escape($userId);
                $seenQuoted = $db->escape('"' . $userId . '"');
                $roomIdsCsv = implode(',', array_map('intval', $roomIds));

                // ── Per-user archive/favorite/unread prefs (reads from
                //    room_user_prefs). Wrapped FIRST and in its own try/catch so
                //    a failure in the unread-count query below can never silence
                //    these flags — the two are unrelated reads.
                if ($roomIdsCsv !== '') {
                    $asserted = $db->tableExists('room_user_prefs');
                    if ($asserted) {
                        try {
                            // The builder (not raw SQL) is deliberate: it applies
                            // DBPrefix ('db_' in the tests group, '' in prod),
                            // which raw query() never does — the tests group would
                            // otherwise fail to see its prefixed stub table.
                            $prefRows = $db->table('room_user_prefs')
                                ->select('room_id, is_archived, is_favorited, marked_unread')
                                ->where('user_id', $userId)
                                ->whereIn('room_id', array_map('intval', $roomIds))
                                ->get()
                                ->getResultArray();
                            foreach ($prefRows as $row) {
                                $rid = (int) $row['room_id'];
                                if (!empty($row['marked_unread'])) {
                                    $markedUnreadRooms[$rid] = true;
                                }
                                if (isset($row['is_archived'])) {
                                    $archivedRooms[$rid] = (bool) $row['is_archived'];
                                }
                                if (isset($row['is_favorited'])) {
                                    $favoritedRooms[$rid] = (bool) $row['is_favorited'];
                                }
                            }
                        } catch (\Throwable $prefErr) {
                            log_message('error', 'fetchRooms prefs: ' . $prefErr->getMessage());
                        }
                    }
                }

                // ── Unread counts. A message counts as unread for the current
                //    user when it was sent by someone else, is a real
                //    (non-scheduled) delivered message, was not deleted for the
                //    user, and the user's id is not in m_seen_by. This is what
                //    feeds the "Unread" filter chip + per-tile badges so they
                //    stay correct even across cold starts.
                if ($roomIdsCsv !== '') {
                    try {
                        $unreadRows = $db->query(
                            "SELECT m_room_id, COUNT(*) AS cnt FROM messages
                             WHERE m_room_id IN ({$roomIdsCsv})
                               AND m_sender_id <> {$escUserId}
                               AND m_is_scheduled = 0
                               AND m_status <> 'pending'
                               AND COALESCE(m_is_deleted, '0') = '0'
                               AND (m_seen_by IS NULL
                                    OR m_seen_by = ''
                                    OR m_seen_by = '[]'
                                    OR NOT JSON_CONTAINS(m_seen_by, {$seenQuoted}))
                             GROUP BY m_room_id"
                        )->getResultArray();
                        foreach ($unreadRows as $row) {
                            $unreadCounts[(int)$row['m_room_id']] = (int)$row['cnt'];
                        }
                    } catch (\Throwable $unreadErr) {
                        log_message('error', 'fetchRooms unread-count: ' . $unreadErr->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'fetchRooms unread-count: ' . $e->getMessage());
            }

            // Batch-fetch last message for ALL rooms in ONE query
            $lastMessages = [];
            $rows = $messageModel
                ->whereIn('m_room_id', $roomIds)
                ->where('m_is_scheduled', 0)
                ->orderBy('m_created_at', 'DESC')
                ->findAll();

            // Group by room_id, take first (most recent) per room
            $seenRooms = [];
            foreach ($rows as $msg) {
                $rid = $msg['m_room_id'];
                if (!isset($seenRooms[$rid])) {
                    $seenRooms[$rid] = true;
                    $lastMessages[$rid] = $msg;
                }
            }

            // Collect admin IDs for private rooms
            $adminIds = [];
            $roomUserIds = [];
            foreach ($roomData as $room) {
                if ($roomType === '0') {
                    $admins = json_decode($room['r_admins'] ?? '[]', true) ?: [];
                    if (!empty($admins)) {
                        $adminIds[$admins[0]] = true;
                    }
                }
                $users = json_decode($room['r_users'] ?? '[]', true) ?: [];
                foreach ($users as $uid) {
                    $roomUserIds[$uid] = true;
                }
            }

            // Batch-fetch all admin users
            $adminUsers = [];
            if (!empty($adminIds)) {
                $adminRows = $userModel->whereIn('user_id', array_keys($adminIds))->findAll();
                foreach ($adminRows as $a) {
                    $adminUsers[$a['user_id']] = $a;
                }
            }

            $rooms = [];
            foreach ($roomData as $room) {
                $usersWhoDeleted = json_decode($room['r_user_deleted'] ?? '[]', true) ?: [];
                if (in_array($userId, $usersWhoDeleted)) {
                    continue;
                }

                if ($roomType === '0') {
                    $admins = json_decode($room['r_admins'] ?? '[]', true) ?: [];
                    $adminId = $admins[0] ?? null;
                    $room['r_admin_number'] = $adminId ? ($adminUsers[$adminId]['user_mobile_num'] ?? null) : null;
                }

                $lastMsg = $lastMessages[$room['r_id']] ?? null;
                if ($lastMsg) {
                    $deletedFor = json_decode($lastMsg['m_users_who_deleted'] ?? '[]', true) ?: [];
                    $room['r_last_message'] = in_array($userId, $deletedFor) ? null : $lastMsg;
                } else {
                    $room['r_last_message'] = null;
                }

                $unreadCount = $unreadCounts[$room['r_id']] ?? 0;
                if (isset($markedUnreadRooms[$room['r_id']])) {
                    $unreadCount = max(1, $unreadCount);
                }
                $room['unread_count'] = $unreadCount;

                // Per-user prefs read back so archive/favorite survive reload. The
                // keys are OMITTED (never emitted as false) for rooms that have no
                // pref row at all — the client's `Boolean(undefined)` falls back to
                // the same default the toggle's own read-back would produce.
                if (isset($archivedRooms[$room['r_id']])) {
                    $room['is_archived'] = (bool) $archivedRooms[$room['r_id']];
                }
                if (isset($favoritedRooms[$room['r_id']])) {
                    $room['is_favorited'] = (bool) $favoritedRooms[$room['r_id']];
                }

                $rooms[] = $room;
            }

            // Sort by last message update time desc
            usort($rooms, function ($a, $b) {
                $aTime = $a['r_last_message']['m_updated_at'] ?? '0';
                $bTime = $b['r_last_message']['m_updated_at'] ?? '0';
                return $bTime <=> $aTime;
            });

            return $this->response->setJSON([
                "status" => true, "message" => "Rooms Found Successfully", "rooms" => $rooms
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'fetchRooms: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "An error occurred while fetching rooms."
            ]);
        }
    }

    public function getGroupInfo()
    {
        $roomId = $this->request->getGet('room_id');
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getGet('room_id'));
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($roomId) || (int)$roomId <= 0
                || !ctype_digit($userId) || (int)$userId <= 0
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid room or user identifier',
                ]);
            }

            $room = $this->getRoomModel()->find((int)$roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }

            $userIds = array_map('strval', json_decode($room['r_users'], true) ?? []);
            if (!in_array($userId, $userIds, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this room',
                ]);
            }

            $users = [];
            if (!empty($userIds)) {
                $users = $this->getUserModel()->whereIn('user_id', $userIds)->findAll();
            }

            return $this->response->setJSON([
                'status' => true, 'message' => 'Members data fetched',
                'room' => $room, 'members' => $users
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function addUserInGroup()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getVar('room_id'));
            $authenticatedUserId = trim((string)$isToken->user_id);
            $participants = json_decode($this->request->getVar('participants'), true);
            if (!ctype_digit($roomId) || (int)$roomId <= 0
                || !ctype_digit($authenticatedUserId) || (int)$authenticatedUserId <= 0
                || !is_array($participants)
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid room, user, or participants input',
                ]);
            }

            $room = $this->getRoomModel()->find((int)$roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }

            $roomMembers = array_map('strval', json_decode($room['r_users'], true) ?? []);
            $roomAdmins = array_map('strval', json_decode($room['r_admins'] ?? '[]', true) ?? []);
            if (!in_array($authenticatedUserId, $roomAdmins, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'Only group admins can add members',
                ]);
            }

            $participants = array_values(array_unique(array_filter(
                array_map('strval', $participants),
                fn($participant) => trim($participant) !== ''
            )));
            if (!empty($participants)) {
                $newUsers = $this->getUserModel()
                    ->whereIn('user_mobile_num', $participants)
                    ->findAll();
                foreach ($newUsers as $nu) {
                    $roomMembers[] = (string)$nu['user_id'];
                }
            }

            $data = ['r_users' => json_encode(array_values(array_unique($roomMembers)))];
            $success = $this->getRoomModel()->update((int)$roomId, $data);
            $updatedRoom = $this->getRoomModel()->find((int)$roomId);

            $updatedUserIds = json_decode($updatedRoom['r_users'], true) ?? [];
            $members = !empty($updatedUserIds)
                ? $this->getUserModel()->whereIn('user_id', $updatedUserIds)->findAll()
                : [];

            $this->broadcastGroupEvent('group-updated', $updatedUserIds, (string)$updatedRoom['r_id']);

            return $this->response->setJSON([
                'status' => $success,
                'message' => $success ? 'Participants added' : 'Failed to add',
                'room' => $updatedRoom,
                'members' => $members
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function leaveGroup()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getVar('room_id'));
            $authenticatedUserId = trim((string)$isToken->user_id);
            $requestedUserId = $this->request->getVar('user_id');
            $userId = $requestedUserId === null || $requestedUserId === ''
                ? $authenticatedUserId
                : trim((string)$requestedUserId);

            if (!ctype_digit($roomId) || (int)$roomId <= 0
                || !ctype_digit($authenticatedUserId) || (int)$authenticatedUserId <= 0
                || !ctype_digit($userId) || (int)$userId <= 0
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid room or user identifier',
                ]);
            }

            $room = $this->getRoomModel()->find((int)$roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }

            $roomMembers = array_map('strval', json_decode($room['r_users'], true) ?? []);
            $roomAdmins = array_map('strval', json_decode($room['r_admins'], true) ?? []);
            if (!in_array($userId, $roomMembers, true)) {
                return $this->response->setStatusCode(404)->setJSON([
                    'status' => false,
                    'message' => 'User is not a member of this group',
                ]);
            }
            if ($userId !== $authenticatedUserId && !in_array($authenticatedUserId, $roomAdmins, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'Only group admins can remove members',
                ]);
            }

            $updatedMembers = array_values(array_filter(
                $roomMembers,
                fn($id) => $id !== $userId
            ));
            $updatedAdmins = array_values(array_filter(
                $roomAdmins,
                fn($id) => $id !== $userId
            ));

            $roomData = [
                'r_users' => json_encode($updatedMembers),
                'r_admins' => json_encode($updatedAdmins),
            ];

            $this->getRoomModel()->update((int)$roomId, $roomData);
            $updatedRoom = $this->getRoomModel()->find((int)$roomId);

            \Config\Database::connect()->query(
                "UPDATE messages SET m_users_who_deleted = JSON_ARRAY_APPEND(IFNULL(m_users_who_deleted, '[]'), '$', CAST(? AS UNSIGNED)) WHERE m_room_id = ?",
                [$userId, (int)$roomId]
            );

            $updatedUserIds = json_decode($updatedRoom['r_users'], true) ?? [];
            $members = !empty($updatedUserIds)
                ? $this->getUserModel()->whereIn('user_id', $updatedUserIds)->findAll()
                : [];

            // Notify remaining members AND the removed user so the leaver's
            // devices drop the group in real time instead of waiting for a
            // refresh.
            $this->broadcastGroupEvent(
                'group-updated',
                array_merge($updatedUserIds, [$userId]),
                (string)$updatedRoom['r_id']
            );

            return $this->response->setJSON([
                'status' => true, 'message' => 'Successfully removed from group',
                'room' => $updatedRoom, 'members' => $members
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateRoom()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getVar('room_id'));
            $authenticatedUserId = trim((string)$isToken->user_id);
            if (!ctype_digit($roomId) || (int)$roomId <= 0
                || !ctype_digit($authenticatedUserId) || (int)$authenticatedUserId <= 0
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid room or user identifier',
                ]);
            }

            $room = $this->getRoomModel()->find((int)$roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }

            $roomAdmins = array_map('strval', json_decode($room['r_admins'] ?? '[]', true) ?? []);
            if (!in_array($authenticatedUserId, $roomAdmins, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'Only group admins can update this room',
                ]);
            }

            $roomName = $this->request->getVar('room_name');
            if (!empty($roomName)) {
                $room['r_name'] = $roomName;
            }

            $roomImage = $this->request->getFile('group_image');
            if ($roomImage && $roomImage->isValid() && !$roomImage->hasMoved()) {
                try {
                    $uploadService = new \App\Services\MediaUploadService();
                    $stored = $uploadService->store($roomImage, 'img/uploads/profile/groups');
                    $room['r_avatar'] = $stored['web_path'];
                } catch (\Throwable $e) {
                    log_message('error', 'updateRoom group image upload failed: ' . $e->getMessage());
                    return $this->response->setJSON([
                        'status' => false,
                        'message' => 'Group image upload failed. Please try again.',
                    ]);
                }
            }

            $success = $this->getRoomModel()->update($roomId, $room);
            $updatedRoom = $this->getRoomModel()->find($roomId);

            $updatedUserIds = json_decode($updatedRoom['r_users'] ?? '[]', true) ?: [];
            $this->broadcastGroupEvent('group-updated', $updatedUserIds, (string)$updatedRoom['r_id']);

            return $this->response->setJSON([
                'status' => (bool)$success,
                'message' => $success ? 'Group details updated.' : 'Failed to update.',
                'room' => $updatedRoom,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function makeAdmin()
    {
        return $this->toggleAdmin(true);
    }

    public function removeFromAdmin()
    {
        return $this->toggleAdmin(false);
    }

    private function toggleAdmin(bool $add)
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getGet('room_id'));
            $userId = trim((string)$this->request->getGet('user_id'));
            $authenticatedUserId = trim((string)$isToken->user_id);
            if (!ctype_digit($roomId) || (int)$roomId <= 0
                || !ctype_digit($userId) || (int)$userId <= 0
                || !ctype_digit($authenticatedUserId) || (int)$authenticatedUserId <= 0
            ) {
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
            $admins = array_map('strval', json_decode($room['r_admins'] ?? '[]', true) ?? []);
            if (!in_array($authenticatedUserId, $admins, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'Only group admins can change admin roles',
                ]);
            }
            if (!in_array($userId, $members, true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'User is not a member of this group',
                ]);
            }
            if (!$add && count(array_diff($admins, [$userId])) === 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'A group must retain at least one admin',
                ]);
            }

            if ($add) {
                $admins[] = $userId;
                $admins = array_values(array_unique($admins));
            } else {
                $admins = array_values(array_filter($admins, fn($id) => $id !== $userId));
            }

            $this->getRoomModel()->update((int)$roomId, ['r_admins' => json_encode($admins)]);
            $updatedRoom = $this->getRoomModel()->find((int)$roomId);

            $updatedUserIds = json_decode($updatedRoom['r_users'] ?? '[]', true) ?: [];
            $this->broadcastGroupEvent('group-updated', $updatedUserIds, (string)$updatedRoom['r_id']);

            return $this->response->setJSON([
                'status' => true,
                'message' => $add ? 'User made admin' : 'User removed from admin',
                'room' => $updatedRoom
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Best-effort push a group-change event through the socket server's
     * /broadcast endpoint so every affected member refreshes instantly
     * (members added/removed, name/photo/admin changed). Socket failure
     * never fails the API call.
     */
    private function broadcastGroupEvent(string $event, array $targetUserIds, string $roomId): void
    {
        if (empty($targetUserIds)) {
            return;
        }
        try {
            $broadcastUrl = getenv('SOCKET_BROADCAST_URL') ?: 'http://localhost:4000/broadcast';
            $internalKey = getenv('INTERNAL_CRON_KEY');
            if ($internalKey === false || $internalKey === '') {
                log_message('error', 'Socket broadcast is not configured');
                return;
            }
            $payload = json_encode([
                'event' => $event,
                'data' => $roomId,
                'userIds' => array_values(array_unique(array_map('strval', $targetUserIds))),
            ]);
            $ch = curl_init($broadcastUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Internal-Key: ' . $internalKey,
                ],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            log_message('error', 'Group broadcast failed: ' . $e->getMessage());
        }
    }

    public function deleteRoom()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $roomId = trim((string)$this->request->getVar('room_id'));
            $userId = trim((string)$isToken->user_id);

            if (!ctype_digit($roomId) || (int)$roomId <= 0
                || !ctype_digit($userId) || (int)$userId <= 0
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid room or user identifier',
                ]);
            }

            $room = $this->getRoomModel()->find((int)$roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }

            $roomMembers = array_map('strval', json_decode($room['r_users'] ?? '[]', true) ?? []);
            if (!in_array($userId, $roomMembers, true)) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => false,
                    'message' => 'You are not a member of this room',
                ]);
            }

            $usersWhoDeleted = json_decode($room['r_user_deleted'] ?? '[]', true) ?: [];
            $usersWhoDeleted[] = $userId;

            $this->getRoomModel()->update((int)$roomId, [
                'r_user_deleted' => json_encode(array_values(array_unique($usersWhoDeleted))),
            ]);

            \Config\Database::connect()->query(
                "UPDATE messages SET m_users_who_deleted = JSON_ARRAY_APPEND(IFNULL(m_users_who_deleted, '[]'), '$', CAST(? AS UNSIGNED)) WHERE m_room_id = ?",
                [$userId, (int)$roomId]
            );

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Room deleted successfully'
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── Room-level user preferences (archive / favorite / mark-unread) ─────

    private function ensureRoomUserPrefsTable(): void
    {
        $db = \Config\Database::connect();
        $db->query("
            CREATE TABLE IF NOT EXISTS room_user_prefs (
                pref_id INT AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                user_id INT NOT NULL,
                is_archived TINYINT(1) DEFAULT 0,
                is_favorited TINYINT(1) DEFAULT 0,
                marked_unread TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_room_user (room_id, user_id)
            )
        ");
    }

    private function getRoomUserPref(int $roomId, int $userId): array
    {
        $this->ensureRoomUserPrefsTable();
        $db = \Config\Database::connect();
        $row = $db->query(
            "SELECT * FROM room_user_prefs WHERE room_id = ? AND user_id = ?",
            [$roomId, $userId]
        )->getRowArray();
        if (!$row) {
            $db->query(
                "INSERT INTO room_user_prefs (room_id, user_id) VALUES (?, ?)",
                [$roomId, $userId]
            );
            return ['is_archived' => 0, 'is_favorited' => 0, 'marked_unread' => 0];
        }
        return $row;
    }

    public function checkUserByNumber()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed"]);
        }

        $phone = $this->request->getVar('phone') ?? '';
        if (empty($phone)) {
            return $this->response->setJSON(["status" => false, "message" => "Phone number is required"]);
        }

        $user = user();
        $found = $this->getUserModel()
            ->where('user_mobile_num', $phone)
            ->where('u_is_deleted !=', 1)
            ->first();

        if ($found) {
            if ($found['user_id'] == $user['user_id']) {
                return $this->response->setJSON(["status" => false, "message" => "This is your own number"]);
            }
            return $this->response->setJSON([
                "status" => true,
                "message" => "User found",
                "exists" => true,
                "user" => public_user($found),
            ]);
        }

        return $this->response->setJSON([
            "status" => true,
            "message" => "User not registered",
            "exists" => false,
        ]);
    }

    public function createPendingRoom()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed", "room" => null]);
        }

        $phone = $this->request->getPost('phone_number') ?? '';
        if (empty($phone)) {
            return $this->response->setJSON(["status" => false, "message" => "Phone number is required", "room" => null]);
        }

        $user = user();
        $rUniqueId = rand(10000, 999999);

        $roomId = $this->getRoomModel()->insert([
            "r_unique_id" => $rUniqueId,
            "r_name" => $phone,
            "r_type" => 0,
            "r_avatar" => "",
            "r_created_by" => $user['user_id'],
            "r_users" => json_encode([$user['user_id']]),
            "r_admins" => json_encode([$user['user_id']]),
            "r_status" => 1,
            "r_is_pending" => 1,
            "r_pending_phone" => $phone,
        ], true);

        $room = $this->getRoomModel()->find($roomId);

        if ($room) {
            $room['r_pending'] = true;
            $room['r_pending_phone'] = $phone;
        }

        return $this->response->setJSON([
            "status" => true,
            "message" => "Pending conversation created",
            "room" => $room,
        ]);
    }

    public function attachPendingRooms()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authorization Failed"]);
        }

        $user = user();
        // Only the caller's OWN number may claim a pending room. The request
        // `phone` used to be trusted verbatim, so anyone who knew another user's
        // number could join (and then read/write) a pending 1:1 room meant for
        // them. The authenticated number is the only one we can prove.
        $phone = $user['user_mobile_num'] ?? '';
        if (empty($phone)) {
            return $this->response->setJSON(["status" => false, "message" => "Phone number is required"]);
        }

        $roomModel = $this->getRoomModel();
        $pendingRooms = $roomModel
            ->where('r_is_pending', 1)
            ->where('r_pending_phone', $phone)
            ->findAll();

        $attached = [];
        foreach ($pendingRooms as $room) {
            $creatorId = $room['r_created_by'];
            $existingUsers = json_decode($room['r_users'], true) ?? [];
            $existingUsers[] = $user['user_id'];
            $existingUsers = array_values(array_unique($existingUsers));

            $roomModel->update($room['r_id'], [
                'r_users' => json_encode($existingUsers),
                'r_is_pending' => 0,
                'r_pending_phone' => null,
                'r_name' => trim(($user['user_first_name'] ?? '') . ' ' . ($user['user_last_name'] ?? '')),
            ]);

            $updatedRoom = $roomModel->find($room['r_id']);

            try {
                $http = \Config\Services::curlrequest();
                $http->post('http://localhost:4000/broadcast', [
                    'json' => [
                        'event' => 'pending-room-activated',
                        'data' => [
                            'room_id' => $room['r_id'],
                            'user_id' => (string)$creatorId,
                            'new_user_id' => (string)$user['user_id'],
                            'new_user_name' => trim(($user['user_first_name'] ?? '') . ' ' . ($user['user_last_name'] ?? '')),
                            'new_user_phone' => $user['user_mobile_num'],
                        ]
                    ]
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'attachPendingRooms broadcast failed: ' . $e->getMessage());
            }

            $attached[] = $updatedRoom;
        }

        return $this->response->setJSON([
            "status" => true,
            "message" => count($attached) . " pending rooms attached",
            "rooms" => $attached,
        ]);
    }

    public function toggleArchive()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }
        try {
            $roomId = (int)$this->request->getVar('room_id');
            $userId = (int)$isToken->user_id;
            $prefs = $this->getRoomUserPref($roomId, $userId);
            $newVal = $prefs['is_archived'] ? 0 : 1;
            $db = \Config\Database::connect();
            $db->query(
                "UPDATE room_user_prefs SET is_archived = ? WHERE room_id = ? AND user_id = ?",
                [$newVal, $roomId, $userId]
            );
            return $this->response->setJSON([
                'status' => true,
                'message' => $newVal ? 'Room archived' : 'Room unarchived',
                'is_archived' => (bool)$newVal,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function toggleFavorite()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }
        try {
            $roomId = (int)$this->request->getVar('room_id');
            $userId = (int)$isToken->user_id;
            $prefs = $this->getRoomUserPref($roomId, $userId);
            $newVal = $prefs['is_favorited'] ? 0 : 1;
            $db = \Config\Database::connect();
            $db->query(
                "UPDATE room_user_prefs SET is_favorited = ? WHERE room_id = ? AND user_id = ?",
                [$newVal, $roomId, $userId]
            );
            return $this->response->setJSON([
                'status' => true,
                'message' => $newVal ? 'Room favorited' : 'Room unfavorited',
                'is_favorited' => (bool)$newVal,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function toggleMute()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }
        try {
            $roomId = $this->request->getVar('room_id');
            $room = $this->getRoomModel()->find($roomId);
            if (!$room) {
                return $this->response->setJSON(['status' => false, 'message' => 'Room not found']);
            }
            // Only a member may mute/unmute a room. `r_status` is a shared column
            // (it suppresses FCM for every participant), so without this check any
            // authenticated user could silence someone else's conversation.
            $callerId = trim((string)$isToken->user_id);
            $members = array_map('strval', json_decode($room['r_users'] ?? '[]', true) ?? []);
            if (!in_array($callerId, $members, true)) {
                return $this->response->setStatusCode(403)->setJSON(['status' => false, 'message' => 'You are not a member of this room']);
            }
            $newStatus = $room['r_status'] == '1' ? '0' : '1';
            $this->getRoomModel()->update($roomId, ['r_status' => $newStatus]);
            $updatedRoom = $this->getRoomModel()->find($roomId);
            return $this->response->setJSON([
                'status' => true,
                'message' => $newStatus === '1' ? 'Room unmuted' : 'Room muted',
                'r_status' => $newStatus,
                'room' => $updatedRoom,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function markRead()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }
        try {
            $roomId = $this->request->getVar('room_id');
            $userId = $isToken->user_id;
            // Reset unread flag for this user in the room
            $db = \Config\Database::connect();
            $this->ensureRoomUserPrefsTable();
            $db->query(
                "INSERT INTO room_user_prefs (room_id, user_id, marked_unread) VALUES (?, ?, 0)
                 ON DUPLICATE KEY UPDATE marked_unread = 0",
                [(int)$roomId, (int)$userId]
            );

            // Clearing the pref alone is not enough: `fetchRooms` counts unread
            // from `messages.m_seen_by`, so the next list fetch resurrected the
            // badge for every message the caller had not stamped row-by-row.
            // Mobile only stamps the bubbles it rendered, so opening a chat there
            // left older rows counted as unread forever ("why does the old count
            // come back?"). Opening a room means the whole room is read; stamp
            // the caller into every incoming, non-scheduled row in one statement.
            //
            // `m_read_status` is deliberately untouched: it is room-global for
            // groups (3 = every other member read), so a per-member mark-read
            // must not promote it. The clients' per-message seen writes still
            // drive the sender's ticks. Best-effort: JSON functions are
            // MySQL-only and the test suite runs on SQLite, so a failure here
            // must never fail the pref reset above.
            if ((int)$roomId > 0) {
                try {
                    $db->query(
                        "UPDATE messages
                            SET m_seen_by = JSON_ARRAY_APPEND(
                                    CASE
                                        WHEN m_seen_by IS NULL OR m_seen_by = '' THEN '[]'
                                        ELSE m_seen_by
                                    END,
                                    '$', ?)
                          WHERE m_room_id = ?
                            AND m_sender_id <> ?
                            AND m_is_scheduled = 0
                            AND (m_seen_by IS NULL
                                 OR m_seen_by = ''
                                 OR m_seen_by = '[]'
                                 OR (JSON_VALID(m_seen_by)
                                     AND NOT JSON_CONTAINS(m_seen_by, ?)))",
                        [(string)$userId, (int)$roomId, (string)$userId, '"' . $userId . '"']
                    );
                } catch (\Throwable $seenErr) {
                    log_message('error', 'markRead seen-by: ' . $seenErr->getMessage());
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Room marked as read'
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function markUnread()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }
        try {
            $roomId = $this->request->getVar('room_id');
            $userId = $isToken->user_id;
            $db = \Config\Database::connect();
            $this->ensureRoomUserPrefsTable();
            $db->query(
                "INSERT INTO room_user_prefs (room_id, user_id, marked_unread) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE marked_unread = 1",
                [(int)$roomId, (int)$userId]
            );
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Room marked as unread'
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}

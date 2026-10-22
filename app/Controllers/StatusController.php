<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Controllers\NotificationController;
use App\Models\StatusUpdateModel;
use App\Models\StatusViewModel;
use App\Models\StatusReactionModel;
use App\Models\StatusReplyModel;
use App\Models\StatusMentionModel;
use App\Models\StatusMuteModel;
use App\Models\StatusReportModel;
use App\Models\StatusShareModel;
use App\Models\UserAvailabilityModel;
use App\Models\UserStatusSettingModel;
use App\Models\ContactModel;
use App\Models\RoomModel;
use App\Models\UserModel;

class StatusController extends BaseController
{
    private const VALID_PRIVACY = ['everyone', 'contacts', 'contacts_except', 'only_share', 'only_me'];
    private const MAX_STATUSES_PER_DAY = 30;
    private const MAX_CONTENT_LENGTH = 2000;
    private const MAX_REPORT_REASON_LENGTH = 255;
    private const FEED_PAGE_SIZE = 20;

    private ?StatusUpdateModel $statusUpdateModel = null;
    private ?StatusViewModel $statusViewModel = null;
    private ?StatusReactionModel $statusReactionModel = null;
    private ?StatusReplyModel $statusReplyModel = null;
    private ?StatusMentionModel $statusMentionModel = null;
    private ?StatusMuteModel $statusMuteModel = null;
    private ?StatusReportModel $statusReportModel = null;
    private ?StatusShareModel $statusShareModel = null;
    private ?UserAvailabilityModel $userAvailabilityModel = null;
    private ?UserStatusSettingModel $userStatusSettingModel = null;
    private ?ContactModel $contactModel = null;
    private ?RoomModel $roomModel = null;
    private ?UserModel $userModel = null;
    private ?NotificationController $notificationController = null;
    /** All 1:1 room member arrays, parsed once per request (r_users is JSON — LIKE matching is unreliable). */
    private ?array $contactRoomsCache = null;

    private function getStatusUpdateModel(): StatusUpdateModel
    {
        if ($this->statusUpdateModel === null) {
            $this->statusUpdateModel = new StatusUpdateModel();
        }
        return $this->statusUpdateModel;
    }

    private function getStatusViewModel(): StatusViewModel
    {
        if ($this->statusViewModel === null) {
            $this->statusViewModel = new StatusViewModel();
        }
        return $this->statusViewModel;
    }

    private function getStatusReportModel(): StatusReportModel
    {
        if ($this->statusReportModel === null) {
            $this->statusReportModel = new StatusReportModel();
        }
        return $this->statusReportModel;
    }

    private function getStatusShareModel(): StatusShareModel
    {
        if ($this->statusShareModel === null) {
            $this->statusShareModel = new StatusShareModel();
        }
        return $this->statusShareModel;
    }

    private function getUserStatusSettingModel(): UserStatusSettingModel
    {
        if ($this->userStatusSettingModel === null) {
            $this->userStatusSettingModel = new UserStatusSettingModel();
        }
        return $this->userStatusSettingModel;
    }

    private function getContactModel(): ContactModel
    {
        if ($this->contactModel === null) {
            $this->contactModel = new ContactModel();
        }
        return $this->contactModel;
    }

    private function getRoomModel(): RoomModel
    {
        if ($this->roomModel === null) {
            $this->roomModel = new RoomModel();
        }
        return $this->roomModel;
    }

    private function getNotificationController(): NotificationController
    {
        if ($this->notificationController === null) {
            $this->notificationController = new NotificationController();
        }
        return $this->notificationController;
    }

    private function getUserAvailabilityModel(): UserAvailabilityModel
    {
        if ($this->userAvailabilityModel === null) {
            $this->userAvailabilityModel = new UserAvailabilityModel();
        }
        return $this->userAvailabilityModel;
    }

    private function getUserModel(): UserModel
    {
        if ($this->userModel === null) {
            $this->userModel = new UserModel();
        }
        return $this->userModel;
    }

    private function getStatusReactionModel(): StatusReactionModel
    {
        if ($this->statusReactionModel === null) {
            $this->statusReactionModel = new StatusReactionModel();
        }
        return $this->statusReactionModel;
    }

    private function getStatusReplyModel(): StatusReplyModel
    {
        if ($this->statusReplyModel === null) {
            $this->statusReplyModel = new StatusReplyModel();
        }
        return $this->statusReplyModel;
    }

    private function getStatusMentionModel(): StatusMentionModel
    {
        if ($this->statusMentionModel === null) {
            $this->statusMentionModel = new StatusMentionModel();
        }
        return $this->statusMentionModel;
    }

    private function getStatusMuteModel(): StatusMuteModel
    {
        if ($this->statusMuteModel === null) {
            $this->statusMuteModel = new StatusMuteModel();
        }
        return $this->statusMuteModel;
    }

    /**
     * Push a status event to the socket server.
     * $targetUserIds === null  → broadcast to everyone (broadcastable events)
     * $targetUserIds === []    → skip entirely (e.g. only_me audience)
     * otherwise                → only to those user ids (audience-limited)
     */
    private function broadcastStatusEvent(string $event, array $data, ?array $targetUserIds = null): void
    {
        try {
            if ($targetUserIds !== null && empty($targetUserIds)) {
                return;
            }
            $broadcastUrl = getenv('SOCKET_BROADCAST_URL') ?: 'http://localhost:4000/broadcast';
            $internalKey = getenv('INTERNAL_CRON_KEY');
            if ($internalKey === false || $internalKey === '') {
                log_message('error', 'Socket broadcast is not configured');
                return;
            }
            $payload = [
                'event' => $event,
                'data' => $data,
            ];
            if ($targetUserIds !== null) {
                $payload['userIds'] = $targetUserIds;
            }
            $ch = curl_init($broadcastUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Internal-Key: ' . $internalKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            log_message('error', 'Status broadcast failed: ' . $e->getMessage());
        }
    }

    private function getUserStatusSettings(int $userId): array
    {
        $settings = $this->getUserStatusSettingModel()
            ->where('us_user_id', $userId)
            ->first();
        if (!$settings) {
            return [
                'us_user_id' => (string)$userId,
                'us_privacy' => 'everyone',
                'us_except_ids' => '[]',
                'us_share_ids' => '[]',
            ];
        }
        return $settings;
    }

    private function getStatusPrivacy(int $userId): array
    {
        $settings = $this->getUserStatusSettings($userId);
        $privacy = $settings['us_privacy'] ?? 'everyone';
        if (!in_array($privacy, self::VALID_PRIVACY, true)) {
            $privacy = 'everyone';
        }
        return [
            'privacy' => $privacy,
            'except_ids' => $this->decodeIdList($settings['us_except_ids'] ?? ''),
            'share_ids' => $this->decodeIdList($settings['us_share_ids'] ?? ''),
        ];
    }

    private function decodeIdList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw), fn ($id) => $id > 0));
        }
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = explode(',', $raw);
        }
        return array_values(array_filter(array_map('intval', $decoded), fn ($id) => $id > 0));
    }

    private function encodeIdList(array $ids): string
    {
        return json_encode(array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0))));
    }

    private function getMutedUserIds(int $userId): array
    {
        $muted = $this->getStatusMuteModel()
            ->where('smu_user_id', $userId)
            ->findAll();
        return array_map(fn ($m) => (int)$m['smu_muted_user_id'], $muted);
    }

    /**
     * COUNT(*) grouped by a status id column (used to batch view/share counts).
     * Returns [statusId => count]. Fail-safe: missing table → empty map.
     */
    private function countGrouped(string $table, string $column, array $statusIds): array
    {
        if (empty($statusIds)) {
            return [];
        }
        try {
            $rows = \Config\Database::connect()
                ->table($table)
                ->select($column . ' AS sid, COUNT(*) AS cnt')
                ->whereIn($column, $statusIds)
                ->groupBy($column)
                ->get()
                ->getResultArray();
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['sid']] = (int)$r['cnt'];
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Status ids already viewed by $viewerId (batched single query).
     */
    private function viewedStatusIds(int $viewerId, array $statusIds): array
    {
        if (empty($statusIds)) {
            return [];
        }
        try {
            $rows = $this->getStatusViewModel()
                ->select('sv_status_id')
                ->where('sv_viewer_id', $viewerId)
                ->whereIn('sv_status_id', $statusIds)
                ->findAll();
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['sv_status_id']] = true;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Member id arrays of every 1:1 room, parsed once per request.
     * r_users is a JSON array of ids (ints or numeric strings, driver-dependent)
     * so SQL LIKE matching is unreliable — parse in PHP instead.
     */
    private function getContactRooms(): array
    {
        if ($this->contactRoomsCache !== null) {
            return $this->contactRoomsCache;
        }
        $this->contactRoomsCache = [];
        try {
            $rooms = $this->getRoomModel()->where('r_type', 0)->findAll();
            foreach ($rooms as $room) {
                $members = json_decode($room['r_users'] ?? '[]', true);
                if (!is_array($members)) {
                    continue;
                }
                $this->contactRoomsCache[] = array_values(array_unique(array_map('intval', $members)));
            }
        } catch (\Throwable $e) {
            $this->contactRoomsCache = [];
        }
        return $this->contactRoomsCache;
    }

    private function isContactOf(int $viewerId, int $authorId): bool
    {
        if ($viewerId === $authorId) {
            return true;
        }
        // A user is a "contact" if they appear in the viewer's synced contacts
        // by mobile number, or share a private (1:1) room.
        try {
            $author = $this->getUserModel()->find($authorId);
            if (!$author || empty($author['user_mobile_num'])) {
                return false;
            }
            $matched = $this->getContactModel()
                ->where('c_user_id', $viewerId)
                ->where('c_mobile_number', $author['user_mobile_num'])
                ->first();
            if ($matched) {
                return true;
            }
            foreach ($this->getContactRooms() as $members) {
                if (in_array($viewerId, $members, true) && in_array($authorId, $members, true)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getContactUserIds(int $userId): array
    {
        // Users who share a private room or whose number is in the user's contacts
        $ids = [];
        try {
            foreach ($this->getContactRooms() as $members) {
                foreach ($members as $m) {
                    if ($m > 0 && $m !== $userId) {
                        $ids[$m] = true;
                    }
                }
            }
            $contacts = $this->getContactModel()
                ->where('c_user_id', $userId)
                ->findAll();
            foreach ($contacts as $contact) {
                if (empty($contact['c_mobile_number'])) {
                    continue;
                }
                $user = $this->getUserModel()
                    ->where('user_mobile_num', $contact['c_mobile_number'])
                    ->first();
                if ($user) {
                    $ids[(int)$user['user_id']] = true;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'getContactUserIds failed: ' . $e->getMessage());
        }
        return array_keys($ids);
    }

    /**
     * Resolve the audience for a status owned by $authorId:
     * returns list of user ids (ints) that should see it.
     */
    private function resolveAudience(int $authorId, array $privacy): array
    {
        $all = [];
        switch ($privacy['privacy']) {
            case 'only_me':
                return [];
            case 'contacts':
            case 'contacts_except':
                $all = $this->getContactUserIds($authorId);
                break;
            case 'only_share':
                return $privacy['share_ids'];
            case 'everyone':
            default:
                try {
                    $users = $this->getUserModel()
                        ->select('user_id')
                        ->where('u_is_deleted', 0)
                        ->findAll();
                    $all = array_map(fn ($u) => (int)$u['user_id'], $users);
                } catch (\Throwable $e) {
                    return [];
                }
                break;
        }

        if ($privacy['privacy'] === 'contacts_except') {
            $except = array_flip($privacy['except_ids']);
            $all = array_values(array_filter($all, fn ($id) => !isset($except[$id])));
        }

        // Never include the author, always include blocked/muted exclusion on receiver side is
        // handled at read time; audience here is for broadcasts.
        return array_values(array_filter($all, fn ($id) => $id !== $authorId));
    }

    /**
     * True if $viewerId is allowed to see $update (authored by $authorId).
     */
    private function canViewStatus(array $update, int $viewerId): bool
    {
        $authorId = (int)$update['su_user_id'];
        if ($viewerId === $authorId) {
            return true;
        }
        $privacy = $update['su_privacy'] ?? 'everyone';
        if (!in_array($privacy, self::VALID_PRIVACY, true)) {
            $privacy = 'everyone';
        }
        switch ($privacy) {
            case 'only_me':
                return false;
            case 'only_share':
                // Share targets come from the author's current settings (no per-status
                // snapshot column in the schema) — matches /status/privacy behavior.
                $settings = $this->getUserStatusSettings($authorId);
                $ids = $this->decodeIdList($settings['us_share_ids'] ?? '');
                return in_array($viewerId, $ids, true);
            case 'contacts':
                return $this->isContactOf($viewerId, $authorId);
            case 'contacts_except':
                if (!$this->isContactOf($viewerId, $authorId)) {
                    return false;
                }
                $settings = $this->getUserStatusSettings($authorId);
                return !in_array($viewerId, $this->decodeIdList($settings['us_except_ids'] ?? ''), true);
            default:
                return true;
        }
    }

    /**
     * True if a status is still alive (not expired, not archived) so users can
     * interact with it. Returns an error message string when blocked, null when OK.
     */
    private function canInteractWithStatus(array $update): ?string
    {
        if (($update['su_is_archived'] ?? '0') === '1') {
            return 'This status is archived';
        }
        if (empty($update['su_expires_at']) || $update['su_expires_at'] <= date('Y-m-d H:i:s')) {
            return 'This status has expired';
        }
        return null;
    }

    private function deleteStatusChildren(int $statusId): void
    {
        try {
            $this->getStatusViewModel()->where('sv_status_id', $statusId)->delete();
        } catch (\Throwable $e) {
        }
        try {
            $this->getStatusReactionModel()->where('sr_status_id', $statusId)->delete();
        } catch (\Throwable $e) {
        }
        try {
            $this->getStatusReplyModel()->where('srp_status_id', $statusId)->delete();
        } catch (\Throwable $e) {
        }
        try {
            $this->getStatusMentionModel()->where('smn_status_id', $statusId)->delete();
        } catch (\Throwable $e) {
        }
        try {
            $this->getStatusReportModel()->where('srp_status_id', $statusId)->delete();
        } catch (\Throwable $e) {
        }
        try {
            $this->getStatusShareModel()->where('ssh_status_id', $statusId)->delete();
        } catch (\Throwable $e) {
        }
    }

    private function deleteStatusMedia(?string $mediaUrl): void
    {
        if (empty($mediaUrl)) {
            return;
        }
        try {
            $filePath = (new \App\Services\MediaUploadService())->resolvePath($mediaUrl);
            if ($filePath) {
                @unlink($filePath);
            }
        } catch (\Throwable $e) {
        }
    }

    private function notifyStatusOwner(array $update, string $event, string $title, string $body, ?array $actor = null): void
    {
        try {
            $owner = $this->getUserModel()->find($update['su_user_id']);
            if (!$owner || empty($owner['fcm_token'])) {
                return;
            }
            $this->getNotificationController()->sendStatusNotification(
                $owner['fcm_token'],
                $title,
                $body,
                $update['su_id'],
                $actor ? trim(($actor['user_first_name'] ?? '') . ' ' . ($actor['user_last_name'] ?? '')) : '',
                $actor['user_image'] ?? '',
                $event,
                $actor ? (string)($actor['user_id'] ?? '') : ''
            );
        } catch (\Throwable $e) {
            log_message('error', 'Status owner notification failed: ' . $e->getMessage());
        }
    }

    // GET /status/my-update
    public function myUpdate()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $user = $this->getUserModel()->find($userId);
            $availability = $this->getUserAvailabilityModel()
                ->where('ua_user_id', $userId)
                ->orderBy('ua_updated_at', 'DESC')
                ->first();

            $latestUpdate = $this->getStatusUpdateModel()
                ->where('su_user_id', $userId)
                ->where('su_is_archived', '0')
                ->where('su_expires_at >', date('Y-m-d H:i:s'))
                ->orderBy('su_created_at', 'DESC')
                ->first();

            return $this->response->setJSON([
                'status' => true,
                'message' => 'My update fetched successfully',
                'data' => [
                    'user' => $user ? public_user($user, true) : null,
                    'availability' => $availability ?: null,
                    'latest_update' => $latestUpdate ?: null,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/my - all of the caller's live statuses + privacy settings
    public function my()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $user = $this->getUserModel()->find($userId);

            $statuses = $this->getStatusUpdateModel()
                ->where('su_user_id', $userId)
                ->where('su_is_archived', '0')
                ->where('su_expires_at >', date('Y-m-d H:i:s'))
                ->orderBy('su_created_at', 'DESC')
                ->findAll();

            $statusIds = array_map(fn ($s) => (int)$s['su_id'], $statuses);
            $viewCounts = $this->countGrouped('status_views', 'sv_status_id', $statusIds);
            $reactionCounts = $this->countGrouped('status_reactions', 'sr_status_id', $statusIds);
            $replyCounts = $this->countGrouped('status_replies', 'srp_status_id', $statusIds);
            $shareCounts = $this->countGrouped('status_shares', 'ssh_status_id', $statusIds);

            $result = [];
            foreach ($statuses as $status) {
                $statusId = (int)$status['su_id'];
                $status['view_count'] = $viewCounts[$statusId] ?? 0;
                $status['reaction_count'] = $reactionCounts[$statusId] ?? 0;
                $status['reply_count'] = $replyCounts[$statusId] ?? 0;
                $status['share_count'] = $shareCounts[$statusId] ?? 0;
                $result[] = $status;
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'My statuses fetched',
                'data' => [
                    'user' => $user ? public_user($user, true) : null,
                    'statuses' => $result,
                    'privacy' => $this->getStatusPrivacy($userId),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/fetch-updates (alias: /status/feed)
    public function fetchUpdates()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $page = max(1, (int)($this->request->getVar('page') ?: 1));
            $perPage = min(50, max(1, (int)($this->request->getVar('per_page') ?: self::FEED_PAGE_SIZE)));

            $me = $this->getUserModel()->find($userId);
            $blockedList = [];
            if ($me && !empty($me['u_block_list'])) {
                $blockedList = json_decode($me['u_block_list'], true) ?? [];
            }
            $blockedList = array_map('intval', $blockedList);
            $mutedList = $this->getMutedUserIds($userId);

            // Fetch ALL live candidates, then apply blocked/muted/privacy filters in
            // PHP — the SQL LIMIT would otherwise cut pages short after filtering.
            $candidates = $this->getStatusUpdateModel()
                ->where('su_is_archived', '0')
                ->where('su_user_id !=', $userId)
                ->where('su_expires_at >', date('Y-m-d H:i:s'))
                ->orderBy('su_created_at', 'DESC')
                ->findAll();

            $eligible = [];
            $statusIds = [];
            foreach ($candidates as $update) {
                $authorId = (int)$update['su_user_id'];
                if (in_array($authorId, $blockedList, true)) continue;
                if (in_array($authorId, $mutedList, true)) continue;
                if (!$this->canViewStatus($update, $userId)) continue;
                $eligible[] = $update;
                $statusIds[] = (int)$update['su_id'];
            }

            $total = count($eligible);
            $pageItems = array_slice($eligible, ($page - 1) * $perPage, $perPage);

            // Batch lookup: authors, view counts, viewed flags, share counts.
            $authors = [];
            if (!empty($pageItems)) {
                $authorIds = array_values(array_unique(array_map(fn ($u) => (int)$u['su_user_id'], $pageItems)));
                try {
                    $authorRows = $this->getUserModel()->whereIn('user_id', $authorIds)->findAll();
                    foreach ($authorRows as $a) {
                        $authors[(int)$a['user_id']] = $a;
                    }
                } catch (\Throwable $e) {
                    $authors = [];
                }
            }
            $viewCounts = $this->countGrouped('status_views', 'sv_status_id', $statusIds);
            $shareCounts = $this->countGrouped('status_shares', 'ssh_status_id', $statusIds);
            $viewedMap = $this->viewedStatusIds($userId, $statusIds);

            // Current user's own reaction per status — lets the app highlight
            // the ❤️ like button / show the exact emoji the user already sent.
            $myReactions = [];
            if (!empty($statusIds)) {
                try {
                    $reactionRows = $this->getStatusReactionModel()
                        ->where('sr_user_id', $userId)
                        ->whereIn('sr_status_id', $statusIds)
                        ->findAll();
                    foreach ($reactionRows as $rr) {
                        $myReactions[(int)$rr['sr_status_id']] = $rr['sr_reaction'];
                    }
                } catch (\Throwable $e) {
                    $myReactions = [];
                }
            }

            // Current user's own reply per status + reply counts — the app uses
            // `my_reply` to highlight statuses the user already replied to
            // (WhatsApp-style) and `reply_count` to show how many replied.
            $myReplies = [];
            $replyCounts = $this->countGrouped('status_replies', 'srp_status_id', $statusIds);
            if (!empty($statusIds)) {
                try {
                    $replyRows = $this->getStatusReplyModel()
                        ->where('srp_user_id', $userId)
                        ->whereIn('srp_status_id', $statusIds)
                        ->findAll();
                    foreach ($replyRows as $rr) {
                        $myReplies[(int)$rr['srp_status_id']] = true;
                    }
                } catch (\Throwable $e) {
                    $myReplies = [];
                }
            }

            $friendsUpdates = [];
            foreach ($pageItems as $update) {
                $statusId = (int)$update['su_id'];
                $friendsUpdates[] = [
                    'update' => $update,
                    'author' => isset($authors[(int)$update['su_user_id']])
                        ? public_user($authors[(int)$update['su_user_id']])
                        : null,
                    'view_count' => $viewCounts[$statusId] ?? 0,
                    'share_count' => $shareCounts[$statusId] ?? 0,
                    'reply_count' => $replyCounts[$statusId] ?? 0,
                    'viewed' => isset($viewedMap[$statusId]),
                    'my_reaction' => $myReactions[$statusId] ?? null,
                    'my_reply' => isset($myReplies[$statusId]),
                ];
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Updates fetched successfully',
                'data' => $friendsUpdates,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more' => ($page * $perPage) < $total,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/feed - paginated feed (alias with stable contract)
    public function feed()
    {
        return $this->fetchUpdates();
    }

    // POST /status/create
    public function create()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $content = $this->request->getVar('content');
            $mediaType = $this->request->getVar('media_type') ?: 'text';
            $mediaUrl = '';
            $scheduleId = $this->request->getVar('schedule_id');
            $privacy = $this->request->getVar('privacy');
            $bgColor = $this->request->getVar('bg_color');
            $fontStyle = $this->request->getVar('font_style');
            $textColor = $this->request->getVar('text_color');
            $caption = $this->request->getVar('caption');
            $location = $this->request->getVar('location');
            $link = $this->request->getVar('link');
            $mentions = $this->request->getVar('mentions');

            if (!in_array($mediaType, ['text', 'image', 'video', 'voice'], true)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid media_type'
                ]);
            }

            if (mb_strlen($content ?? '') > self::MAX_CONTENT_LENGTH) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Content too long'
                ]);
            }

            // Validate privacy against the user's saved settings
            $settings = $this->getUserStatusSettings($userId);
            $settingsPrivacy = $settings['us_privacy'] ?? 'everyone';
            if (!in_array($settingsPrivacy, self::VALID_PRIVACY, true)) {
                $settingsPrivacy = 'everyone';
            }
            if ($privacy === null) {
                $privacy = $settingsPrivacy;
            }
            if (!in_array($privacy, self::VALID_PRIVACY, true)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid privacy value'
                ]);
            }
            // A user cannot override to a more public setting than configured
            if ($settingsPrivacy !== 'everyone' && $privacy === 'everyone') {
                $privacy = $settingsPrivacy;
            }

            // Rate limit: max statuses per day
            $todayCount = $this->getStatusUpdateModel()
                ->where('su_user_id', $userId)
                ->where('su_created_at >=', date('Y-m-d 00:00:00'))
                ->countAllResults();
            if ($todayCount >= self::MAX_STATUSES_PER_DAY) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Daily status limit reached (' . self::MAX_STATUSES_PER_DAY . ')',
                ]);
            }

            // Handle file upload for media updates
            $mediaFile = $this->request->getFile('media_file');
            if ($mediaFile && $mediaFile->isValid() && !$mediaFile->hasMoved()) {
                $maxSize = 50 * 1024 * 1024;
                if ($mediaFile->getSize() > $maxSize) {
                    return $this->response->setJSON([
                        'status' => false, 'message' => 'File exceeds 50MB limit'
                    ]);
                }
                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'mkv', 'm4a', 'mp3', 'wav', 'ogg'];
                $ext = strtolower($mediaFile->getExtension());
                if (!in_array($ext, $allowedExt, true)) {
                    return $this->response->setJSON([
                        'status' => false, 'message' => 'File type not allowed'
                    ]);
                }
                try {
                    $uploadService = new \App\Services\MediaUploadService();
                    $newName = $mediaFile->getRandomName();
                    $stored = $uploadService->store($mediaFile, 'files/status', $newName);
                    $mediaUrl = $stored['web_path'];
                } catch (\Throwable $e) {
                    log_message('error', 'Status upload failed: ' . $e->getMessage());
                    return $this->response->setJSON([
                        'status' => false, 'message' => 'Media upload failed. Please try again.'
                    ]);
                }
                if (empty($mediaType) || $mediaType === 'text') {
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $mediaType = 'image';
                    } elseif (in_array($ext, ['mp4', 'mov', 'mkv'])) {
                        $mediaType = 'video';
                    } elseif (in_array($ext, ['m4a', 'mp3', 'wav', 'ogg'])) {
                        $mediaType = 'voice';
                    }
                }
            }

            // Determine expiration: if linked to a schedule, expire 1h after schedule time
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            if ($scheduleId) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            }

            $mentionIds = [];
            if ($mentions) {
                $mentionIds = json_decode($mentions, true);
                if (!is_array($mentionIds)) {
                    $mentionIds = [];
                }
                $mentionIds = array_values(array_unique(array_filter(array_map('intval', $mentionIds), fn ($id) => $id > 0)));
            }

            $data = [
                'su_user_id' => $userId,
                'su_content' => $content ?? '',
                'su_media_url' => $mediaUrl,
                'su_media_type' => $mediaType,
                'su_schedule_id' => $scheduleId ?: null,
                'su_created_at' => date('Y-m-d H:i:s'),
                'su_expires_at' => $expiresAt,
                'su_is_archived' => '0',
                'su_privacy' => $privacy,
                'su_bg_color' => $bgColor ?: null,
                'su_font_style' => $fontStyle ?: null,
                'su_text_color' => $textColor ?: null,
                'su_caption' => $caption ?: null,
                'su_location' => $location ?: null,
                'su_link' => $link ?: null,
                'su_mentions' => $mentionIds ? json_encode($mentionIds) : null,
            ];

            if ($this->getStatusUpdateModel()->save($data)) {
                $updateId = $this->getStatusUpdateModel()->getInsertID();
                $saved = $this->getStatusUpdateModel()->find($updateId);

                foreach ($mentionIds as $mentionedId) {
                    if ((int)$mentionedId === (int)$userId) {
                        continue;
                    }
                    try {
                        $this->getStatusMentionModel()->save([
                            'smn_status_id' => $updateId,
                            'smn_user_id' => $userId,
                            'smn_mentioned_user_id' => $mentionedId,
                            'smn_created_at' => date('Y-m-d H:i:s'),
                        ]);
                    } catch (\Throwable $e) {
                        log_message('error', 'Status mention insert failed: ' . $e->getMessage());
                    }
                }

                $audience = $this->resolveAudience((int)$userId, $this->getStatusPrivacy($userId));
                $this->broadcastStatusEvent('status_created', $saved, $audience);

                foreach ($mentionIds as $mentionedId) {
                    if ((int)$mentionedId === (int)$userId) {
                        continue;
                    }
                    try {
                        $mentionedUser = $this->getUserModel()->find($mentionedId);
                        if ($mentionedUser && !empty($mentionedUser['fcm_token'])) {
                            $this->getNotificationController()->sendStatusNotification(
                                $mentionedUser['fcm_token'],
                                'You were mentioned in a status',
                                mb_substr($content ?? '', 0, 120),
                                $updateId,
                                '',
                                '',
                                'status_mention'
                            );
                        }
                    } catch (\Throwable $e) {
                    }
                }

                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Update created successfully',
                    'data' => $saved,
                ]);
            }

            return $this->response->setJSON([
                'status' => false,
                'message' => 'Failed to create update',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Status create failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/edit
    public function edit()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $updateId = $this->request->getVar('update_id');
            $content = $this->request->getVar('content');

            if (mb_strlen($content ?? '') > self::MAX_CONTENT_LENGTH) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Content too long'
                ]);
            }

            $update = $this->getStatusUpdateModel()->find($updateId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Update not found'
                ]);
            }
            if ($update['su_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            $this->getStatusUpdateModel()->update($updateId, [
                'su_content' => $content ?? $update['su_content'],
            ]);

            $saved = $this->getStatusUpdateModel()->find($updateId);
            // Broadcast only to the audience of this status — never leak an edited
            // only_me / limited status to everyone.
            $audience = $this->resolveAudience((int)$userId, $this->getStatusPrivacy((int)$userId));
            $this->broadcastStatusEvent('status_updated', $saved, $audience);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Update edited successfully',
                'data' => $saved,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/view
    public function view()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $statusId = $this->request->getVar('status_id');
            $viewerId = (int)$isToken->user_id;

            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if (!$this->canViewStatus($update, $viewerId)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Not allowed to view this status'
                ]);
            }
            // The author never "views" their own status — don't inflate analytics.
            if ((int)$update['su_user_id'] === $viewerId) {
                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Status viewed',
                ]);
            }
            $blocked = $this->canInteractWithStatus($update);
            if ($blocked !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => $blocked
                ]);
            }

            $existing = $this->getStatusViewModel()
                ->where('sv_status_id', $statusId)
                ->where('sv_viewer_id', $viewerId)
                ->first();

            if ($existing) {
                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Already viewed',
                ]);
            }

            $data = [
                'sv_status_id' => $statusId,
                'sv_viewer_id' => $viewerId,
                'sv_viewed_at' => date('Y-m-d H:i:s'),
            ];

            if ($this->getStatusViewModel()->save($data)) {
                $this->broadcastStatusEvent('status_seen', [
                    'status_id' => $statusId,
                    'viewer_id' => $viewerId,
                    'viewed_at' => $data['sv_viewed_at'],
                ], [(int)$update['su_user_id']]);

                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Status viewed',
                ]);
            }

            return $this->response->setJSON([
                'status' => false,
                'message' => 'Failed to record view',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/viewers/{statusId}
    public function viewers($statusId)
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;

            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            // Viewers are private analytics — only the owner may see them
            if ((int)$update['su_user_id'] !== $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized to view viewers'
                ]);
            }

            $views = $this->getStatusViewModel()
                ->where('sv_status_id', $statusId)
                ->orderBy('sv_viewed_at', 'DESC')
                ->findAll();

            $viewers = [];
            foreach ($views as $view) {
                $viewer = $this->getUserModel()->find($view['sv_viewer_id']);
                if ($viewer) {
                    $reaction = $this->getStatusReactionModel()
                        ->where('sr_status_id', $statusId)
                        ->where('sr_user_id', $view['sv_viewer_id'])
                        ->first();
                    $reply = $this->getStatusReplyModel()
                        ->where('srp_status_id', $statusId)
                        ->where('srp_user_id', $view['sv_viewer_id'])
                        ->first();
                    $viewers[] = array_merge($viewer, [
                        'viewed_at' => $view['sv_viewed_at'],
                        'reaction' => $reaction ? $reaction['sr_reaction'] : null,
                        'replied' => $reply ? (bool)$reply : false,
                    ]);
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Viewers fetched',
                'data' => $viewers,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/change-availability
    public function changeAvailability()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $status = $this->request->getVar('status');

            $validStatuses = ['Available', 'Busy', 'In Meeting', 'Focus Mode', 'Travelling', 'Offline'];
            if (!in_array($status, $validStatuses)) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Invalid availability status',
                ]);
            }

            $existing = $this->getUserAvailabilityModel()
                ->where('ua_user_id', $userId)
                ->first();

            if ($existing) {
                $this->getUserAvailabilityModel()->update($existing['ua_id'], [
                    'ua_status' => $status,
                    'ua_updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $this->getUserAvailabilityModel()->save([
                    'ua_user_id' => $userId,
                    'ua_status' => $status,
                    'ua_updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Availability updated',
                'data' => ['status' => $status],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Audience that could currently see a status row, for archive/delete
     * broadcasts. Best-effort: contacts_except/only_share read the author's
     * *current* settings (no per-status snapshot column). Returns [] when
     * nobody (e.g. only_me) — broadcastStatusEvent skips empty audiences.
     */
    private function statusAudience(array $update): array
    {
        try {
            $authorId = (int)$update['su_user_id'];
            $privacy = $update['su_privacy'] ?? 'everyone';
            if (!in_array($privacy, self::VALID_PRIVACY, true)) {
                $privacy = 'everyone';
            }
            $settings = $this->getUserStatusSettings($authorId);
            return $this->resolveAudience($authorId, [
                'privacy' => $privacy,
                'except_ids' => $this->decodeIdList($settings['us_except_ids'] ?? ''),
                'share_ids' => $this->decodeIdList($settings['us_share_ids'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Public hook for other controllers (e.g. ScheduledMomentController) to
     * push a status_updates row through the same audience-targeted broadcast
     * path used by StatusController endpoints. $row needs su_user_id and
     * su_privacy keys.
     */
    public function broadcastStatusRowEvent(string $event, array $row): void
    {
        $this->broadcastStatusEvent($event, $row, $this->statusAudience($row));
    }

    // POST /status/archive
    public function archive()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $updateId = $this->request->getVar('update_id');
            $archive = $this->request->getVar('archive'); // '1' or '0'

            $update = $this->getStatusUpdateModel()->find($updateId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Update not found'
                ]);
            }
            if ($update['su_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            // Restoring also renews the 24h window, so expired (auto-archived)
            // updates come back to life instead of staying invisible.
            $this->getStatusUpdateModel()->update($updateId, [
                'su_is_archived' => $archive === '1' ? '1' : '0',
                'su_expires_at' => $archive === '1'
                    ? $update['su_expires_at']
                    : date('Y-m-d H:i:s', strtotime('+24 hours')),
            ]);

            $event = $archive === '1' ? 'status_archived' : 'status_restored';
            // Audience-limited: only users who could see the status learn
            // about the change (previously broadcast to everyone).
            $this->broadcastStatusEvent($event, ['status_id' => $updateId, 'user_id' => $userId], $this->statusAudience($update));

            return $this->response->setJSON([
                'status' => true,
                'message' => $archive === '1' ? 'Update archived' : 'Update restored',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/archived
    public function archivedList()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;

            $updates = $this->getStatusUpdateModel()
                ->where('su_user_id', $userId)
                ->where('su_is_archived', '1')
                ->orderBy('su_created_at', 'DESC')
                ->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Archived updates fetched',
                'data' => $updates,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // DELETE /status/delete/{id}
    public function delete($id)
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $update = $this->getStatusUpdateModel()->find($id);

            if (!$update) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Update not found',
                ]);
            }

            if ((int)$update['su_user_id'] !== $userId) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Unauthorized to delete this update',
                ]);
            }

            $this->deleteStatusChildren((int)$id);
            $this->deleteStatusMedia($update['su_media_url']);
            $this->getStatusUpdateModel()->delete($id);

            // Notify everyone who could see the status (best-effort, self excluded)
            $this->broadcastStatusEvent('status_deleted', ['status_id' => $id, 'user_id' => $userId], $this->statusAudience($update));

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Update deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Accepts emoji-only reactions (Unicode pictographic ranges, incl. ZWJ
     * sequences like skin tones/families and variation selectors).
     */
    private function isValidReaction(string $reaction): bool
    {
        if ($reaction === '' || mb_strlen($reaction) > 8) {
            return false;
        }
        return (bool)preg_match(
            '/^[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}\x{00A9}\x{00AE}\x{3030}\x{303D}]+$/u',
            $reaction
        );
    }

    // POST /status/react
    public function react()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $statusId = $this->request->getVar('status_id');
            $reaction = $this->request->getVar('reaction');

            if (!$statusId || !$reaction) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Missing status_id or reaction'
                ]);
            }
            if (!$this->isValidReaction($reaction)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid reaction'
                ]);
            }

            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if (!$this->canViewStatus($update, $userId)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Not allowed to react to this status'
                ]);
            }
            if ((int)$update['su_user_id'] === $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Cannot react to your own status'
                ]);
            }
            $blocked = $this->canInteractWithStatus($update);
            if ($blocked !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => $blocked
                ]);
            }

            $existing = $this->getStatusReactionModel()
                ->where('sr_status_id', $statusId)
                ->where('sr_user_id', $userId)
                ->first();

            $removed = false;
            if ($existing) {
                if ($existing['sr_reaction'] === $reaction) {
                    $this->getStatusReactionModel()->delete($existing['sr_id']);
                    $removed = true;
                    $this->broadcastStatusEvent('status_reaction', [
                        'status_id' => $statusId, 'user_id' => $userId,
                        'reaction' => $reaction, 'action' => 'removed',
                    ], [(int)$update['su_user_id']]);
                } else {
                    $this->getStatusReactionModel()->update($existing['sr_id'], [
                        'sr_reaction' => $reaction,
                    ]);
                }
            } else {
                $this->getStatusReactionModel()->save([
                    'sr_status_id' => $statusId,
                    'sr_user_id' => $userId,
                    'sr_reaction' => $reaction,
                    'sr_created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if (!$removed) {
                $this->broadcastStatusEvent('status_reaction', [
                    'status_id' => $statusId, 'user_id' => $userId,
                    'reaction' => $reaction, 'action' => $existing ? 'changed' : 'added',
                ], [(int)$update['su_user_id']]);

                if ((int)$update['su_user_id'] !== $userId) {
                    $actor = $this->getUserModel()->find($userId);
                    $this->notifyStatusOwner($update, 'status_reacted', 'New reaction on your status', $reaction, $actor);
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => $removed ? 'Reaction removed' : 'Reaction updated',
                'removed' => $removed,
                'action' => $removed
                    ? 'removed'
                    : ($existing ? 'changed' : 'added'),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/reactions/{statusId}
    public function reactions($statusId)
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if ((int)$update['su_user_id'] !== $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized to view reactions'
                ]);
            }

            $all = $this->getStatusReactionModel()
                ->where('sr_status_id', $statusId)
                ->findAll();

            $grouped = [];
            $users = [];
            foreach ($all as $r) {
                $emoji = $r['sr_reaction'];
                if (!isset($grouped[$emoji])) {
                    $grouped[$emoji] = ['count' => 0, 'users' => []];
                }
                $grouped[$emoji]['count']++;
                $viewer = $this->getUserModel()->find($r['sr_user_id']);
                if ($viewer) {
                    $grouped[$emoji]['users'][] = $viewer;
                    $users[] = $viewer;
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Reactions fetched',
                'data' => [
                    'reactions' => $grouped,
                    'users' => $users,
                    'total' => count($all),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/reply
    public function reply()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $statusId = $this->request->getVar('status_id');
            $message = $this->request->getVar('message');

            if (!$statusId || !$message) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Missing status_id or message'
                ]);
            }
            if (mb_strlen($message) > self::MAX_CONTENT_LENGTH) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Reply too long'
                ]);
            }

            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if (!$this->canViewStatus($update, $userId)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Not allowed to reply to this status'
                ]);
            }
            $blocked = $this->canInteractWithStatus($update);
            if ($blocked !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => $blocked
                ]);
            }

            $data = [
                'srp_status_id' => $statusId,
                'srp_user_id' => $userId,
                'srp_message' => $message,
                'srp_created_at' => date('Y-m-d H:i:s'),
            ];

            if ($this->getStatusReplyModel()->save($data)) {
                $replyId = $this->getStatusReplyModel()->getInsertID();
                $saved = $this->getStatusReplyModel()->find($replyId);

                $this->broadcastStatusEvent('status_reply', $saved, [(int)$update['su_user_id']]);

                if ((int)$update['su_user_id'] !== $userId) {
                    $actor = $this->getUserModel()->find($userId);
                    $this->notifyStatusOwner($update, 'status_replied', 'New reply on your status', mb_substr($message, 0, 120), $actor);
                }

                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Reply sent',
                    'data' => $saved,
                ]);
            }

            return $this->response->setJSON([
                'status' => false, 'message' => 'Failed to reply',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/replies/{statusId}
    public function replies($statusId)
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if ((int)$update['su_user_id'] !== $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized to view replies'
                ]);
            }

            $all = $this->getStatusReplyModel()
                ->where('srp_status_id', $statusId)
                ->orderBy('srp_created_at', 'ASC')
                ->findAll();

            $result = [];
            foreach ($all as $r) {
                $author = $this->getUserModel()->find($r['srp_user_id']);
                $result[] = [
                    'reply' => $r,
                    'author' => $author ? public_user($author) : null,
                ];
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Replies fetched',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/share
    public function share()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $statusId = $this->request->getVar('status_id');

            if (!$statusId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Missing status_id'
                ]);
            }

            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if (!$this->canViewStatus($update, $userId)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Not allowed to share this status'
                ]);
            }
            $blocked = $this->canInteractWithStatus($update);
            if ($blocked !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => $blocked
                ]);
            }

            $existing = $this->getStatusShareModel()
                ->where('ssh_status_id', $statusId)
                ->where('ssh_user_id', $userId)
                ->first();

            if ($existing) {
                $this->getStatusShareModel()->update($existing['ssh_id'], [
                    'ssh_shared_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $this->getStatusShareModel()->save([
                    'ssh_status_id' => $statusId,
                    'ssh_user_id' => $userId,
                    'ssh_shared_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $shareCount = $this->getStatusShareModel()
                ->where('ssh_status_id', $statusId)
                ->countAllResults();

            $this->broadcastStatusEvent('status_shared', [
                'status_id' => $statusId,
                'user_id' => $userId,
                'share_count' => $shareCount,
            ], [(int)$update['su_user_id']]);

            if ((int)$update['su_user_id'] !== $userId) {
                $this->notifyStatusOwner($update, 'status_shared', 'Status shared', 'Your status was shared');
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Status shared',
                'data' => ['share_count' => $shareCount],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/shares/{statusId}
    public function shares($statusId)
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if ((int)$update['su_user_id'] !== $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized to view shares'
                ]);
            }

            $all = $this->getStatusShareModel()
                ->where('ssh_status_id', $statusId)
                ->orderBy('ssh_shared_at', 'ASC')
                ->findAll();

            $users = [];
            foreach ($all as $s) {
                $viewer = $this->getUserModel()->find($s['ssh_user_id']);
                if ($viewer) {
                    $viewer['shared_at'] = $s['ssh_shared_at'];
                    $users[] = $viewer;
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Shares fetched',
                'data' => [
                    'share_count' => count($users),
                    'users' => $users,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/mute
    public function mute()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $mutedUserId = $this->request->getVar('muted_user_id');

            if (!$mutedUserId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Missing muted_user_id'
                ]);
            }
            if ((int)$mutedUserId === (int)$userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Cannot mute yourself'
                ]);
            }

            $existing = $this->getStatusMuteModel()
                ->where('smu_user_id', $userId)
                ->where('smu_muted_user_id', $mutedUserId)
                ->first();

            if ($existing) {
                $this->getStatusMuteModel()->delete($existing['smu_id']);
                return $this->response->setJSON([
                    'status' => true, 'message' => 'User unmuted',
                ]);
            }

            $this->getStatusMuteModel()->save([
                'smu_user_id' => $userId,
                'smu_muted_user_id' => $mutedUserId,
                'smu_created_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->response->setJSON([
                'status' => true, 'message' => 'User muted',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/muted
    public function mutedList()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;
            $muted = $this->getStatusMuteModel()
                ->where('smu_user_id', $userId)
                ->findAll();

            $users = [];
            foreach ($muted as $m) {
                $user = $this->getUserModel()->find($m['smu_muted_user_id']);
                if ($user) {
                    $users[] = $user;
                }
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Muted users fetched',
                'data' => $users,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/search
    public function search()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $keyword = $this->request->getVar('keyword');
            $dateFrom = $this->request->getVar('date_from');
            $dateTo = $this->request->getVar('date_to');
            $mediaType = $this->request->getVar('media_type');
            $page = max(1, (int)($this->request->getVar('page') ?: 1));
            $perPage = min(50, max(1, (int)($this->request->getVar('per_page') ?: 20)));

            $me = $this->getUserModel()->find($userId);
            $blockedList = [];
            if ($me && !empty($me['u_block_list'])) {
                $blockedList = json_decode($me['u_block_list'], true) ?? [];
            }
            $blockedList = array_map('intval', $blockedList);
            $mutedList = $this->getMutedUserIds($userId);

            $builder = $this->getStatusUpdateModel();
            $builder->where('su_is_archived', '0')
                ->where('su_expires_at >', date('Y-m-d H:i:s'));

            if ($keyword) {
                $builder->like('su_content', $keyword);
            }
            if ($dateFrom) {
                $builder->where('su_created_at >=', $dateFrom);
            }
            if ($dateTo) {
                $builder->where('su_created_at <=', $dateTo);
            }
            if ($mediaType) {
                $builder->where('su_media_type', $mediaType);
            }

            // Same as fetchUpdates: filter blocked/muted/privacy in PHP, then paginate.
            $candidates = $builder->orderBy('su_created_at', 'DESC')->findAll();
            $eligible = [];
            foreach ($candidates as $r) {
                $authorId = (int)$r['su_user_id'];
                if (in_array($authorId, $blockedList, true)) continue;
                if (in_array($authorId, $mutedList, true)) continue;
                if ($authorId === $userId) continue;
                if (!$this->canViewStatus($r, $userId)) continue;
                $eligible[] = $r;
            }

            $total = count($eligible);
            $pageItems = array_slice($eligible, ($page - 1) * $perPage, $perPage);

            $authors = [];
            if (!empty($pageItems)) {
                $authorIds = array_values(array_unique(array_map(fn ($u) => (int)$u['su_user_id'], $pageItems)));
                try {
                    $authorRows = $this->getUserModel()->whereIn('user_id', $authorIds)->findAll();
                    foreach ($authorRows as $a) {
                        $authors[(int)$a['user_id']] = $a;
                    }
                } catch (\Throwable $e) {
                    $authors = [];
                }
            }

            $data = [];
            foreach ($pageItems as $r) {
                $data[] = [
                    'update' => $r,
                    'author' => isset($authors[(int)$r['su_user_id']])
                        ? public_user($authors[(int)$r['su_user_id']])
                        : null,
                ];
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Search results',
                'data' => [
                    'results' => $data,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/analytics
    public function analytics()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = $isToken->user_id;

            $myUpdates = $this->getStatusUpdateModel()
                ->where('su_user_id', $userId)
                ->where('su_is_archived', '0')
                ->orderBy('su_created_at', 'DESC')
                ->findAll();

            $statusIds = array_map(fn ($s) => (int)$s['su_id'], $myUpdates);
            $viewCounts = $this->countGrouped('status_views', 'sv_status_id', $statusIds);
            $reactionCounts = $this->countGrouped('status_reactions', 'sr_status_id', $statusIds);
            $replyCounts = $this->countGrouped('status_replies', 'srp_status_id', $statusIds);
            $shareCounts = $this->countGrouped('status_shares', 'ssh_status_id', $statusIds);

            $totalUpdates = count($myUpdates);
            $totalViews = 0;
            $totalReactions = 0;
            $totalReplies = 0;
            $totalShares = 0;
            $updateAnalytics = [];

            foreach ($myUpdates as $upd) {
                $views = $viewCounts[(int)$upd['su_id']] ?? 0;
                $reactions = $reactionCounts[(int)$upd['su_id']] ?? 0;
                $replies = $replyCounts[(int)$upd['su_id']] ?? 0;
                $shares = $shareCounts[(int)$upd['su_id']] ?? 0;

                $totalViews += $views;
                $totalReactions += $reactions;
                $totalReplies += $replies;
                $totalShares += $shares;

                $updateAnalytics[] = [
                    'status_id' => $upd['su_id'],
                    'content' => $upd['su_content'],
                    'media_type' => $upd['su_media_type'],
                    'created_at' => $upd['su_created_at'],
                    'views' => $views,
                    'reactions' => $reactions,
                    'replies' => $replies,
                    'shares' => $shares,
                ];
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Analytics fetched',
                'data' => [
                    'total_updates' => $totalUpdates,
                    'total_views' => $totalViews,
                    'total_reactions' => $totalReactions,
                    'total_replies' => $totalReplies,
                    'total_shares' => $totalShares,
                    'reach' => $totalViews,
                    'completion_rate' => $totalUpdates > 0
                        ? min(100, round(($totalViews / ($totalUpdates * 10)) * 100, 1))
                        : 0,
                    'updates' => $updateAnalytics,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/upload
    public function upload()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $file = $this->request->getFile('file');
            if (!$file || !$file->isValid() || $file->hasMoved()) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid file'
                ]);
            }

            $maxSize = 50 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'File exceeds 50MB limit'
                ]);
            }

            try {
                $uploadService = new \App\Services\MediaUploadService();
                $stored = $uploadService->store($file, 'files/status');
            } catch (\Throwable $e) {
                log_message('error', 'Status upload failed: ' . $e->getMessage());
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Media upload failed. Please try again.'
                ]);
            }

            $ext = strtolower($file->getExtension());
            $mediaType = 'text';
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $mediaType = 'image';
            } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv'])) {
                $mediaType = 'video';
            } elseif (in_array($ext, ['m4a', 'mp3', 'wav', 'ogg', 'aac'])) {
                $mediaType = 'voice';
            }

            $url = $stored['web_path'];

            return $this->response->setJSON([
                'status' => true,
                'message' => 'File uploaded',
                'data' => [
                    'url' => $url,
                    'media_type' => $mediaType,
                    'name' => $file->getName(),
                    'size' => $file->getSize(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // GET /status/privacy - current privacy settings
    public function privacy()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $privacy = $this->getStatusPrivacy($userId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Privacy settings fetched',
                'data' => $privacy,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/save-privacy - persist privacy settings
    public function savePrivacy()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $privacy = $this->request->getVar('privacy');
            $exceptIds = $this->request->getVar('except_ids');
            $shareIds = $this->request->getVar('share_ids');

            if (!in_array($privacy, self::VALID_PRIVACY, true)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid privacy value'
                ]);
            }

            $exceptList = $this->decodeIdList($exceptIds ?: '[]');
            $shareList = $this->decodeIdList($shareIds ?: '[]');

            if ($privacy === 'contacts_except') {
                foreach ($exceptList as $id) {
                    if (!is_numeric($id) || $id <= 0) {
                        return $this->response->setJSON([
                            'status' => false, 'message' => 'Invalid except_ids'
                        ]);
                    }
                }
            }
            if ($privacy === 'only_share') {
                if (empty($shareList)) {
                    return $this->response->setJSON([
                        'status' => false, 'message' => 'Select at least one user to share with'
                    ]);
                }
            }

            $existing = $this->getUserStatusSettingModel()
                ->where('us_user_id', $userId)
                ->first();

            $data = [
                'us_privacy' => $privacy,
                'us_except_ids' => $this->encodeIdList($exceptList),
                'us_share_ids' => $this->encodeIdList($shareList),
                'us_updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($existing) {
                $this->getUserStatusSettingModel()->update($existing['us_id'], $data);
            } else {
                $data['us_user_id'] = $userId;
                $this->getUserStatusSettingModel()->save($data);
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Privacy settings saved',
                'data' => $this->getStatusPrivacy($userId),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/report - report an abusive status
    public function report()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ]);
        }

        try {
            $userId = (int)$isToken->user_id;
            $statusId = $this->request->getVar('status_id');
            $reason = $this->request->getVar('reason');

            if (!$statusId || !$reason) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Missing status_id or reason'
                ]);
            }
            if (mb_strlen($reason) > self::MAX_REPORT_REASON_LENGTH) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Reason too long'
                ]);
            }

            $update = $this->getStatusUpdateModel()->find($statusId);
            if (!$update) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Status not found'
                ]);
            }
            if ((int)$update['su_user_id'] === $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Cannot report your own status'
                ]);
            }

            $existing = $this->getStatusReportModel()
                ->where('srp_status_id', $statusId)
                ->where('srp_reporter_id', $userId)
                ->first();
            if ($existing) {
                return $this->response->setJSON([
                    'status' => true, 'message' => 'Status already reported'
                ]);
            }

            $this->getStatusReportModel()->save([
                'srp_status_id' => $statusId,
                'srp_reporter_id' => $userId,
                'srp_reason' => $reason,
                'srp_created_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Status reported',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // POST /status/cleanup-expired - purge expired statuses (called by cron)
    public function cleanupExpired()
    {
        $isToken = check_jwt_authentication();
        $internalKey = $this->request->getHeaderLine('X-Internal-Key');
        $expectedKey = getenv('INTERNAL_CRON_KEY');
        if ($expectedKey === false || $expectedKey === '') {
            log_message('error', 'Status cleanup is not configured');
            return $this->response->setStatusCode(503)->setJSON([
                'status' => false,
                'message' => 'Status cleanup is unavailable',
            ]);
        }
        if (!$isToken && ($internalKey === '' || !hash_equals($expectedKey, $internalKey))) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => false,
                'message' => 'Authorization Failed',
            ]);
        }

        try {
            $expired = $this->getStatusUpdateModel()
                ->where('su_expires_at <=', date('Y-m-d H:i:s'))
                ->where('su_is_archived', '0')
                ->findAll();

            $count = 0;
            foreach ($expired as $update) {
                // WhatsApp-style: expired updates move to Archive instead of
                // being hard-deleted, so the owner can restore (renews 24h)
                // or permanently delete them from the Archive screen.
                $this->getStatusUpdateModel()->update($update['su_id'], [
                    'su_is_archived' => '1',
                ]);

                $this->broadcastStatusEvent('status_archived', [
                    'status_id' => $update['su_id'],
                    'user_id' => $update['su_user_id'],
                ], [(int)$update['su_user_id']]);

                // Tell everyone who saw it (plus the author) to drop it from
                // their feed now.
                $audience = [(int)$update['su_user_id']];
                try {
                    $viewers = $this->getStatusViewModel()
                        ->select('sv_viewer_id')
                        ->where('sv_status_id', $update['su_id'])
                        ->findAll();
                    foreach ($viewers as $v) {
                        $audience[] = (int)$v['sv_viewer_id'];
                    }
                } catch (\Throwable $e) {
                }
                $audience = array_values(array_unique($audience));

                $this->broadcastStatusEvent('status_expired', [
                    'status_id' => $update['su_id'],
                    'user_id' => $update['su_user_id'],
                ], $audience);
                $count++;
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Expired statuses archived',
                'data' => ['archived' => $count],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage(),
            ]);
        }
    }
}

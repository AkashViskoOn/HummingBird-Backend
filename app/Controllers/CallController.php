<?php

namespace App\Controllers;

use App\Controllers\BaseController;

helper('token');

class CallController extends BaseController
{
    public function initCall()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();
        $targetUserId = $this->request->getPost('target_user_id');
        $callType = $this->request->getPost('call_type');
        $callSessionId = $this->request->getPost('call_session_id');

        if (!$targetUserId || !$callType) {
            return $this->response->setJSON([
                "status" => false, "message" => "Missing required fields"
            ]);
        }

        try {
            $db = \Config\Database::connect();
            $blocked = $db->table('call_blocking')
                ->where('blocked_by', $targetUserId)
                ->where('blocked_user', $user['user_id'])
                ->get()
                ->getRow();

            if ($blocked) {
                return $this->response->setJSON([
                    "status" => false, "message" => "Call blocked by recipient"
                ]);
            }

            if (!$callSessionId) {
                $callSessionId = uniqid('call_', true);
            }

            $db->table('call_history')->insert([
                'call_session_id' => $callSessionId,
                'caller_id' => $user['user_id'],
                'receiver_id' => $targetUserId,
                'call_type' => $callType,
                'call_status' => 'initiated',
                'started_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->response->setJSON([
                "status" => true,
                "message" => "Call initiated",
                "data" => [
                    'call_session_id' => $callSessionId,
                    'caller_id' => (string)$user['user_id'],
                    'receiver_id' => (string)$targetUserId,
                    'call_type' => $callType,
                    'room_name' => "call_$callSessionId"
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] initCall: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to initiate call"
            ]);
        }
    }

    public function answerCall()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $callSessionId = $this->request->getPost('call_session_id');
        $answer = $this->request->getPost('answer');

        if (!$callSessionId || !$answer) {
            return $this->response->setJSON([
                "status" => false, "message" => "Missing required fields"
            ]);
        }

        try {
            $db = \Config\Database::connect();
            $db->table('call_history')
                ->where('call_session_id', $callSessionId)
                ->update([
                    'call_status' => $answer === 'accepted' ? 'connected' : 'rejected',
                    'answered_at' => $answer === 'accepted' ? date('Y-m-d H:i:s') : null
                ]);

            return $this->response->setJSON([
                "status" => true,
                "message" => $answer === 'accepted' ? "Call answered" : "Call rejected",
                "data" => ['call_session_id' => $callSessionId, 'status' => $answer]
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] answerCall: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to answer call"
            ]);
        }
    }

    public function endCall()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $callSessionId = $this->request->getPost('call_session_id');
        $duration = $this->request->getPost('duration') ?? 0;
        $callStatus = $this->request->getPost('call_status') ?? 'ended';

        if (!$callSessionId) {
            return $this->response->setJSON([
                "status" => false, "message" => "Missing call_session_id"
            ]);
        }

        try {
            $db = \Config\Database::connect();
            $db->table('call_history')
                ->where('call_session_id', $callSessionId)
                ->update([
                    'call_status' => $callStatus,
                    'duration' => (int)$duration,
                    'ended_at' => date('Y-m-d H:i:s')
                ]);

            return $this->response->setJSON([
                "status" => true,
                "message" => "Call ended",
                "data" => ['call_session_id' => $callSessionId]
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] endCall: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to end call"
            ]);
        }
    }

    public function getCallHistory()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();
        $page = $this->request->getGet('page') ?? 1;
        $limit = 20;
        $offset = ((int)$page - 1) * $limit;

        try {
            $db = \Config\Database::connect();
            $results = $db->query(
                "SELECT ch.*,
                        CASE WHEN ch.caller_id = ? THEN ch.receiver_id ELSE ch.caller_id END as other_user_id
                 FROM call_history ch
                 WHERE ch.caller_id = ? OR ch.receiver_id = ?
                 ORDER BY ch.started_at DESC
                 LIMIT ? OFFSET ?",
                [$user['user_id'], $user['user_id'], $user['user_id'], $limit, $offset]
            )->getResultArray();

            $userIds = [];
            foreach ($results as $row) {
                $otherId = $row['other_user_id'];
                if ($otherId) $userIds[] = $otherId;
            }

            $userNames = [];
            $userImages = [];
            if (!empty($userIds)) {
                $userIds = array_values(array_unique(array_map('intval', $userIds)));
                $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                $users = $db->query(
                    "SELECT user_id, user_first_name, user_last_name, user_image FROM users WHERE user_id IN ($userPlaceholders)",
                    $userIds
                )->getResultArray();
                foreach ($users as $u) {
                    $userNames[$u['user_id']] = trim(($u['user_first_name'] ?? '') . ' ' . ($u['user_last_name'] ?? ''));
                    $userImages[$u['user_id']] = $u['user_image'] ?? '';
                }
            }

            foreach ($results as &$row) {
                $otherId = $row['other_user_id'];
                $row['other_user_name'] = $userNames[$otherId] ?? 'Unknown';
                $row['other_user_image'] = $userImages[$otherId] ?? '';
                $row['is_incoming'] = $row['receiver_id'] == $user['user_id'] ? '1' : '0';
                $row['caller_id'] = (string)$row['caller_id'];
                $row['receiver_id'] = (string)$row['receiver_id'];
            }

            return $this->response->setJSON([
                "status" => true,
                "data" => $results
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] getCallHistory: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to get call history"
            ]);
        }
    }

    public function deleteCallHistory()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();
        $callSessionId = $this->request->getPost('call_session_id')
            ?? $this->request->getGet('call_session_id');

        try {
            $db = \Config\Database::connect();
            if ($callSessionId) {
                $db->table('call_history')
                    ->where('call_session_id', $callSessionId)
                    ->groupStart()
                    ->where('caller_id', $user['user_id'])
                    ->orWhere('receiver_id', $user['user_id'])
                    ->groupEnd()
                    ->delete();
            } else {
                $db->table('call_history')
                    ->where('caller_id', $user['user_id'])
                    ->orWhere('receiver_id', $user['user_id'])
                    ->delete();
            }

            return $this->response->setJSON([
                "status" => true, "message" => "Call history deleted"
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] deleteCallHistory: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to delete history"
            ]);
        }
    }

    public function toggleFavorite()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();
        $contactUserId = $this->request->getPost('contact_user_id');
        $action = $this->request->getPost('action');

        if (!$contactUserId || !$action) {
            return $this->response->setJSON([
                "status" => false, "message" => "Missing required fields"
            ]);
        }

        try {
            $db = \Config\Database::connect();

            if ($action === 'add') {
                if (!$this->tableExists($db, 'favorite_contacts')) {
                    $db->query("CREATE TABLE IF NOT EXISTS favorite_contacts (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        contact_user_id INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_fav (user_id, contact_user_id)
                    )");
                }

                $db->table('favorite_contacts')->insert([
                    'user_id' => $user['user_id'],
                    'contact_user_id' => $contactUserId
                ]);
                $message = 'Contact added to favorites';
            } else {
                $db->table('favorite_contacts')
                    ->where('user_id', $user['user_id'])
                    ->where('contact_user_id', $contactUserId)
                    ->delete();
                $message = 'Contact removed from favorites';
            }

            return $this->response->setJSON([
                "status" => true, "message" => $message
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] toggleFavorite: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to update favorites"
            ]);
        }
    }

    public function getFavorites()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();

        try {
            $db = \Config\Database::connect();

            if (!$this->tableExists($db, 'favorite_contacts')) {
                return $this->response->setJSON(["status" => true, "data" => []]);
            }

            $favorites = $db->query(
                "SELECT fc.*, u.user_first_name, u.user_last_name, u.user_image
                 FROM favorite_contacts fc
                 JOIN users u ON fc.contact_user_id = u.user_id
                 WHERE fc.user_id = ?
                 ORDER BY u.user_first_name ASC",
                [$user['user_id']]
            )->getResultArray();

            foreach ($favorites as &$fav) {
                $fav['contact_user_id'] = (string)$fav['contact_user_id'];
                $fav['contact_name'] = trim(($fav['user_first_name'] ?? '') . ' ' . ($fav['user_last_name'] ?? ''));
                $fav['contact_image'] = $fav['user_image'] ?? '';
            }

            return $this->response->setJSON(["status" => true, "data" => $favorites]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] getFavorites: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to get favorites"
            ]);
        }
    }

    public function blockUser()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();
        $blockUserId = $this->request->getPost('block_user_id');
        $action = $this->request->getPost('action');

        if (!$blockUserId || !$action) {
            return $this->response->setJSON([
                "status" => false, "message" => "Missing required fields"
            ]);
        }

        try {
            $db = \Config\Database::connect();

            if (!$this->tableExists($db, 'call_blocking')) {
                $db->query("CREATE TABLE IF NOT EXISTS call_blocking (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    blocked_by INT NOT NULL,
                    blocked_user INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_block (blocked_by, blocked_user)
                )");
            }

            if ($action === 'block') {
                $db->table('call_blocking')->insert([
                    'blocked_by' => $user['user_id'],
                    'blocked_user' => $blockUserId
                ]);
                $message = 'User blocked';
            } else {
                $db->table('call_blocking')
                    ->where('blocked_by', $user['user_id'])
                    ->where('blocked_user', $blockUserId)
                    ->delete();
                $message = 'User unblocked';
            }

            return $this->response->setJSON([
                "status" => true, "message" => $message
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] blockUser: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to update block list"
            ]);
        }
    }

    public function getBlockedUsers()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();

        try {
            $db = \Config\Database::connect();

            if (!$this->tableExists($db, 'call_blocking')) {
                return $this->response->setJSON(["status" => true, "data" => []]);
            }

            $blocked = $db->query(
                "SELECT cb.*, u.user_first_name, u.user_last_name
                 FROM call_blocking cb
                 JOIN users u ON cb.blocked_user = u.user_id
                 WHERE cb.blocked_by = ?",
                [$user['user_id']]
            )->getResultArray();

            foreach ($blocked as &$b) {
                $b['blocked_user'] = (string)$b['blocked_user'];
                $b['id'] = (string)$b['id'];
                $b['user_name'] = trim(($b['user_first_name'] ?? '') . ' ' . ($b['user_last_name'] ?? ''));
            }

            return $this->response->setJSON(["status" => true, "data" => $blocked]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] getBlockedUsers: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to get blocked users"
            ]);
        }
    }

    public function getSpeedDial()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $user = user();

        try {
            $db = \Config\Database::connect();

            $speedDial = $db->query(
                "SELECT
                    CASE WHEN caller_id = ? THEN receiver_id ELSE caller_id END as contact_id,
                    COUNT(*) as call_count,
                    MAX(started_at) as last_call
                 FROM call_history
                 WHERE (caller_id = ? OR receiver_id = ?)
                    AND call_status = 'connected'
                 GROUP BY contact_id
                 ORDER BY call_count DESC, last_call DESC
                 LIMIT 5",
                [$user['user_id'], $user['user_id'], $user['user_id']]
            )->getResultArray();

            $contactIds = array_column($speedDial, 'contact_id');
            $userNames = [];
            if (!empty($contactIds)) {
                $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
                $contactPlaceholders = implode(',', array_fill(0, count($contactIds), '?'));
                $users = $db->query(
                    "SELECT user_id, user_first_name, user_image FROM users WHERE user_id IN ($contactPlaceholders)",
                    $contactIds
                )->getResultArray();
                foreach ($users as $u) {
                    $userNames[$u['user_id']] = $u;
                }
            }

            foreach ($speedDial as &$sd) {
                $uid = $sd['contact_id'];
                $sd['contact_id'] = (string)$uid;
                $sd['contact_name'] = $userNames[$uid]['user_first_name'] ?? 'Unknown';
                $sd['contact_image'] = $userNames[$uid]['user_image'] ?? '';
            }

            return $this->response->setJSON(["status" => true, "data" => $speedDial]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] getSpeedDial: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to get speed dial"
            ]);
        }
    }

    public function saveCallRecording()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Unauthorized"
            ]);
        }

        $file = $this->request->getFile('recording_file');
        $callSessionId = $this->request->getPost('call_session_id');

        if (!$file || !$file->isValid() || !$callSessionId) {
            return $this->response->setJSON([
                "status" => false, "message" => "Missing recording file or session ID"
            ]);
        }

        try {
            $newName = 'recording_' . $callSessionId . '.mp3';
            $uploadService = new \App\Services\MediaUploadService();
            $stored = $uploadService->storeToWritable($file, 'recordings', $newName);

            $db = \Config\Database::connect();
            $db->table('call_history')
                ->where('call_session_id', $callSessionId)
                ->update(['recording_url' => 'uploads/recordings/' . $stored['name']]);

            return $this->response->setJSON([
                "status" => true,
                "message" => "Recording saved",
                "data" => ['url' => base_url('uploads/recordings/' . $stored['name'])]
            ]);
        } catch (\Exception $e) {
            log_message('error', '[CallController] saveCallRecording: ' . $e->getMessage());
            return $this->response->setJSON([
                "status" => false, "message" => "Failed to save recording"
            ]);
        }
    }

    private function tableExists($db, string $tableName): bool
    {
        return $db->tableExists($tableName);
    }
}

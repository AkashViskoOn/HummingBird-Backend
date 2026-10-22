<?php

namespace App\Controllers;

use App\Models\MessageModel;
use App\Models\RoomModel;
use CodeIgniter\RESTful\ResourceController;

class SearchController extends ResourceController
{
    public function searchMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        $query = $this->request->getVar('message');
        if (empty($query)) {
            return $this->response->setJSON(['status' => false, 'message' => 'Search query is required']);
        }

        $userId = $isToken->user_id;
        $limit = min((int)($this->request->getVar('limit') ?? 50), 200);
        $offset = max((int)($this->request->getVar('offset') ?? 0), 0);

        $messageModel = new MessageModel();
        $roomModel = new RoomModel();
        $encryptionHelper = new \App\Helpers\EncryptionService();

        // Search by encrypted query directly via DB (exact match only - limits N+1 decrypt)
        // For substring search, we need to decrypt and match in PHP
        $allMessages = $messageModel
            ->groupStart()
                ->where('m_sender_id', $userId)
                ->orWhere('m_receiver_id', $userId)
            ->groupEnd()
            ->orderBy('m_epoch_time', 'DESC')
            ->findAll();

        $matched = [];
        $matchedCount = 0;

        foreach ($allMessages as $message) {
            if ($matchedCount >= $offset + $limit) {
                break;
            }

            try {
                $decryptedMessage = $encryptionHelper->decryptMessage($message['m_message']);
            } catch (\Exception $e) {
                continue;
            }

            if (stripos($decryptedMessage, $query) === false) {
                continue;
            }

            $matchedCount++;

            if ($matchedCount <= $offset) {
                continue;
            }

            // Verify room access
            $room = $roomModel->find($message['m_room_id']);
            if (!$room) continue;

            if ($room['r_type'] == '1') {
                $roomUsers = json_decode($room['r_users'] ?? '[]', true) ?: [];
                if (!in_array($userId, $roomUsers)) continue;
            }

            $message['decrypted_content'] = $decryptedMessage;
            $message['m_sender_name'] = '';
            $matched[] = $message;
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => count($matched) . ' matching messages found',
            'total_matched' => $matchedCount,
            'data' => $matched,
        ]);
    }
}

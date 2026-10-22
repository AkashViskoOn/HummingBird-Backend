<?php

namespace App\Controllers;

use App\Models\ScheduledMessagesModel;
use CodeIgniter\RESTful\ResourceController;

class ScheduledMessagesController extends ResourceController
{
    private function getAuthenticatedUserId(): ?string
    {
        $isToken = check_jwt_authentication();
        if (!$isToken || !isset($isToken->user_id)) {
            return null;
        }

        $userId = trim((string)$isToken->user_id);
        return ctype_digit($userId) && (int)$userId > 0 ? $userId : null;
    }

    private function isPositiveInteger($value): bool
    {
        return is_scalar($value)
            && preg_match('/^[1-9][0-9]*$/', (string)$value) === 1;
    }

    public function getSchedulingData()
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => false,
                'message' => 'Authorization Failed',
            ]);
        }

        try {
            $data = (new ScheduledMessagesModel())
                ->where('user_id', $userId)
                ->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => count($data) . ' Scheduled Messages found.',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function saveSchedulingData()
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => false,
                'message' => 'Authorization Failed',
            ]);
        }

        $messageId = $this->request->getVar('messageId');
        $cronExpression = trim((string)$this->request->getVar('cron_expression'));
        $mCount = $this->request->getVar('m_count');
        $mInterval = $this->request->getVar('m_interval');

        if (!$this->isPositiveInteger($messageId)
            || $cronExpression === ''
            || strlen($cronExpression) > 100
            || preg_match('/[\x00-\x1F\x7F]/', $cronExpression) === 1
            || !is_numeric($mCount)
            || (int)$mCount < -1
            || !is_numeric($mInterval)
            || (int)$mInterval < 0
        ) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => false,
                'message' => 'Invalid scheduling data',
            ]);
        }

        try {
            $inserted = (new ScheduledMessagesModel())->insert([
                'user_id' => $userId,
                'm_id' => (int)$messageId,
                'cronexpression' => $cronExpression,
                's_m_count' => (int)$mCount,
                's_m_interval' => (int)$mInterval,
            ]);

            return $this->response->setJSON([
                'status' => (bool)$inserted,
                'message' => $inserted
                    ? 'Scheduling data saved successfully'
                    : 'Failed to save scheduling data.',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function deleteSchedulingData()
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId === null) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => false,
                'message' => 'Authorization Failed',
            ]);
        }

        $messageId = $this->request->getVar('messageId');
        if (!$this->isPositiveInteger($messageId)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => false,
                'message' => 'Invalid scheduling data',
            ]);
        }

        try {
            $deleted = (new ScheduledMessagesModel())
                ->where('user_id', $userId)
                ->where('s_m_id', (int)$messageId)
                ->delete();

            return $this->response->setJSON([
                'status' => (bool)$deleted,
                'message' => $deleted
                    ? 'Scheduling data deleted successfully.'
                    : 'Failed to delete scheduling data.',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

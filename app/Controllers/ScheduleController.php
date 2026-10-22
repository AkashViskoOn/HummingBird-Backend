<?php

namespace App\Controllers;

use App\Models\MessageModel;
use App\Models\ScheduledMessagesModel;
use App\Models\ScheduleTemplateModel;
use App\Models\ScheduleDraftModel;

helper('token');
helper('user');

class ScheduleController extends BaseController
{
    private $categories = ['birthday', 'anniversary', 'reminder', 'promotional', 'meeting', 'follow-up', 'greeting', 'emergency', 'custom'];

    public function getScheduleCategories()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Categories found',
                'data' => $this->categories,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'getScheduleCategories: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveScheduleTemplate()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $templateModel = new ScheduleTemplateModel();

            $data = [
                'user_id' => $user['user_id'],
                'name' => $this->request->getVar('name'),
                'message' => $this->request->getVar('message'),
                'message_type' => $this->request->getVar('message_type') ?? '0',
                'interval' => $this->request->getVar('interval') ?? '0',
                'category' => $this->request->getVar('category') ?? 'custom',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($templateModel->insert($data)) {
                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Template saved successfully',
                    'data' => $templateModel->getInsertID(),
                ]);
            }

            return $this->response->setJSON(['status' => false, 'message' => 'Failed to save template']);
        } catch (\Throwable $e) {
            log_message('error', 'saveScheduleTemplate: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getScheduleTemplates()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $templateModel = new ScheduleTemplateModel();

            $templates = $templateModel->where('user_id', $user['user_id'])->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => count($templates) . ' templates found',
                'data' => $templates,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'getScheduleTemplates: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteScheduleTemplate()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $templateId = $this->request->getVar('template_id');

            if (!$templateId) {
                return $this->response->setJSON(['status' => false, 'message' => 'template_id is required']);
            }

            $templateModel = new ScheduleTemplateModel();
            $template = $templateModel->where(['template_id' => $templateId, 'user_id' => $user['user_id']])->first();

            if (!$template) {
                return $this->response->setJSON(['status' => false, 'message' => 'Template not found']);
            }

            $templateModel->delete($templateId);

            return $this->response->setJSON(['status' => true, 'message' => 'Template deleted successfully']);
        } catch (\Throwable $e) {
            log_message('error', 'deleteScheduleTemplate: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getUpcomingSchedules()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $messageModel = new MessageModel();
            $now = time();

            $messages = $messageModel
                ->where('m_sender_id', $user['user_id'])
                ->where('m_is_scheduled', 1)
                ->where('m_scheduled_at >', $now)
                ->where('m_scheduled_at !=', '0')
                ->orderBy('m_scheduled_at', 'ASC')
                ->limit(20)
                ->findAll();

            $data = array_map(function ($msg) {
                $msg['m_status'] = $msg['m_status'] ?? 'pending';
                return $msg;
            }, $messages);

            return $this->response->setJSON([
                'status' => true,
                'message' => count($data) . ' upcoming schedules found',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'getUpcomingSchedules: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveDraftScheduledMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $draftModel = new ScheduleDraftModel();

            $data = [
                'user_id' => $user['user_id'],
                'message' => $this->request->getVar('message'),
                'message_type' => $this->request->getVar('message_type') ?? '0',
                'room_id' => $this->request->getVar('room_id'),
                'scheduled_at' => $this->request->getVar('scheduled_at') ?? '0',
                'interval' => $this->request->getVar('interval') ?? '0',
                'count' => $this->request->getVar('count') ?? '0',
                'category' => $this->request->getVar('category') ?? 'custom',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($draftModel->insert($data)) {
                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Draft saved successfully',
                    'data' => $draftModel->getInsertID(),
                ]);
            }

            return $this->response->setJSON(['status' => false, 'message' => 'Failed to save draft']);
        } catch (\Throwable $e) {
            log_message('error', 'saveDraftScheduledMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getDraftScheduledMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $draftModel = new ScheduleDraftModel();

            $drafts = $draftModel
                ->where('user_id', $user['user_id'])
                ->orderBy('created_at', 'DESC')
                ->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => count($drafts) . ' drafts found',
                'data' => $drafts,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'getDraftScheduledMessages: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteDraftScheduledMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $draftId = $this->request->getVar('draft_id');

            if (!$draftId) {
                return $this->response->setJSON(['status' => false, 'message' => 'draft_id is required']);
            }

            $draftModel = new ScheduleDraftModel();
            $draft = $draftModel->where(['draft_id' => $draftId, 'user_id' => $user['user_id']])->first();

            if (!$draft) {
                return $this->response->setJSON(['status' => false, 'message' => 'Draft not found']);
            }

            $draftModel->delete($draftId);

            return $this->response->setJSON(['status' => true, 'message' => 'Draft deleted successfully']);
        } catch (\Throwable $e) {
            log_message('error', 'deleteDraftScheduledMessage: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function markScheduleFailed()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $messageId = $this->request->getVar('m_id');

            if (!$messageId) {
                return $this->response->setJSON(['status' => false, 'message' => 'm_id is required']);
            }

            $messageModel = new MessageModel();
            $message = $messageModel->where(['m_id' => $messageId, 'm_sender_id' => $user['user_id']])->first();

            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }

            $messageModel->update($messageId, ['m_status' => 'failed']);

            return $this->response->setJSON(['status' => true, 'message' => 'Message marked as failed']);
        } catch (\Throwable $e) {
            log_message('error', 'markScheduleFailed: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function retryFailedSchedule()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $messageId = $this->request->getVar('m_id');

            if (!$messageId) {
                return $this->response->setJSON(['status' => false, 'message' => 'm_id is required']);
            }

            $messageModel = new MessageModel();
            $message = $messageModel->where(['m_id' => $messageId, 'm_sender_id' => $user['user_id']])->first();

            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }

            $messageModel->update($messageId, [
                'm_status' => 'pending',
                'm_is_scheduled' => 1,
            ]);

            return $this->response->setJSON(['status' => true, 'message' => 'Message queued for retry']);
        } catch (\Throwable $e) {
            log_message('error', 'retryFailedSchedule: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function searchScheduledMessages()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            $messageModel = new MessageModel();

            $keyword = $this->request->getVar('keyword');
            $category = $this->request->getVar('category');
            $status = $this->request->getVar('status');
            $dateFrom = $this->request->getVar('date_from');
            $dateTo = $this->request->getVar('date_to');

            $messageModel->where('m_sender_id', $user['user_id']);
            $messageModel->where('m_is_scheduled', 1);

            if (!empty($keyword)) {
                $messageModel->like('m_message', $keyword);
            }

            if (!empty($category)) {
                $messageModel->where('m_interval', $category);
            }

            if (!empty($status)) {
                $messageModel->where('m_status', $status);
            }

            if (!empty($dateFrom)) {
                $messageModel->where('m_scheduled_at >=', strtotime($dateFrom));
            }

            if (!empty($dateTo)) {
                $messageModel->where('m_scheduled_at <=', strtotime($dateTo));
            }

            $results = $messageModel->orderBy('m_scheduled_at', 'ASC')->findAll();

            return $this->response->setJSON([
                'status' => true,
                'message' => count($results) . ' results found',
                'data' => $results,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'searchScheduledMessages: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function parseNaturalSchedule()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $text = $this->request->getVar('text');

            if (empty($text)) {
                return $this->response->setJSON(['status' => false, 'message' => 'text is required']);
            }

            $text = strtolower(trim($text));
            $now = time();
            $time = null;
            $date = null;
            $message = $text;
            $category = 'reminder';

            // Extract time patterns (e.g., "3pm", "3:00pm", "15:00", "at 3")
            if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $text, $matches)) {
                $hour = (int)$matches[1];
                $minute = isset($matches[2]) ? (int)$matches[2] : 0;
                $ampm = strtolower($matches[3]);

                if ($ampm === 'pm' && $hour < 12) $hour += 12;
                if ($ampm === 'am' && $hour === 12) $hour = 0;

                $time = sprintf('%02d:%02d', $hour, $minute);
            }

            // Extract date patterns
            if (preg_match('/\b(tomorrow|today|next\s+\w+)\b/i', $text, $matches)) {
                $dayRef = strtolower($matches[1]);
                if ($dayRef === 'tomorrow') {
                    $date = date('Y-m-d', strtotime('+1 day'));
                } elseif ($dayRef === 'today') {
                    $date = date('Y-m-d');
                } elseif (strpos($dayRef, 'next ') === 0) {
                    $dayName = substr($dayRef, 5);
                    $date = date('Y-m-d', strtotime('next ' . $dayName));
                }
            }

            // Extract category hints
            if (preg_match('/\b(birthday|anniversary|meeting|greeting|reminder|follow.?up|promotional|emergency)\b/i', $text, $catMatch)) {
                $category = strtolower($catMatch[1]);
            }

            // Extract message content (remove time/date keywords)
            $cleanText = preg_replace('/\b(remind\s+(me\s+)?(to\s+)?|tomorrow|today|at\s+\d[\d:]*\s*(am|pm)?|next\s+\w+)\b/i', '', $text);
            $cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));
            if (!empty($cleanText)) {
                $message = $cleanText;
            }

            $parsed = [
                'time' => $time,
                'date' => $date,
                'message' => $message,
                'category' => $category,
                'original' => $text,
            ];

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Text parsed successfully',
                'data' => $parsed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'parseNaturalSchedule: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Controllers;

use App\Services\AiService;
use CodeIgniter\RESTful\ResourceController;

helper('token');

class AIController extends ResourceController
{
    private AiService $aiService;

    public function __construct()
    {
        $this->aiService = new AiService();
    }

    private function jsonResponse(bool $status, string $message, $data = null)
    {
        $response = ['status' => $status, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        return $this->response->setJSON($response);
    }

    public function getAiMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $prompt = trim($input['prompt'] ?? '');
            if (empty($prompt)) {
                return $this->jsonResponse(false, 'Prompt is required');
            }

            $result = $this->aiService->getAiMessage($prompt);
            if ($result === null) {
                log_message('error', '[AIController::getAiMessage] Gemini returned null');
                return $this->jsonResponse(false, 'AI service unavailable');
            }

            return $this->jsonResponse(true, $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getAiMessage] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function generateMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $prompt = trim($input['prompt'] ?? '');

            if (empty($prompt)) {
                return $this->jsonResponse(false, 'Prompt is required');
            }

            $result = $this->aiService->generateText($prompt);
            if ($result === null) {
                log_message('error', '[AIController::generateMessage] AI service returned null', ['prompt' => $prompt]);
                return $this->jsonResponse(false, 'AI service is temporarily unavailable. Please try again.');
            }

            return $this->jsonResponse(true, 'Message generated', ['text' => $result]);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::generateMessage] ' . $th->getMessage());
            // Never leak raw backend/API errors to the client — log them and
            // send a generic message so the UI can show something friendly.
            return $this->jsonResponse(false, 'AI service is temporarily unavailable. Please try again.');
        }
    }

    public function getReplySuggestions()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');
            $conversationHistory = $input['conversation_history'] ?? [];

            if (empty($message)) {
                return $this->jsonResponse(false, 'Message is required');
            }

            $cache = $this->getCache();
            $cacheKey = 'reply_suggestions_' . md5($message . serialize($conversationHistory));
            $cached = $cache->get($cacheKey);
            if ($cached) {
                return $this->jsonResponse(true, 'Reply suggestions (cached)', $cached);
            }

            $suggestions = $this->aiService->getReplySuggestions($message, $conversationHistory);
            if ($suggestions === null) {
                return $this->jsonResponse(false, 'Failed to generate suggestions');
            }

            $suggestions = array_slice($suggestions, 0, 5);
            $cache->save($cacheKey, $suggestions, 300);

            return $this->jsonResponse(true, 'Reply suggestions generated', $suggestions);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getReplySuggestions] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function categorizeMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Message is required');
            }

            $result = $this->aiService->categorizeMessage($message);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to categorize message');
            }

            return $this->jsonResponse(true, 'Message categorized', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::categorizeMessage] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function generateSummary()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $messages = $input['messages'] ?? [];
            $maxLength = (int)($input['max_length'] ?? 100);

            if (empty($messages)) {
                return $this->jsonResponse(false, 'Messages array is required');
            }

            $result = $this->aiService->generateSummary($messages, $maxLength);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to generate summary');
            }

            return $this->jsonResponse(true, 'Summary generated', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::generateSummary] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function analyzeSentiment()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Message is required');
            }

            $result = $this->aiService->analyzeSentiment($message);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to analyze sentiment');
            }

            return $this->jsonResponse(true, 'Sentiment analyzed', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::analyzeSentiment] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function smartSearch()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $query = trim($input['query'] ?? '');
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($userId) || (int)$userId <= 0) {
                return $this->jsonResponse(false, 'Invalid user identifier');
            }

            if (empty($query)) {
                return $this->jsonResponse(false, 'Query is required');
            }

            $result = $this->aiService->smartSearch($query, $userId);
            if ($result === null) {
                return $this->jsonResponse(false, 'Search failed');
            }

            return $this->jsonResponse(true, 'Search completed', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::smartSearch] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function translateMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');
            $targetLanguage = trim($input['target_language'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Message is required');
            }
            if (empty($targetLanguage)) {
                return $this->jsonResponse(false, 'Target language is required');
            }

            $result = $this->aiService->translateMessage($message, $targetLanguage);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to translate message');
            }

            return $this->jsonResponse(true, 'Message translated', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::translateMessage] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function detectSpam()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');
            $senderId = trim((string)$isToken->user_id);
            if (!ctype_digit($senderId) || (int)$senderId <= 0) {
                return $this->jsonResponse(false, 'Invalid user identifier');
            }

            if (empty($message)) {
                return $this->jsonResponse(false, 'Message is required');
            }

            $result = $this->aiService->detectSpam($message, $senderId);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to check spam');
            }

            return $this->jsonResponse(true, 'Spam check completed', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::detectSpam] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function getChatbotResponse()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Message is required');
            }

            $result = $this->aiService->getChatbotResponse($message);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to generate response');
            }

            return $this->jsonResponse(true, 'Chatbot response generated', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getChatbotResponse] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    public function getNotificationGroup()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Authorization Failed');
        }

        try {
            $input = $this->request->getJSON(true);
            $notifications = $input['notifications'] ?? [];

            if (empty($notifications)) {
                return $this->jsonResponse(false, 'Notifications array is required');
            }

            $result = $this->aiService->getNotificationGroup($notifications);
            if ($result === null) {
                return $this->jsonResponse(false, 'Failed to group notifications');
            }

            return $this->jsonResponse(true, 'Notifications grouped successfully', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getNotificationGroup] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Internal Server Error');
        }
    }

    private function getCache()
    {
        return \Config\Services::cache();
    }
}

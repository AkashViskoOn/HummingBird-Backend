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
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $prompt = trim($input['prompt'] ?? '');
            if (empty($prompt)) {
                return $this->jsonResponse(false, 'Please enter a message for the AI assistant.');
            }

            $result = $this->aiService->getAiMessage($prompt);
            if ($result === null) {
                log_message('error', '[AIController::getAiMessage] Gemini returned null');
                return $this->jsonResponse(false, 'The AI assistant could not reply. Please try again.');
            }

            return $this->jsonResponse(true, $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getAiMessage] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The AI assistant could not reply. Please try again.');
        }
    }

    public function generateMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $prompt = trim($input['prompt'] ?? '');

            if (empty($prompt)) {
                return $this->jsonResponse(false, 'Please enter a prompt to generate a message.');
            }

            $result = $this->aiService->generateText($prompt);
            if ($result === null) {
                log_message('error', '[AIController::generateMessage] AI service returned null', ['prompt' => $prompt]);
                return $this->jsonResponse(false, 'Message generation failed. Please try again.');
            }

            return $this->jsonResponse(true, 'Message generated', ['text' => $result]);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::generateMessage] ' . $th->getMessage());
            // Never leak raw backend/API errors to the client — log them and
            // send a generic message so the UI can show something friendly.
            return $this->jsonResponse(false, 'Message generation failed. Please try again.');
        }
    }

    public function getReplySuggestions()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');
            $conversationHistory = $input['conversation_history'] ?? [];

            if (empty($message)) {
                return $this->jsonResponse(false, 'Please enter a message to get reply suggestions.');
            }

            $cache = $this->getCache();
            $cacheKey = 'reply_suggestions_' . md5($message . serialize($conversationHistory));
            $cached = $cache->get($cacheKey);
            if ($cached) {
                return $this->jsonResponse(true, 'Reply suggestions (cached)', $cached);
            }

            $suggestions = $this->aiService->getReplySuggestions($message, $conversationHistory);
            if ($suggestions === null) {
                return $this->jsonResponse(false, 'Reply suggestions could not be generated. Please try again.');
            }

            $suggestions = array_slice($suggestions, 0, 5);
            $cache->save($cacheKey, $suggestions, 300);

            return $this->jsonResponse(true, 'Reply suggestions generated', $suggestions);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getReplySuggestions] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Reply suggestions could not be generated. Please try again.');
        }
    }

    public function categorizeMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Please enter a message to categorize.');
            }

            $result = $this->aiService->categorizeMessage($message);
            if ($result === null) {
                return $this->jsonResponse(false, 'The message could not be categorized. Please try again.');
            }

            return $this->jsonResponse(true, 'Message categorized', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::categorizeMessage] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The message could not be categorized. Please try again.');
        }
    }

    public function generateSummary()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $messages = $input['messages'] ?? [];
            $maxLength = (int)($input['max_length'] ?? 100);

            if (empty($messages)) {
                return $this->jsonResponse(false, 'Please provide messages to summarize.');
            }

            $result = $this->aiService->generateSummary($messages, $maxLength);
            if ($result === null) {
                return $this->jsonResponse(false, 'The summary could not be generated. Please try again.');
            }

            return $this->jsonResponse(true, 'Summary generated', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::generateSummary] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The summary could not be generated. Please try again.');
        }
    }

    public function analyzeSentiment()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Please enter a message to analyze.');
            }

            $result = $this->aiService->analyzeSentiment($message);
            if ($result === null) {
                return $this->jsonResponse(false, 'The sentiment could not be analyzed. Please try again.');
            }

            return $this->jsonResponse(true, 'Sentiment analyzed', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::analyzeSentiment] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The sentiment could not be analyzed. Please try again.');
        }
    }

    public function smartSearch()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $query = trim($input['query'] ?? '');
            $userId = trim((string)$isToken->user_id);
            if (!ctype_digit($userId) || (int)$userId <= 0) {
                return $this->jsonResponse(false, 'Your session is invalid. Please sign in again.');
            }

            if (empty($query)) {
                return $this->jsonResponse(false, 'Please enter a search query.');
            }

            $result = $this->aiService->smartSearch($query, $userId);
            if ($result === null) {
                return $this->jsonResponse(false, 'Search could not be completed. Please try again.');
            }

            return $this->jsonResponse(true, 'Search completed', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::smartSearch] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Search could not be completed. Please try again.');
        }
    }

    public function translateMessage()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');
            $targetLanguage = trim($input['target_language'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Please enter a message to translate.');
            }
            if (empty($targetLanguage)) {
                return $this->jsonResponse(false, 'Please choose a language to translate into.');
            }

            $result = $this->aiService->translateMessage($message, $targetLanguage);
            if ($result === null) {
                return $this->jsonResponse(false, 'The message could not be translated. Please try again.');
            }

            return $this->jsonResponse(true, 'Message translated', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::translateMessage] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The message could not be translated. Please try again.');
        }
    }

    public function detectSpam()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');
            $senderId = trim((string)$isToken->user_id);
            if (!ctype_digit($senderId) || (int)$senderId <= 0) {
                return $this->jsonResponse(false, 'Your session is invalid. Please sign in again.');
            }

            if (empty($message)) {
                return $this->jsonResponse(false, 'Please enter a message to check.');
            }

            $result = $this->aiService->detectSpam($message, $senderId);
            if ($result === null) {
                return $this->jsonResponse(false, 'The spam check could not be completed. Please try again.');
            }

            return $this->jsonResponse(true, 'Spam check completed', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::detectSpam] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The spam check could not be completed. Please try again.');
        }
    }

    public function getChatbotResponse()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $message = trim($input['message'] ?? '');

            if (empty($message)) {
                return $this->jsonResponse(false, 'Please enter a message.');
            }

            $result = $this->aiService->getChatbotResponse($message);
            if ($result === null) {
                return $this->jsonResponse(false, 'The AI assistant could not respond. Please try again.');
            }

            return $this->jsonResponse(true, 'Chatbot response generated', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getChatbotResponse] ' . $th->getMessage());
            return $this->jsonResponse(false, 'The AI assistant could not respond. Please try again.');
        }
    }

    public function getNotificationGroup()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->jsonResponse(false, 'Your session has expired. Please sign in again.');
        }

        try {
            $input = $this->request->getJSON(true);
            $notifications = $input['notifications'] ?? [];

            if (empty($notifications)) {
                return $this->jsonResponse(false, 'Please provide notifications to group.');
            }

            $result = $this->aiService->getNotificationGroup($notifications);
            if ($result === null) {
                return $this->jsonResponse(false, 'Notifications could not be grouped. Please try again.');
            }

            return $this->jsonResponse(true, 'Notifications grouped successfully', $result);
        } catch (\Throwable $th) {
            log_message('error', '[AIController::getNotificationGroup] ' . $th->getMessage());
            return $this->jsonResponse(false, 'Notifications could not be grouped. Please try again.');
        }
    }

    private function getCache()
    {
        return \Config\Services::cache();
    }
}

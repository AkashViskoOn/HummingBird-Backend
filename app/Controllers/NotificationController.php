<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;

class NotificationController extends BaseController
{
    private static ?string $cachedToken = null;
    private static int $tokenExpiry = 0;

    /**
     * Status-scoped FCM notification.
     * $event: 'view' | 'react' | 'reply' | 'status_expired'
     */
    public function sendStatusNotification($fcmToken, $title, $body, $statusId, $actorName, $actorImage, $event, $senderId = '')
    {
        if (empty($fcmToken)) {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            log_message('error', 'FCM: Failed to obtain access token');
            return false;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => [
                    'type' => 'status',
                    'title' => $title,
                    'body' => $body,
                    'statusId' => (string)$statusId,
                    'actorName' => (string)$actorName,
                    'actorImage' => (string)$actorImage,
                    'event' => (string)$event,
                    'senderId' => (string)$senderId,
                ],
            ],
        ];

        $ch = curl_init('https://fcm.googleapis.com/v1/projects/humming-bird-ed916/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($statusCode !== 200) {
            log_message('error', "FCM status send failed [{$statusCode}]: " . ($error ?: $response));
            if ($statusCode === 401 || $statusCode === 403) {
                self::$cachedToken = null;
            }
            return false;
        }

        return true;
    }

    public function sendNotification($fcmToken, $title, $body, $senderId, $roomId, $userNumber, $isGroup, $userProfile, $userBio, $groupName)
    {        if (empty($fcmToken)) {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            log_message('error', 'FCM: Failed to obtain access token');
            return false;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => [
                    'title' => $title,
                    'body' => $body,
                    'senderId' => (string)$senderId,
                    'roomId' => (string)$roomId,
                    'senderNumber' => (string)$userNumber,
                    'isGroup' => (string)$isGroup,
                    'profile_pic' => (string)$userProfile,
                    'bio' => (string)$userBio,
                    'groupName' => (string)$groupName,
                ],
            ],
        ];

        $ch = curl_init('https://fcm.googleapis.com/v1/projects/humming-bird-ed916/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($statusCode !== 200) {
            log_message('error', "FCM send failed [{$statusCode}]: " . ($error ?: $response));
            // Token might be expired, force refresh next time
            if ($statusCode === 401 || $statusCode === 403) {
                self::$cachedToken = null;
            }
            return false;
        }

        return true;
    }

    private function getAccessToken(): ?string
    {
        if (self::$cachedToken !== null && time() < self::$tokenExpiry) {
            return self::$cachedToken;
        }

        $filePath = getenv('FCM_SERVICE_ACCOUNT_PATH')
            ?: '/var/www/hummingbird/main/app/Controllers/pvKey.json';

        if (!file_exists($filePath)) {
            log_message('error', "FCM service account file not found: {$filePath}");
            return null;
        }

        try {
            $credential = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                json_decode(file_get_contents($filePath), true)
            );

            $token = $credential->fetchAuthToken(HttpHandlerFactory::build());

            if (!isset($token['access_token'])) {
                log_message('error', 'FCM: No access_token in credential response');
                return null;
            }

            self::$cachedToken = $token['access_token'];
            self::$tokenExpiry = time() + ($token['expires_in'] ?? 3000) - 120;

            return self::$cachedToken;
        } catch (\Throwable $e) {
            log_message('error', 'FCM auth failed: ' . $e->getMessage());
            return null;
        }
    }
}

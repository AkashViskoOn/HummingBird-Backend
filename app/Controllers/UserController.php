<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\RoomModel;

helper('token');

class UserController extends BaseController
{
    private ?UserModel $userModel = null;

    private function getUserModel(): UserModel
    {
        if ($this->userModel === null) {
            $this->userModel = new UserModel();
        }
        return $this->userModel;
    }

    private function publicUser(array $user, bool $includeBlockList = false): array
    {
        return public_user($user, $includeBlockList);
    }

    private function sendUserOtp(array $user, string $number): bool|string
    {
        $otp = (string)random_int(1000, 9999);
        $saved = $this->getUserModel()->update($user['user_id'], [
            'user_otp' => $otp,
        ]);
        if (!$saved) {
            return false;
        }

        return sendOTP($otp, $number);
    }

    public function registerMobile()
    {
        $userNumber = $this->request->getVar('user_mobile_num');
        $appSignature = $this->request->getVar('app_signature');
        $otp = (string)random_int(1000, 9999);

        $existingUser = $this->getUserModel()
            ->where(['user_mobile_num' => $userNumber, 'u_is_deleted !=' => 1])
            ->first();

        try {
            $data = ['user_otp' => $otp];

            if ($existingUser) {
                $userId = $existingUser['user_id'];
                $this->getUserModel()->update($userId, $data);
            } else {
                $data['user_mobile_num'] = $userNumber;
                $userId = $this->getUserModel()->insert($data, true);
                $existingUser = $this->getUserModel()->find($userId);
            }

            $jwt = encode_jwt(['user' => $existingUser['user_mobile_num']], 900);
            $otpSent = sendOTP($otp, $userNumber, $appSignature);

            if ($otpSent === false) {
                return $this->response->setJSON([
                    "status" => false,
                    "message" => "Failed to send OTP. Please try again later."
                ]);
            }

            return $this->response->setJSON([
                "status" => true,
                "message" => $existingUser ? "Already number registered" : "Number successfully registered",
                "tempToken" => $jwt,
                'user_id' => $userId,
                'user_number' => $userNumber
            ]);
        } catch (\Throwable $th) {
            return $this->response->setJSON(["status" => false, "message" => $th->getMessage()]);
        }
    }

    public function verifyOtp()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authentication Failed']);
        }

        $otpNumber = $this->request->getVar('otp');
        $user = $this->getUserModel()
            ->where('user_mobile_num', $isToken->user)
            ->first();

        if (!$user) {
            return $this->response->setJSON(['status' => false, 'message' => 'User not found']);
        }

        if (empty($user['user_otp']) || !hash_equals((string)$user['user_otp'], (string)$otpNumber)) {
            return $this->response->setJSON(['status' => false, 'message' => 'Wrong OTP entered.']);
        }

        $this->getUserModel()->update($user['user_id'], ['user_otp' => null]);
        $token = encode_jwt([
            'user_id' => $user['user_id'],
            'user_mobile_num' => $user['user_mobile_num'],
        ]);

        $this->autoAttachPendingRooms($user);

        return $this->response->setJSON([
            "status" => true, "message" => "Otp verified",
            "token" => $token, "user" => $this->publicUser($user, true)
        ]);
    }

    public function updateUser()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authentication Failed']);
        }

        $user = user();
        if (!$user) {
            return $this->response->setJSON(['status' => false, 'message' => 'User not found.']);
        }

        $updateData = [];

        $fields = [
            'first_name' => 'user_first_name',
            'last_name' => 'user_last_name',
            'email' => 'user_email',
            'user_dob' => 'user_dob',
            'bio' => 'user_bio',
            'fcm_token' => 'fcm_token',
            'web_fcm_token' => 'web_fcm_token',
            'profile_completed' => 'user_profile_complete',
        ];

        foreach ($fields as $inputKey => $dbKey) {
            $value = $this->request->getVar($inputKey);
            if (isset($value) && !empty($value)) {
                $updateData[$dbKey] = $value;
            }
        }

        // Handle image upload
        $profileImage = $this->request->getFile('user_image');
        if ($profileImage && $profileImage->isValid() && !$profileImage->hasMoved()) {
            try {
                $uploadService = new \App\Services\MediaUploadService();
                $stored = $uploadService->store($profileImage, 'img/uploads/profile');
                $updateData['user_image'] = $stored['web_path'];
            } catch (\Throwable $e) {
                log_message('error', 'updateUser profile image upload failed: ' . $e->getMessage());
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Profile image upload failed. Please try again.',
                ]);
            }
        }

        $removeImage = $this->request->getVar('remove_image');
        if ($removeImage === '1') {
            $updateData['user_image'] = '';
        }

        if (empty($updateData)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No data to update',
                'user' => $this->publicUser($user, true),
            ]);
        }

        try {
            $success = $this->getUserModel()->update($isToken->user_id, $updateData);
            $updatedUser = $success ? $this->getUserModel()->find($isToken->user_id) : $user;

            if ($success) {
                $this->broadcastProfileUpdated($updatedUser);
            }

            return $this->response->setJSON([
                'status' => (bool)$success,
                'message' => $success ? 'User Profile Updated' : 'Failed to update profile',
                'user' => $this->publicUser($updatedUser, true),
            ]);
        } catch (\Throwable $th) {
            return $this->response->setJSON(['status' => false, 'message' => 'Error', 'data' => $th->getMessage()]);
        }
    }

    /**
     * Push a lightweight profile-change event through the socket server so
     * group-chat participants see the new name/photo/bio immediately.
     */
    private function broadcastProfileUpdated(array $user): void
    {
        try {
            // The socket rejects /broadcast without X-Internal-Key, so omitting it
            // (as this used to) silently dropped every profile change once the key
            // was configured on the socket server.
            $internalKey = getenv('INTERNAL_CRON_KEY');
            if ($internalKey === false || $internalKey === '') {
                log_message('error', 'broadcastProfileUpdated: INTERNAL_CRON_KEY not set, skipping broadcast');
                return;
            }
            $broadcastUrl = getenv('SOCKET_BROADCAST_URL') ?: 'http://localhost:4000/broadcast';
            $payload = [
                'user_id' => (string)$user['user_id'],
                'user_first_name' => $user['user_first_name'] ?? '',
                'user_last_name' => $user['user_last_name'] ?? '',
                'user_image' => $user['user_image'] ?? '',
                'user_bio' => $user['user_bio'] ?? '',
            ];
            $ch = curl_init($broadcastUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Internal-Key: ' . $internalKey,
                ],
                CURLOPT_POSTFIELDS => json_encode(['event' => 'profile-updated', 'data' => $payload]),
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            log_message('error', 'Profile broadcast failed: ' . $e->getMessage());
        }
    }

    public function dashboard()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(["status" => false, "message" => "Authentication failed"]);
        }

        try {
            $user = $this->getUserModel()->find($isToken->user_id);
            $user['user_image'] = base_url('/public' . $user['user_image']);
            $user = $this->publicUser($user, true);

            return $this->response->setJSON([
                "status" => true, "message" => "User fetched successfully", "data" => $user
            ]);
        } catch (\Throwable $th) {
            return $this->response->setJSON(["status" => false, "message" => $th->getMessage(), "data" => []]);
        }
    }

    public function getUserById()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $userId = trim((string)$this->request->getVar('user_id'));
            if (!ctype_digit($userId) || (int)$userId <= 0) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'Invalid user identifier',
                ]);
            }
            $user = $this->getUserModel()->find((int)$userId);

            if ($user) {
                return $this->response->setJSON([
                    "status" => true,
                    "message" => "User fetched successfully",
                    "user" => $this->publicUser($user),
                ]);
            }

            return $this->response->setJSON(["status" => false, "message" => "User not found"]);
        } catch (\Throwable $th) {
            return $this->response->setJSON(["status" => false, "message" => $th->getMessage(), "data" => []]);
        }
    }

    public function getUsers()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Unauthorized access']);
        }

        try {
            $contactsJson = $this->request->getVar('contacts');
            $contacts = json_decode($contactsJson, true);

            if (!is_array($contacts)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid contacts input. Expected JSON array.'
                ]);
            }

            $user = user();
            $users = [];

            if (!empty($contacts)) {
                $foundUsers = $this->getUserModel()
                    ->whereIn('user_mobile_num', $contacts)
                    ->findAll();

                foreach ($foundUsers as $cu) {
                    if ($cu['user_mobile_num'] != $user['user_mobile_num']) {
                        $users[] = $this->publicUser($cu);
                    }
                }
            }

            return $this->response->setJSON([
                'status' => true, 'message' => count($users) . ' users found', 'data' => $users
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function requestChangeNumber()
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

            $userNumber = trim((string)$this->request->getVar('user_number'));
            $currentNumber = trim((string)($user['user_mobile_num'] ?? ''));
            if ($userNumber === '' || $currentNumber === '' || !preg_match('/^\d{10,15}$/', $userNumber)
                || !hash_equals($currentNumber, $userNumber)
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'The number must match your current account number',
                ]);
            }

            $otpSent = $this->sendUserOtp($user, $currentNumber);
            if ($otpSent === false) {
                return $this->response->setStatusCode(503)->setJSON([
                    'status' => false,
                    'message' => 'Unable to send verification code',
                ]);
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Verification code sent',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'requestChangeNumber: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => 'Unable to process request']);
        }
    }

    public function changeNumber()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $newNumber = trim((string)$this->request->getVar('new_number'));
            $otp = trim((string)$this->request->getVar('otp'));
            if (!preg_match('/^\d{10,15}$/', $newNumber)) {
                return $this->response->setJSON(['status' => false, 'message' => 'A valid new number is required.']);
            }
            if (!preg_match('/^\d{4}$/', $otp)) {
                return $this->response->setJSON(['status' => false, 'message' => 'A 4-digit verification code is required.']);
            }

            $user = user();
            if (!$user) {
                return $this->response->setJSON(['status' => false, 'message' => 'User not found.']);
            }
            if (empty($user['user_otp']) || !hash_equals((string)$user['user_otp'], $otp)) {
                return $this->response->setJSON(['status' => false, 'message' => 'Invalid verification code.']);
            }
            if (hash_equals((string)$user['user_mobile_num'], $newNumber)) {
                return $this->response->setJSON(['status' => false, 'message' => 'The new number must be different.']);
            }
            if ($this->getUserModel()->where('user_mobile_num', $newNumber)->where('u_is_deleted !=', 1)->first()) {
                return $this->response->setJSON(['status' => false, 'message' => 'That number is already registered.']);
            }

            $this->getUserModel()->update($user['user_id'], [
                'user_mobile_num' => $newNumber,
                'user_otp' => null,
            ]);
            $updatedUser = $this->getUserModel()->find($user['user_id']);
            $newToken = encode_jwt([
                'user_id' => $updatedUser['user_id'],
                'user_mobile_num' => $updatedUser['user_mobile_num'],
            ]);

            return $this->response->setJSON([
                'status' => true, 'message' => 'Number changed',
                'token' => $newToken, 'user' => $this->publicUser($updatedUser, true)
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'changeNumber: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => 'Unable to process request']);
        }
    }

    public function requestAccountDelete()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $user = user();
            if (!$user) {
                return $this->response->setJSON(['status' => false, 'message' => 'User not found.']);
            }
            $requestedNumber = trim((string)$this->request->getVar('user_number'));
            $number = trim((string)($user['user_mobile_num'] ?? ''));
            if ($requestedNumber === '' || $number === '' || !preg_match('/^\d{10,15}$/', $number)
                || !hash_equals($number, $requestedNumber)
            ) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => false,
                    'message' => 'The number must match your current account number',
                ]);
            }

            $otpSent = $this->sendUserOtp($user, $number);
            if ($otpSent === false) {
                return $this->response->setStatusCode(503)->setJSON([
                    'status' => false,
                    'message' => 'Unable to send verification code',
                ]);
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Verification code sent',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'requestAccountDelete: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => 'Unable to process request']);
        }
    }

    public function deleteAccount()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $otp = trim((string)$this->request->getVar('otp'));
            if (!preg_match('/^\d{4}$/', $otp)) {
                return $this->response->setJSON(['status' => false, 'message' => 'A 4-digit verification code is required.']);
            }

            $user = user();
            if (!$user) {
                return $this->response->setJSON(['status' => false, 'message' => 'User not found.']);
            }
            if (empty($user['user_otp']) || !hash_equals((string)$user['user_otp'], $otp)) {
                return $this->response->setJSON(['status' => false, 'message' => 'Invalid verification code.']);
            }

            $success = $this->getUserModel()->update($user['user_id'], [
                'u_is_deleted' => 1,
                'user_otp' => null,
            ]);

            return $this->response->setJSON([
                'status' => (bool)$success,
                'message' => $success ? 'Account deleted.' : 'Failed to delete account.'
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'deleteAccount: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => 'Unable to process request']);
        }
    }

    public function blockUser()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $blockId = $this->request->getVar('block_id');
            $user = user();

            $blockList = json_decode($user['u_block_list'] ?? '[]', true) ?: [];
            $blockList[] = $blockId;

            $this->getUserModel()->update($isToken->user_id, [
                'u_block_list' => json_encode(array_unique($blockList)),
            ]);

            $updatedUser = $this->getUserModel()->find($isToken->user_id);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'User Blocked',
                'user' => $this->publicUser($updatedUser, true),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function unBlockUser()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        try {
            $blockedId = $this->request->getVar('blocked_id');
            $user = user();

            $blockList = json_decode($user['u_block_list'] ?? '[]', true) ?: [];
            $blockList = array_values(array_filter($blockList, fn($id) => $id != $blockedId));

            $this->getUserModel()->update($isToken->user_id, [
                'u_block_list' => json_encode($blockList),
            ]);

            $updatedUser = $this->getUserModel()->find($isToken->user_id);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'User Unblocked',
                'user' => $this->publicUser($updatedUser, true),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveUserSecretKey()
    {
        $token = check_jwt_authentication();
        if (!$token) {
            return $this->response->setJSON(['status' => false, 'message' => 'Authorization Failed']);
        }

        $secretKey = $this->request->getVar('secret_key');
        if (!$secretKey) {
            return $this->response->setJSON(['status' => false, 'message' => 'Secret Key is required.']);
        }

        try {
            $success = $this->getUserModel()->update($token->user_id, ['user_secret_key' => $secretKey]);

            return $this->response->setJSON([
                'status' => $success,
                'message' => $success ? 'Secret Key saved.' : 'Failed to save secret key.',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    // Stub method kept for route compatibility
    public function verifyNewNumber()
    {
        return $this->response->setJSON(['status' => false, 'message' => 'Not implemented']);
    }

    private function autoAttachPendingRooms(array $user): void
    {
        $phone = $user['user_mobile_num'] ?? null;
        if (empty($phone)) return;

        try {
            $roomModel = new RoomModel();
            $pendingRooms = $roomModel
                ->where('r_is_pending', 1)
                ->where('r_pending_phone', $phone)
                ->findAll();

            if (empty($pendingRooms)) return;

            $userId = $user['user_id'];
            foreach ($pendingRooms as $room) {
                $existingUsers = json_decode($room['r_users'] ?? '[]', true) ?: [];
                if (!in_array($userId, $existingUsers)) {
                    $existingUsers[] = $userId;
                }
                $roomModel->update($room['r_id'], [
                    'r_is_pending' => 0,
                    'r_pending_phone' => null,
                    'r_users' => json_encode($existingUsers),
                    'r_name' => ($user['user_first_name'] ?? '') . ' ' . ($user['user_last_name'] ?? ''),
                    'r_avatar' => $user['user_image'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'autoAttachPendingRooms failed: ' . $e->getMessage());
        }
    }
}

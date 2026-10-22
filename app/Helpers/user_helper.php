<?php

use App\Models\UserModel;

function user(): array|false
{
    static $cachedUser = null;
    static $cachedId = null;

    $isToken = check_jwt_authentication();
    if (!$isToken) {
        return false;
    }

    $currentId = $isToken->user_id ?? null;
    if ($currentId === null) {
        return false;
    }

    if ($cachedUser !== null && $cachedId === $currentId) {
        return $cachedUser;
    }

    $userModel = new UserModel();
    $user = $userModel->where([
        'user_mobile_num' => $isToken->user_mobile_num,
        'u_is_deleted !=' => 1
    ])->first();

    if ($user) {
        $cachedUser = $user;
        $cachedId = $currentId;
        return $user;
    }

    return false;
}


function public_user(array $user, bool $includeBlockList = false): array
{
    $fields = [
        'user_id',
        'user_first_name',
        'user_last_name',
        'user_image',
        'user_dob',
        'user_mobile_num',
        'user_email',
        'user_bio',
        'user_profile_complete',
        'user_created_at',
        'user_updated_at',
    ];
    if ($includeBlockList) {
        $fields[] = 'u_block_list';
    }

    return array_intersect_key($user, array_flip($fields));
}

function user_name_validation(string $firstName, string $lastName): bool
{
    if (strlen($firstName) > 50) {
        return false;
    }

    if (strlen($lastName) > 50) {
        return false;
    }

    return true;
}


function sendOTP(string $otp, string $number, string $appSignature = ''): bool|string
{
    $postData = [
        'template_id' => '675c3939d6fc056a86258102',
        "recipients" => [
            [
                "mobiles" => '91' . $number,
                "var" => "<#>",
                "otp" => $otp,
                "sign" => $appSignature
            ]
        ],
    ];

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://control.msg91.com/api/v5/flow",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "authkey: " . getenv('MSG91_AUTH_KEY'),
            "content-type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        log_message('error', 'sendOTP failed: ' . $err);
        return false;
    }

    return $response;
}

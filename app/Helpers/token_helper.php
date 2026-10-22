<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;


function encode_jwt($payload, $expiresIn = 86400)
{
    $jwt = null;
    $key = getenv('JWT_SECRET');
    try {
        $issuedAt = time();
        $payload['iat'] = $issuedAt;
        $payload['exp'] = $issuedAt + max(60, (int)$expiresIn);

        $jwt = JWT::encode($payload, $key, 'HS256');

        if($jwt) {
            return $jwt;
        }else{
            return false;
        }

    } catch (\Throwable $th) {
        return $th->getMessage();
    }

}

function check_header_token()
{

    $header = request()->header('Authorization');

    if ($header) {
        $authorization = $header->getValue();
        if (!empty($authorization)) {
            if (preg_match('/Bearer\s(\S+)/', $authorization, $token)) {
                return $token[1];
            }
        }
    } else {
        return false;
    }
}

/**
 * Whether the device behind a decoded JWT is still allowed to act.
 *
 * This is what makes a remote sign-out real rather than cosmetic. The JWT is
 * stateless: signing it out cannot be done by deleting a row, because the
 * already-issued token would keep verifying until it expires. Instead the token
 * carries `device_id`, and every authenticated request re-checks that row. A
 * revoked (or deleted) device row therefore dies on its very next request, even
 * if it missed the socket `desktop.device.revoked` push because it was offline.
 *
 * Tokens without a `device_id` claim (the phone OTP token) are not device-bound
 * and are left alone.
 */
function jwt_device_is_active($jwt): bool
{
    $deviceId = $jwt->device_id ?? null;
    if ($deviceId === null) {
        return true;
    }
    if (! is_numeric($deviceId)) {
        return false;
    }

    try {
        $row = (new \App\Models\UserDeviceModel())
            ->where('device_id', (int) $deviceId)
            ->first();
    } catch (\Throwable $th) {
        // A database hiccup must not silently log every desktop out. Fail open
        // on the lookup itself; the device stays enforced once the DB answers.
        log_message('error', 'jwt_device_is_active lookup failed: ' . $th->getMessage());
        return true;
    }

    return $row !== null && ($row['status'] ?? '') === 'active';
}

function check_jwt_authentication()
{
    $token = check_header_token();
    $key = getenv('JWT_SECRET');
    if ($token) {
        $jwt = null;
        try {
            $jwt = JWT::decode($token, new Key($key, 'HS256'));
            if (!isset($jwt->exp) || !is_numeric($jwt->exp) || (int)$jwt->exp <= time()) {
                return false;
            }
            // A revoked device is rejected on every authenticated request, not
            // only on the linked-devices routes.
            if (! jwt_device_is_active($jwt)) {
                return false;
            }
            return $jwt;
        } catch (\Throwable $th) {
            return false;
        }
    } else {
        return false;
    }
}

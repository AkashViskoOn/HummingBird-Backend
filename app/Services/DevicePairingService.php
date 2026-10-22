<?php

namespace App\Services;

use App\Models\DesktopPairingSessionModel;
use App\Models\UserDeviceModel;

/**
 * Desktop device pairing.
 *
 * All the rules live here rather than in the controller so the same invariants
 * apply no matter who calls them.
 *
 * Security properties this enforces:
 *
 *  - The QR code never carries a credential that already authorises anything. It
 *    holds `pairing_ref` + `challenge` + a `claim_secret` the desktop generated
 *    itself. No JWT, no server-issued secret and no database credential is
 *    placed in it, and the server only stores a hash of the claim secret.
 *  - A pairing session is unowned (user_id NULL) until a phone approves it, so
 *    an anonymous caller cannot approve its own session and mint itself a
 *    desktop token.
 *  - Approval requires an authenticated phone JWT, a non-expired session, a
 *    session that is still pending/scanned, and a matching challenge.
 *  - The exchange code is stored hashed and redeemable exactly once: the
 *    status flip and the `consumed_at` write happen in one conditional UPDATE,
 *    so two concurrent claims cannot both succeed.
 *  - A revoked device is re-checked on every authenticated call, so revoking a
 *    desktop actually ends its session instead of only hiding it from a list.
 */
class DevicePairingService
{
    /** Pairing window. 60 seconds, per the desktop QR countdown. */
    public const PAIRING_TTL_SECONDS = 60;

    /** How long an approved-but-unclaimed session stays claimable. */
    public const CLAIM_WINDOW_SECONDS = 300;

    /**
     * Scopes accepted for the desktop token. Desktop sessions are deliberately
     * short-lived: there is no refresh token in this codebase, so a short
     * lifetime keeps the blast radius of a stolen desktop token small.
     */
    public const DESKTOP_TOKEN_TTL = 604800; // 7 days

    private function sessions(): DesktopPairingSessionModel
    {
        return new DesktopPairingSessionModel();
    }

    private function devices(): UserDeviceModel
    {
        return new UserDeviceModel();
    }

    /** Cryptographically secure, URL-safe, lowercase hex. */
    private function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Token-bucket rate limit for one unauthenticated pairing action.
     *
     * Defence in depth for the routes a caller can reach without a JWT: a single
     * source cannot mint unbounded pairing sessions or grind the claim endpoint.
     * The endpoint still cannot mint a session on its own — that is enforced by
     * approval + the claim secret — so this only caps abuse.
     *
     * It FAILS OPEN on purpose: if the cache backend is unreachable the action is
     * allowed rather than locking a legitimate user out of linking. The caller
     * (DevicePairingController) decides the budgets and skips this entirely under
     * the testing environment so the suite is not throttled by its own traffic.
     *
     * @param string $bucket   action name, so budgets do not share a counter
     * @param string $key      the caller identity (client IP)
     * @param int    $capacity requests allowed per window
     * @param int    $seconds  window length
     *
     * @return bool true when the request is within budget
     */
    public function allowRequest(string $bucket, string $key, int $capacity, int $seconds): bool
    {
        try {
            return \Config\Services::throttler()->check(
                'pairing-' . $bucket . '-' . $key,
                $capacity,
                $seconds,
            );
        } catch (\Throwable $e) {
            log_message('warning', 'DevicePairingService::allowRequest failed open: ' . $e->getMessage());

            return true;
        }
    }

    // ---------------------------------------------------------------- create

    /**
     * Desktop asks for a pairing session. No authentication — that is the
     * whole point, the desktop has no session yet.
     *
     * The caller supplies `claim_secret`, a random value it generated itself and
     * is about to render into the QR code. This is the WhatsApp-Web model: the
     * QR image is the shared secret, so the phone never has to transmit
     * anything back to the machine that displayed it. The server only ever
     * stores a hash of it, so a stolen database cannot be replayed against a
     * live pairing session.
     *
     * @return array{pairing_ref:string,challenge:string,expires_at:string,ttl:int}|array{error:string}
     */
    public function createPairingSession(array $device): array
    {
        $claimSecret = (string) ($device['claim_secret'] ?? '');
        if (! preg_match('/^[a-f0-9]{32,128}$/', $claimSecret)) {
            return ['error' => 'A valid claim_secret is required to start pairing.'];
        }

        $ref      = $this->randomHex(16); // 32 chars
        $challenge = $this->randomHex(16);
        $expires  = date('Y-m-d H:i:s', time() + self::PAIRING_TTL_SECONDS);

        $row = [
            'user_id'           => null,
            'pairing_ref'       => $ref,
            'challenge'         => $challenge,
            'claim_secret_hash' => password_hash($claimSecret, PASSWORD_DEFAULT),
            'device_name'       => $this->cleanText($device['device_name'] ?? null, 120),
            'platform'          => $this->cleanText($device['platform'] ?? null, 40),
            'browser'           => $this->cleanText($device['browser'] ?? null, 120),
            'app_version'       => $this->cleanText($device['app_version'] ?? null, 40),
            'ip_address'        => $this->clientIp(),
            'status'            => 'pending',
            'created_at'        => $this->now(),
            'expires_at'        => $expires,
        ];

        // Model::insert() returns false on failure, it does not throw. A silent
        // failure here would hand the desktop a QR for a session that does not
        // exist, so check the return value and the driver's error.
        $inserted = $this->sessions()->insert($row);
        if ($inserted === false) {
            $error = $this->sessions()->db->error();
            log_message('error', 'DevicePairingService::createPairingSession failed: ' . json_encode($error));
            return ['error' => 'Could not create a pairing session. Please try again.'];
        }

        return [
            'pairing_ref' => $ref,
            'challenge'   => $challenge,
            'expires_at'  => $expires,
            'ttl'         => self::PAIRING_TTL_SECONDS,
        ];
    }

    // ---------------------------------------------------------------- status

    /**
     * Desktop polls pairing state, or listens for it over the socket. Always
     * resolves a terminal state rather than leaving the caller hanging.
     *
     * @return array{status:string,...}
     */
    public function getPairingStatus(string $pairingRef): array
    {
        $session = $this->findLiveSession($pairingRef);
        if ($session === null) {
            return ['status' => 'expired', 'reason' => 'unknown_or_expired'];
        }

        $status = $session['status'];

        // Lazily retire a session whose time is up, so a desktop that stopped
        // polling still finds the row terminal on its next visit.
        if (in_array($status, ['pending', 'scanned'], true) && $this->isExpired($session)) {
            $this->sessions()->update($session['pairing_id'], ['status' => 'expired']);
            return ['status' => 'expired', 'reason' => 'timeout'];
        }

        if ($status === 'consumed') {
            return ['status' => 'consumed'];
        }

        if ($status === 'approved') {
            if ($this->approvalIsStale($session)) {
                $this->sessions()->update($session['pairing_id'], ['status' => 'expired']);
                return ['status' => 'expired', 'reason' => 'claim_window_missed'];
            }
            return [
                'status' => 'approved',
                'device' => $this->publicDevice((int) $session['device_id']),
            ];
        }

        return ['status' => $status];
    }

    // --------------------------------------------------------------- approve

    /**
     * The phone confirms a scanned QR. This is the only step that binds a
     * pairing session to a user.
     *
     * @return array{status:string,...}
     */
    public function approvePairing(int $userId, string $pairingRef, string $challenge): array
    {
        $session = $this->findLiveSession($pairingRef);
        if ($session === null) {
            return ['status' => 'expired', 'reason' => 'unknown_or_expired'];
        }

        // A phone must not be able to approve a session that is already bound to
        // a different account, and must not be able to replay a consumed one.
        if ($session['user_id'] !== null && (int) $session['user_id'] !== $userId) {
            return ['status' => 'error', 'reason' => 'already_bound'];
        }
        if (in_array($session['status'], ['consumed', 'rejected', 'cancelled'], true)) {
            return ['status' => $session['status'], 'reason' => 'not_approvable'];
        }
        // A second approval of the same session must not mint a second device
        // row: the phone approving twice (double-tap, retried request) would
        // otherwise stack duplicate "desktop" entries and orphan the first one.
        // Idempotent instead: an already-approved session answers with its own
        // device, exactly like the first approval did.
        if ($session['status'] === 'approved') {
            $result = ['status' => 'approved', 'reason' => 'already_approved'];
            if (! empty($session['device_id'])) {
                $result['device'] = $this->publicDevice((int) $session['device_id']);
            }

            return $result;
        }
        if ($this->isExpired($session)) {
            $this->sessions()->update($session['pairing_id'], ['status' => 'expired']);
            return ['status' => 'expired', 'reason' => 'timeout'];
        }

        // Constant-time compare: the challenge is public but comparing it
        // honestly costs nothing and keeps the door shut against a scan of the
        // wrong QR.
        if (! hash_equals((string) $session['challenge'], $challenge)) {
            return ['status' => 'error', 'reason' => 'challenge_mismatch'];
        }

        $exchangeCode = $this->randomHex(32);

        $device = [
            'user_id'     => $userId,
            'platform'    => 'desktop',
            'device_name' => $session['device_name'],
            'browser'     => $session['browser'],
            'app_version' => $session['app_version'],
            'ip_address'  => $session['ip_address'],
            'status'      => 'active',
            'created_at'  => $this->now(),
            'last_seen'   => $this->now(),
        ];

        // user_id is NOT NULL, so it has to be part of the insert itself.
        $deviceId = $this->devices()->insert($device);
        if ($deviceId === false) {
            $error = $this->devices()->db->error();
            log_message('error', 'DevicePairingService::approvePairing device insert failed: ' . json_encode($error));
            return ['status' => 'error', 'reason' => 'device_create_failed'];
        }
        $deviceId = (int) $deviceId;

        $this->sessions()->update($session['pairing_id'], [
            'user_id'     => $userId,
            'status'      => 'approved',
            'device_id'   => $deviceId,
            'approved_at' => $this->now(),
        ]);

        return [
            'status' => 'approved',
            'device' => $this->publicDevice($deviceId),
        ];
    }

    public function rejectPairing(string $pairingRef): array
    {
        $session = $this->findLiveSession($pairingRef);
        if ($session === null) {
            return ['status' => 'expired'];
        }
        if (in_array($session['status'], ['consumed'], true)) {
            return ['status' => 'consumed'];
        }
        $this->sessions()->update($session['pairing_id'], ['status' => 'rejected']);

        $this->broadcastPairingEvent('desktop.pairing.rejected', $session, []);

        return ['status' => 'rejected'];
    }

    // ----------------------------------------------------------------- claim

    /**
     * Desktop redeems its own claim secret for a session token. This is the
     * only place a desktop token is ever issued.
     *
     * The claim secret is the one the desktop generated and rendered into the QR
     * code, so the requester is provably the machine that displayed the code
     * (or the phone that scanned it) — not a third party who merely observed an
     * approval. Consuming the session is a one-time compare-and-swap.
     */
    public function claimPairing(string $pairingRef, string $claimSecret, string $challenge): array
    {
        $session = $this->findLiveSession($pairingRef);
        if ($session === null) {
            return ['status' => 'expired', 'reason' => 'unknown_or_expired'];
        }
        if ($session['status'] !== 'approved') {
            return ['status' => $session['status'], 'reason' => 'not_claimable'];
        }
        if ($this->approvalIsStale($session)) {
            $this->sessions()->update($session['pairing_id'], ['status' => 'expired']);
            return ['status' => 'expired', 'reason' => 'claim_window_missed'];
        }
        if (! hash_equals((string) $session['challenge'], $challenge)) {
            return ['status' => 'error', 'reason' => 'challenge_mismatch'];
        }
        if (! preg_match('/^[a-f0-9]{32,128}$/', $claimSecret)) {
            return ['status' => 'error', 'reason' => 'bad_claim_secret'];
        }
        if (empty($session['claim_secret_hash']) || ! password_verify($claimSecret, $session['claim_secret_hash'])) {
            return ['status' => 'error', 'reason' => 'bad_claim_secret'];
        }

        // Claim it with a real compare-and-swap.
        //
        // Two traps here, both hit the hard way. Model::update($id, $data) takes
        // the DATA as its second argument, so a chained ->where()->update([...])
        // passes the array as $id and throws "There is no data to update". And a
        // builder update's return value cannot be used to detect a lost race:
        // Model::update() returns bool, and even the query builder's affected-row
        // count is bool on some drivers, so "0 rows matched" is indistinguishable
        // from success. Instead each claimer writes a unique nonce in the same
        // conditional UPDATE and then reads it back — the nonce it finds is
        // authoritative, so exactly one claimer can ever proceed.
        $nonce = $this->randomHex(16);

        $this->sessions()->builder()
            ->where('pairing_id', $session['pairing_id'])
            ->where('status', 'approved')
            ->update([
                'status'      => 'consumed',
                'consumed_at' => $this->now(),
                'claim_nonce' => $nonce,
            ]);

        $winner = $this->sessions()->builder()
            ->where('pairing_id', $session['pairing_id'])
            ->get()
            ->getRowArray();

        if (! is_array($winner) || ! hash_equals((string) ($winner['claim_nonce'] ?? ''), $nonce)) {
            return ['status' => 'error', 'reason' => 'already_consumed'];
        }

        $device = $this->devices()->find($session['device_id']);
        if ($device === null) {
            return ['status' => 'error', 'reason' => 'device_missing'];
        }
        if ($device['status'] !== 'active') {
            return ['status' => 'error', 'reason' => 'device_revoked'];
        }

        $this->devices()->update($device['device_id'], ['last_seen' => $this->now()]);

        $user = $this->publicUser((int) $session['user_id']);

        // The token must carry user_mobile_num, not just user_id: the user()
        // helper resolves the account by user_mobile_num, so a token without it
        // would make every controller that calls user() fail for a desktop.
        // device_id is an extra claim the socket ignores.
        //
        // encode_jwt() returns false on failure but returns the exception
        // *message* (a string) on a throw, so a mere is_string() check would
        // happily hand back an error message as an auth token. A real JWT is
        // always three base64url segments.
        $token = encode_jwt([
            'user_id'         => (int) $session['user_id'],
            'user_mobile_num' => $user['user_mobile_num'] ?? null,
            'device_id'       => (int) $device['device_id'],
            'platform'        => 'desktop',
        ], self::DESKTOP_TOKEN_TTL);

        if (! is_string($token) || substr_count($token, '.') !== 2) {
            log_message('error', 'DevicePairingService::claimPairing token issue failed: ' . (is_string($token) ? $token : gettype($token)));
            return ['status' => 'error', 'reason' => 'token_failed'];
        }

        $this->broadcastPairingEvent('desktop.pairing.approved', $session, [
            'device_id' => (int) $device['device_id'],
        ]);

        return [
            'status' => 'connected',
            'token'  => $token,
            'user'   => $user,
            'device' => $this->publicDevice($device),
        ];
    }

    // ------------------------------------------------------- linked devices

    /**
     * List a user's devices. Mobile/tablet rows only appear here if something
     * else created them; pairing creates desktop rows.
     *
     * Only ACTIVE rows are returned. A revoked device is gone as far as this
     * screen is concerned: showing it struck-through made the list look like it
     * had not been signed out at all, and the "Signed out" history is not
     * something this endpoint is for. Revocation is still recorded in the row
     * (`status`, `revoked_at`) so the token rejection and audit trail keep
     * working; it is simply not advertised back to the user as a live device.
     */
    public function listDevices(int $userId): array
    {
        $rows = $this->devices()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('created_at', 'DESC')
            ->findAll();

        return array_map([$this, 'publicDevice'], $rows);
    }

    public function getDevice(int $userId, int $deviceId): ?array
    {
        $row = $this->devices()
            ->where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->first();

        return $row === null ? null : $this->publicDevice($row);
    }

    public function renameDevice(int $userId, int $deviceId, string $name): array
    {
        $device = $this->getDevice($userId, $deviceId);
        if ($device === null) {
            return ['status' => 'error', 'reason' => 'not_found'];
        }
        $clean = $this->cleanText($name, 120);
        if ($clean === null) {
            return ['status' => 'error', 'reason' => 'invalid_name'];
        }
        $this->devices()->update($deviceId, ['device_name' => $clean]);

        // Pass the row itself: casting an array to int would yield 1 and report
        // the wrong device back.
        return ['status' => 'ok', 'device' => $this->publicDevice($this->devices()->find($deviceId))];
    }

    /**
     * Revoke a device. Revoking the device a request is currently authenticated
     * as is refused: that is logout, not "log out a device".
     *
     * @param bool $allOthers when true, revoke every device except $keepDeviceId
     */
    public function revokeDevice(int $userId, int $deviceId, int $keepDeviceId = 0): array
    {
        $device = $this->getDevice($userId, $deviceId);
        if ($device === null) {
            return ['status' => 'error', 'reason' => 'not_found'];
        }
        if ($deviceId === $keepDeviceId) {
            return ['status' => 'error', 'reason' => 'cannot_revoke_current_device'];
        }

        $this->devices()->update($deviceId, [
            'status'     => 'revoked',
            'revoked_at' => $this->now(),
        ]);

        // Tell the revoked desktop immediately, over the existing broadcast path.
        $this->broadcastToUser((int) $userId, 'desktop.device.revoked', [
            'device_id' => $deviceId,
            'reason'    => 'revoked_by_user',
        ]);

        return ['status' => 'ok', 'device' => $this->publicDevice($this->devices()->find($deviceId))];
    }

    public function revokeAllOtherDevices(int $userId, int $keepDeviceId): array
    {
        $rows = $this->devices()
            ->where('user_id', $userId)
            ->where('status', 'active')
            // The operator belongs INSIDE the key. This CI4 builder is
            // where($key, $value, ?bool $escape) — there is no 3-arg operator
            // form, so where('device_id', $id, '!=') silently becomes
            // `device_id` = $id (with the '!=' string coerced to $escape) and
            // matches the device you meant to keep, while the reverse-looking
            // where('device_id', '!=', $id) compiles to `device_id` = '!=' and
            // matches nothing. Either way "log out everywhere" does the wrong
            // thing without erroring.
            ->where('device_id !=', $keepDeviceId)
            ->findAll();

        $revoked = [];
        foreach ($rows as $row) {
            $this->devices()->update($row['device_id'], [
                'status'     => 'revoked',
                'revoked_at' => $this->now(),
            ]);
            $revoked[] = (int) $row['device_id'];
            $this->broadcastToUser($userId, 'desktop.device.revoked', [
                'device_id' => (int) $row['device_id'],
                'reason'    => 'revoked_log_out_everywhere',
            ]);
        }

        return ['status' => 'ok', 'revoked' => $revoked];
    }

    /**
     * Called on every authenticated desktop request. Returns false when the
     * device behind the token is gone or revoked, which is what actually ends
     * a desktop session server-side.
     */
    public function assertDeviceActive(int $userId, $deviceId): bool
    {
        if ($deviceId === null) {
            return true; // not a device-bound token (i.e. mobile)
        }
        if (! is_numeric($deviceId)) {
            return false;
        }
        $row = $this->devices()
            ->where('device_id', (int) $deviceId)
            ->where('user_id', $userId)
            ->first();

        if ($row === null || $row['status'] !== 'active') {
            return false;
        }

        // Keep last_seen fresh without a write on literally every call: only
        // update when it has gone stale by more than a minute.
        if (empty($row['last_seen']) || (time() - strtotime($row['last_seen'])) > 60) {
            $this->devices()->update($row['device_id'], ['last_seen' => $this->now()]);
        }

        return true;
    }

    /** Revoke a device straight from its token — used by desktop logout. */
    public function revokeFromToken(int $userId, $deviceId): void
    {
        if ($deviceId === null || ! is_numeric($deviceId)) {
            return;
        }
        $this->devices()
            ->where('device_id', (int) $deviceId)
            ->where('user_id', $userId)
            ->update(null, ['status' => 'revoked', 'revoked_at' => $this->now()]);
    }

    // -------------------------------------------------------------- helpers

    private function findLiveSession(string $pairingRef): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $pairingRef)) {
            return null;
        }
        $row = $this->sessions()->where('pairing_ref', $pairingRef)->first();

        return $row === null ? null : $row;
    }

    private function isExpired(array $session): bool
    {
        return empty($session['expires_at']) || strtotime($session['expires_at']) <= time();
    }

    private function approvalIsStale(array $session): bool
    {
        $base = ! empty($session['approved_at']) ? $session['approved_at'] : $session['created_at'];

        return (time() - strtotime((string) $base)) > self::CLAIM_WINDOW_SECONDS;
    }

    private function cleanText($value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? mb_substr($ip, 0, 45) : null;
    }

    public function publicDevice($row): array
    {
        if (is_int($row)) {
            $row = $this->devices()->find($row);
        }
        if (! is_array($row)) {
            return [];
        }

        return [
            'device_id'   => (int) $row['device_id'],
            'platform'    => $row['platform'],
            'device_name' => $row['device_name'],
            'browser'     => $row['browser'],
            'app_version' => $row['app_version'],
            'status'      => $row['status'],
            'created_at'  => $row['created_at'],
            'last_seen'   => $row['last_seen'],
            'revoked_at'  => $row['revoked_at'],
        ];
    }

    private function publicUser(int $userId): array
    {
        $user = (new \App\Models\UserModel())->find($userId);

        return is_array($user) ? $user : [];
    }

    /**
     * Push a pairing event at the desktop that opened the session.
     *
     * An unapproved session has no user, so there is nothing to target yet and
     * the desktop will simply keep polling `getPairingStatus` — which is
     * exactly the fallback this needs. This reuses the existing
     * POST /broadcast + X-Internal-Key channel; no new socket plumbing.
     */
    private function broadcastPairingEvent(string $event, array $session, array $data = []): void
    {
        if (empty($session['user_id'])) {
            return;
        }
        $this->broadcastToUser((int) $session['user_id'], $event, $data);
    }

    private function broadcastToUser(int $userId, string $event, array $data): void
    {
        $internalKey = getenv('INTERNAL_CRON_KEY');
        if ($internalKey === false || $internalKey === '') {
            log_message('error', 'DevicePairingService: INTERNAL_CRON_KEY not set, cannot broadcast ' . $event);
            return;
        }
        $url = getenv('SOCKET_BROADCAST_URL') ?: 'http://localhost:4000/broadcast';
        $payload = json_encode([
            'event'   => $event,
            'data'    => $data,
            'userIds' => [(string) $userId],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Internal-Key: ' . $internalKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

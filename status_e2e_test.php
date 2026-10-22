<?php
require __DIR__ . '/vendor/autoload.php';

$lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES);
$secret = '';
foreach ($lines as $line) {
    if (preg_match('/^JWT_SECRET\s*=\s*(.+)$/', trim($line), $m)) { $secret = trim($m[1]); break; }
}
use Firebase\JWT\JWT;

function jwtFor($userId, $secret) {
    return JWT::encode(['user_id' => $userId, 'iat' => time()], $secret, 'HS256');
}

function callApi($method, $url, $token, $body = null, $filePath = null) {
    $headers = ['Authorization: Bearer ' . $token];
    $ch = curl_init($url);
    if ($filePath !== null) {
        $file = new CURLFile($filePath, 'image/png', 'test.png');
        $post = array_merge(is_array($body) ? $body : [], ['media_file' => $file]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res ?: '', true) ?: $res];
}

$base = 'http://localhost:8080';
$tok1 = jwtFor(1, $secret);
$tok2 = jwtFor(2, $secret);
$tok3 = jwtFor(3, $secret);
$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    echo ($cond ? "PASS  " : "FAIL  ") . $name . ($cond ? "" : "  << " . $detail) . "\n";
    $cond ? $pass++ : $fail++;
}

echo "===== AUTH =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/create", 'BADTOKEN', ['content' => 'x']);
check('create without valid JWT rejected', $c === 200 && ($r['status'] ?? true) === false, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/my-update", 'BADTOKEN');
check('my-update without JWT rejected', $c === 200 && ($r['status'] ?? true) === false);

echo "===== MY UPDATE + AVAILABILITY =====" . "\n";
[$c, $r] = callApi('GET', "$base/status/my-update", $tok1);
check('my-update empty state', $c === 200 && $r['status'] === true && $r['data']['latest_update'] === null, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/change-availability", $tok1, ['status' => 'Busy']);
check('change availability Busy', $c === 200 && $r['status'] === true);
[$c, $r] = callApi('POST', "$base/status/change-availability", $tok1, ['status' => 'INVALID']);
check('invalid availability rejected', ($r['status'] ?? true) === false);

echo "===== PRIVACY =====" . "\n";
[$c, $r] = callApi('GET', "$base/status/privacy", $tok1);
check('privacy default everyone', $c === 200 && $r['status'] === true && $r['data']['privacy'] === 'everyone', json_encode($r));
[$c, $r] = callApi('POST', "$base/status/save-privacy", $tok1, ['privacy' => 'only_share', 'share_ids' => [2]]);
check('save-privacy only_share', $c === 200 && $r['status'] === true && $r['data']['privacy'] === 'only_share', json_encode($r));
[$c, $r] = callApi('POST', "$base/status/save-privacy", $tok1, ['privacy' => 'only_share', 'share_ids' => []]);
check('only_share without ids rejected', ($r['status'] ?? true) === false);
[$c, $r] = callApi('POST', "$base/status/save-privacy", $tok1, ['privacy' => 'contacts_except', 'except_ids' => [3]]);
check('save-privacy contacts_except', $c === 200 && $r['status'] === true && $r['data']['privacy'] === 'contacts_except', json_encode($r));

echo "===== CREATE (TEXT) + MENTION =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/create", $tok1, [
    'content' => 'Hello from Aarav! 🎉 Test status with mention',
    'media_type' => 'text',
    'bg_color' => '#6B21A8',
    'font_style' => 'bold',
    'text_color' => '#FFFFFF',
    'link' => 'https://myhummingbird.net',
    'mentions' => json_encode([2]),
]);
check('create text status with mention', $c === 200 && $r['status'] === true, json_encode($r));
$s1 = $r['data']['su_id'] ?? null;
check('created su_id present', $s1 !== null);
check('privacy forced from settings (contacts_except)', ($r['data']['su_privacy'] ?? '') === 'contacts_except', json_encode($r['data']));

[$c, $r] = callApi('GET', "$base/status/my", $tok1);
check('my statuses list has 1', $c === 200 && count($r['data']['statuses'] ?? []) === 1, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/my-update", $tok1);
check('my-update latest present', ($r['data']['latest_update']['su_id'] ?? null) == $s1);

echo "===== FEED + PRIVACY ENFORCEMENT =====" . "\n";
[$c, $r] = callApi('GET', "$base/status/fetch-updates", $tok2);
$visibleTo2 = $r['data'] ?? [];
check('user2 sees Aarav status (share list has 2)', count($visibleTo2) === 1, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/fetch-updates", $tok3);
check('user3 does NOT see (except list has 3)', count($r['data'] ?? []) === 0, json_encode($r));

echo "===== UPLOAD + MEDIA CREATE =====" . "\n";
file_put_contents(__DIR__ . '/writable/test_img.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
[$c, $r] = callApi('POST', "$base/status/upload", $tok1, [], __DIR__ . '/writable/test_img.png');
check('upload png', $c === 200 && $r['status'] === true && !empty($r['data']['url']), json_encode($r));
$mediaUrl = $r['data']['url'] ?? '';
[$c, $r] = callApi('GET', "$base/uploads/" . ltrim(str_replace('\\', '/', $mediaUrl), '/'), null);
check('uploaded file served via /uploads', $c === 200 && strlen($r ?? '') > 0, "HTTP $c");
[$c, $r] = callApi('POST', "$base/status/create", $tok2, [
    'content' => 'Image status from Priya',
    'media_type' => 'image',
    'media_url_hint' => $mediaUrl,
]);
// use upload endpoint result inside create via media_file
[$c, $r] = callApi('POST', "$base/status/create", $tok2, ['content' => 'Image status from Priya', 'media_type' => 'image'], __DIR__ . '/writable/test_img.png');
check('create image status with file', $c === 200 && $r['status'] === true && !empty($r['data']['su_media_url']), json_encode($r));
$s2 = $r['data']['su_id'] ?? null;
check('image media_type inferred', ($r['data']['su_media_type'] ?? '') === 'image', json_encode($r['data']));

echo "===== VIEW + VIEWERS =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/view", $tok2, ['status_id' => $s1]);
check('user2 views s1', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/view", $tok2, ['status_id' => $s1]);
check('duplicate view idempotent', $c === 200 && $r['status'] === true);
[$c, $r] = callApi('GET', "$base/status/viewers/$s1", $tok1);
check('owner sees viewer list', $c === 200 && count($r['data'] ?? []) === 1 && ($r['data'][0]['user']['user_id'] ?? 0) == 2, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/viewers/$s1", $tok2);
check('non-owner cannot see viewers', ($r['status'] ?? true) === false);

echo "===== REACTIONS =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/react", $tok2, ['status_id' => $s1, 'reaction' => '❤️']);
check('react heart', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/react", $tok2, ['status_id' => $s1, 'reaction' => '😂']);
check('change reaction', $c === 200 && $r['status'] === true);
[$c, $r] = callApi('POST', "$base/status/react", $tok2, ['status_id' => $s1, 'reaction' => '😂']);
check('toggle same reaction removes', $c === 200 && $r['status'] === true && strpos($r['message'] ?? '', 'removed') !== false, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/reactions/$s1", $tok1);
check('reactions grouped for owner', $c === 200 && ($r['data']['total'] ?? -1) === 0, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/reactions/$s1", $tok2);
check('non-owner cannot read reactions', ($r['status'] ?? true) === false);

echo "===== REPLIES =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/reply", $tok2, ['status_id' => $s1, 'message' => 'Nice status Aarav!']);
check('reply to s1', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/reply", $tok2, ['status_id' => $s1, 'message' => '']);
check('empty reply rejected', ($r['status'] ?? true) === false);
[$c, $r] = callApi('GET', "$base/status/replies/$s1", $tok1);
check('owner reads replies', $c === 200 && count($r['data'] ?? []) === 1, json_encode($r));

echo "===== MUTE =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/mute", $tok3, ['muted_user_id' => 1]);
check('user3 mutes user1', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/muted", $tok3);
check('muted list has user1', count($r['data'] ?? []) === 1, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/fetch-updates", $tok3);
check('muted user1 status hidden from feed', count($r['data'] ?? []) === 0, json_encode($r));

echo "===== REPORT =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/report", $tok2, ['status_id' => $s2, 'reason' => 'Spam']);
check('report s2', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/report", $tok1, ['status_id' => $s1, 'reason' => 'x']);
check('cannot report own status', ($r['status'] ?? true) === false);

echo "===== SEARCH + ANALYTICS =====" . "\n";
[$c, $r] = callApi('GET', "$base/status/search?keyword=Hello&page=1", $tok2);
check('search finds text status', $c === 200 && count($r['data']['results'] ?? []) >= 1, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/search?media_type=image&page=1", $tok1);
check('search by media_type', count($r['data']['results'] ?? []) >= 1, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/analytics", $tok1);
check('analytics totals', $c === 200 && ($r['data']['total_updates'] ?? -1) >= 1 && ($r['data']['total_views'] ?? -1) >= 1, json_encode($r));

echo "===== ARCHIVE =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/archive", $tok1, ['update_id' => $s1, 'archive' => '1']);
check('archive s1', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/archived", $tok1);
check('archived list has s1', count($r['data'] ?? []) === 1, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/fetch-updates", $tok2);
check('archived hidden from feed', count($r['data'] ?? []) === 0, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/archive", $tok1, ['update_id' => $s1, 'archive' => '0']);
check('restore s1', $c === 200 && $r['status'] === true);
[$c, $r] = callApi('GET', "$base/status/fetch-updates", $tok2);
check('restored visible in feed', count($r['data'] ?? []) === 1, json_encode($r));

echo "===== EDIT + EXPIRY + DELETE =====" . "\n";
[$c, $r] = callApi('POST', "$base/status/edit", $tok1, ['update_id' => $s1, 'content' => 'Edited content!']);
check('edit s1 content', $c === 200 && $r['status'] === true && ($r['data']['su_content'] ?? '') === 'Edited content!', json_encode($r));
[$c, $r] = callApi('GET', "$base/status/analytics", $tok2);
check('analytics user2 totals', $c === 200 && ($r['data']['total_updates'] ?? -1) >= 1, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/cleanup-expired", $tok1, []);
check('cleanup-expired with JWT', $c === 200 && $r['status'] === true && ($r['data']['deleted'] ?? -1) >= 0, json_encode($r));
[$c, $r] = callApi('POST', "$base/status/cleanup-expired", null, []);
check('cleanup-expired without auth rejected', ($r['status'] ?? true) === false);
[$c, $r] = callApi('POST', "$base/status/cleanup-expired", null, []);
$ch = curl_init("$base/status/cleanup-expired");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Internal-Key: humming-bird-cron']]);
$res = json_decode(curl_exec($ch), true); curl_close($ch);
check('cleanup-expired with cron key', ($res['status'] ?? false) === true, json_encode($res));
[$c, $r] = callApi('DELETE', "$base/status/delete/$s2", $tok1);
check('cannot delete others status', ($r['status'] ?? true) === false);
[$c, $r] = callApi('DELETE', "$base/status/delete/$s2", $tok2);
check('owner deletes s2', $c === 200 && $r['status'] === true, json_encode($r));
[$c, $r] = callApi('GET', "$base/status/my", $tok2);
check('s2 gone from my statuses', count($r['data']['statuses'] ?? []) === 0, json_encode($r));

echo "\n===== RESULT: $pass passed, $fail failed =====\n";

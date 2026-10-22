<?php
$dbFile = __DIR__ . '/humming_bird.sqlite';
if (file_exists($dbFile)) unlink($dbFile);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = [
'users' => "CREATE TABLE users (
  user_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_first_name TEXT, user_last_name TEXT, user_email TEXT, user_image TEXT,
  user_dob TEXT, user_mobile_num TEXT, user_otp TEXT, user_bio TEXT,
  user_profile_complete TEXT DEFAULT '1', u_is_deleted INTEGER DEFAULT 0,
  fcm_token TEXT, web_fcm_token TEXT, u_block_list TEXT, user_secret_key TEXT)",
'contacts' => "CREATE TABLE contacts (
  c_id INTEGER PRIMARY KEY AUTOINCREMENT, c_user_id INTEGER, c_mobile_number TEXT,
  c_name TEXT, c_country_code TEXT, c_created_at TEXT)",
'rooms' => "CREATE TABLE rooms (
  r_id INTEGER PRIMARY KEY AUTOINCREMENT, r_type INTEGER DEFAULT 0, r_users TEXT,
  r_name TEXT, r_created_at TEXT)",
'status_updates' => "CREATE TABLE status_updates (
  su_id INTEGER PRIMARY KEY AUTOINCREMENT,
  su_user_id INTEGER, su_content TEXT, su_media_url TEXT, su_media_type TEXT DEFAULT 'text',
  su_schedule_id TEXT, su_created_at TEXT, su_expires_at TEXT,
  su_is_archived TEXT DEFAULT '0', su_privacy TEXT DEFAULT 'everyone',
  su_bg_color TEXT, su_font_style TEXT, su_text_color TEXT, su_caption TEXT,
  su_location TEXT, su_link TEXT, su_mentions TEXT)",
'status_views' => "CREATE TABLE status_views (
  sv_id INTEGER PRIMARY KEY AUTOINCREMENT, sv_status_id INTEGER, sv_viewer_id INTEGER, sv_viewed_at TEXT)",
'status_reactions' => "CREATE TABLE status_reactions (
  sr_id INTEGER PRIMARY KEY AUTOINCREMENT, sr_status_id INTEGER, sr_user_id INTEGER,
  sr_reaction TEXT, sr_created_at TEXT)",
'status_replies' => "CREATE TABLE status_replies (
  srp_id INTEGER PRIMARY KEY AUTOINCREMENT, srp_status_id INTEGER, srp_user_id INTEGER,
  srp_message TEXT, srp_created_at TEXT)",
'status_mentions' => "CREATE TABLE status_mentions (
  smn_id INTEGER PRIMARY KEY AUTOINCREMENT, smn_status_id INTEGER, smn_user_id INTEGER,
  smn_mentioned_user_id INTEGER, smn_created_at TEXT)",
'status_mutes' => "CREATE TABLE status_mutes (
  smu_id INTEGER PRIMARY KEY AUTOINCREMENT, smu_user_id INTEGER, smu_muted_user_id INTEGER, smu_created_at TEXT)",
'status_reports' => "CREATE TABLE status_reports (
  srp_id INTEGER PRIMARY KEY AUTOINCREMENT, srp_status_id INTEGER, srp_reporter_id INTEGER,
  srp_reason TEXT, srp_created_at TEXT)",
'user_availability' => "CREATE TABLE user_availability (
  ua_id INTEGER PRIMARY KEY AUTOINCREMENT, ua_user_id INTEGER, ua_status TEXT, ua_updated_at TEXT)",
'user_status_settings' => "CREATE TABLE user_status_settings (
  us_id INTEGER PRIMARY KEY AUTOINCREMENT, us_user_id INTEGER, us_privacy TEXT DEFAULT 'everyone',
  us_except_ids TEXT, us_share_ids TEXT, us_updated_at TEXT)",
];
foreach ($tables as $name => $sql) {
    $pdo->exec("DROP TABLE IF EXISTS $name");
    $pdo->exec($sql);
    echo "created $name\n";
}

$pdo->exec("INSERT INTO users (user_id, user_first_name, user_last_name, user_mobile_num, user_bio, user_image, u_is_deleted, fcm_token) VALUES
  (1, 'Aarav', 'Sharma', '+919000000001', 'hello aarav', '', 0, 'fcm1'),
  (2, 'Priya', 'Verma', '+919000000002', 'hello priya', '', 0, 'fcm2'),
  (3, 'Rahul', 'Mehta', '+919000000003', 'hi', '', 0, NULL)");
$pdo->exec("INSERT INTO contacts (c_id, c_user_id, c_mobile_number, c_name) VALUES
  (1, 1, '+919000000002', 'Priya'), (2, 2, '+919000000001', 'Aarav')");
$pdo->exec("INSERT INTO rooms (r_id, r_type, r_users, r_name) VALUES
  (1, 0, '[1,2]', '1-2'), (2, 0, '[1,3]', '1-3')");
echo "seeded users 1,2,3 + contacts + rooms\n";
echo "OK\n";

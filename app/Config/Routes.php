<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// Liveness + readiness probes. Intentionally unauthenticated: a load balancer
// and an uptime check cannot present an app JWT, and a probe that requires one
// is a probe nobody configures. Neither endpoint returns a secret value, a DSN
// or a stack trace — only reachability and presence. See HealthController.
$routes->get('health', 'HealthController::index');
$routes->get('health/ready', 'HealthController::ready');

// Prometheus text metrics. Intentionally unauthenticated and never touches the
// database, like the health probes: a scraper cannot present an app JWT and a
// metric that dies with its dependency is useless exactly when it matters.
// Emits only aggregate counters — never identifiers, secrets, DSNs or stacks.
$routes->get('metrics', 'MetricsController::index');

$routes->group("user",function($routes) {
    $routes->post("register-number","UserController::registerMobile");
    $routes->post("otp-verify","UserController::verifyOtp");
    $routes->post("update-user-profile","UserController::updateUser");
    $routes->get("dashboard-details","UserController::dashboard");
    $routes->post('get-users', 'UserController::getUsers');
    $routes->get('get-single-user', 'UserController::getUserById');
    // $routes->post('update-user', "UserController::updateUser");
    $routes->get('request-change-number', 'UserController::requestChangeNumber');
    $routes->get('change-number', 'UserController::changeNumber');
    $routes->get('request-account-delete', 'UserController::requestAccountDelete');
    $routes->get('delete-account', 'UserController::deleteAccount');
    $routes->get('block-user', 'UserController::blockUser');
    $routes->get('unblock-user', 'UserController::unBlockUser');
    $routes->get('save-secret-key', 'UserController::saveUserSecretKey');

    // Desktop device linking. Additional to the phone OTP flow above, not a
    // replacement for it. The three pairing-session routes the desktop needs
    // before it has a session are intentionally unauthenticated: they cannot
    // mint a session on their own, and the session stays unowned (user_id NULL)
    // until a phone with a valid JWT approves it. See DevicePairingController.
    $routes->post('desktop-pairing/create', 'DevicePairingController::createPairing');
    $routes->get('desktop-pairing/status', 'DevicePairingController::getPairingStatus');
    $routes->post('desktop-pairing/claim', 'DevicePairingController::claimPairing');
    $routes->post('desktop-pairing/approve', 'DevicePairingController::approvePairing');
    $routes->post('desktop-pairing/reject', 'DevicePairingController::rejectPairing');

    $routes->get('linked-devices', 'DevicePairingController::listDevices');
    $routes->post('linked-devices/revoke', 'DevicePairingController::revokeDevice');
    $routes->post('linked-devices/rename', 'DevicePairingController::renameDevice');
    $routes->post('linked-devices/logout', 'DevicePairingController::logoutDevice');

    // Internal, X-Internal-Key protected. Called by the socket server at
    // handshake to confirm the device behind the presented JWT is still active,
    // so a revoked desktop cannot simply reconnect with its old token. Not a
    // consumer route: it returns no device details, only active/not.
    $routes->get('device-status', 'DevicePairingController::deviceStatus');
});

$routes->group("contact", function($routes) {
    $routes->post("add-contact", "ContactController::addContact");
    $routes->get("fetch-contacts", "ContactController::fetchContacts");
    $routes->get("check-user", "ContactController::checkUser");
    $routes->post("update-contact", "ContactController::updateContact");
    $routes->post("delete-contact", "ContactController::deleteContact");
});

$routes->group("room", function($routes) {
    $routes->post("create-room", "RoomController::createRoom");
    $routes->get("fetch-rooms", "RoomController::fetchRooms");
    $routes->post('add-users', 'RoomController::addUserInGroup');
    $routes->get('get-group-info', 'RoomController::getGroupInfo');
    $routes->get('leave-group', 'RoomController::leaveGroup');
    $routes->get('make-admin', 'RoomController::makeAdmin');
    $routes->get('remove-admin', 'RoomController::removeFromAdmin');
    $routes->post('update-room', 'RoomController::updateRoom');
    $routes->get('delete-room', 'RoomController::deleteRoom');
    $routes->get('toggle-archive', 'RoomController::toggleArchive');
    $routes->get('toggle-favorite', 'RoomController::toggleFavorite');
    $routes->get('toggle-mute', 'RoomController::toggleMute');
    $routes->get('mark-read', 'RoomController::markRead');
    $routes->get('mark-unread', 'RoomController::markUnread');
    $routes->get('check-user-by-number', 'RoomController::checkUserByNumber');
    $routes->post('create-pending-room', 'RoomController::createPendingRoom');
    $routes->get('attach-pending-rooms', 'RoomController::attachPendingRooms');
});

$routes->group("message", function($routes) {
    $routes->post('send-message', "MessageController::sendMessage");
    $routes->get('fetch-messages', "MessageController::fetchMessages");
    $routes->get('delete-message', 'MessageController::deleteMessage');
    $routes->get('get-scheduled-messages', 'MessageController::getScheduledMessages');
    $routes->get('delete-scheduled-message', 'MessageController::cancelScheduledMessage');
    $routes->post('update-message', 'MessageController::updateMessage');
    $routes->get('send-scheduled-messages', 'MessageController::sendScheduledMessage');
    $routes->post('toggle-reaction', 'MessageController::toggleReaction');
    $routes->get('pin-message', 'MessageController::pinMessage');
    $routes->get('unpin-message', 'MessageController::unpinMessage');
    $routes->get('star-message', 'MessageController::starMessage');
    $routes->get('unstar-message', 'MessageController::unstarMessage');
    $routes->post('forward-message', 'MessageController::forwardMessage');
    $routes->get('message-info', 'MessageController::fetchMessageInfo');
    $routes->get('fetch-pinned-messages', 'MessageController::fetchPinnedMessages');
    $routes->get('fetch-starred-messages', 'MessageController::fetchStarredMessages');
});

$routes->group('notification', function($routes) {
    // sendNotification is an internal FCM helper (10 required positional args,
    // returns bool) invoked only from MessageController / ConnectMessageService.
    // Exposing it here was a dead, unauthenticated 500 trap — a POST hit it with
    // 0 args and crashed with ArgumentCountError. No client calls it over HTTP.
});

$routes->group('scheduling-data', function($routes){
    $routes->get('get-scheduling-data', 'ScheduledMessagesController::getSchedulingData');
    $routes->get('save-scheduling-data', 'ScheduledMessagesController::saveSchedulingData');
    $routes->get('delete-scheduling-data', 'ScheduledMessagesController::deleteSchedulingData');
});

$routes->group('ai', function($routes) {
    $routes->post('reply-suggestions', 'AIController::getReplySuggestions');
    $routes->post('categorize', 'AIController::categorizeMessage');
    $routes->post('summarize', 'AIController::generateSummary');
    $routes->post('sentiment', 'AIController::analyzeSentiment');
    $routes->post('smart-search', 'AIController::smartSearch');
    $routes->post('translate', 'AIController::translateMessage');
    $routes->post('detect-spam', 'AIController::detectSpam');
    $routes->post('chatbot', 'AIController::getChatbotResponse');
    $routes->post('notification-group', 'AIController::getNotificationGroup');
    $routes->post('generate-message', 'AIController::generateMessage');
});

$routes->group('report', function($routes) {
    $routes->post('report-user', "ReportController::reportUser");
});

$routes->group('generative-ai', function($routes) {
        $routes->post('get-ai-message', "AIController::getAiMessage");
});

$routes->group('search', function($routes) {
    $routes->get('search-messages', "SearchController::searchMessages");
});

$routes->group('emoji', function($routes) {
    $routes->post('add-emoji', "EmojiController::addEmoji");
});

$routes->group('media', function($routes) {
    $routes->post('compress', 'MediaController::compressMedia');
    $routes->post('share', 'MediaController::shareMedia');
    $routes->get('info', 'MediaController::getMediaInfo');
    $routes->get('gallery', 'MediaController::getGalleryMedia');
    $routes->post('trim-video', 'MediaController::trimVideo');
    $routes->get('compressed/(:any)', 'MediaController::serveCompressed/$1');
    $routes->get('trimmed/(:any)', 'MediaController::serveTrimmed/$1');
});

// Serves files stored in writable/uploads (fallback storage + call recordings)
$routes->get('uploads/(:any)', 'MediaController::serveUpload/$1');

$routes->group('call', function($routes) {
    $routes->post('init', 'CallController::initCall');
    $routes->post('answer', 'CallController::answerCall');
    $routes->post('end', 'CallController::endCall');
    $routes->get('history', 'CallController::getCallHistory');
    $routes->post('delete-history', 'CallController::deleteCallHistory');
    $routes->post('favorite', 'CallController::toggleFavorite');
    $routes->get('favorites', 'CallController::getFavorites');
    $routes->post('block', 'CallController::blockUser');
    $routes->get('blocked', 'CallController::getBlockedUsers');
    $routes->get('speed-dial', 'CallController::getSpeedDial');
    $routes->post('save-recording', 'CallController::saveCallRecording');
});

$routes->group('schedule', function($routes) {
    $routes->get('categories', 'ScheduleController::getScheduleCategories');
    $routes->post('save-template', 'ScheduleController::saveScheduleTemplate');
    $routes->get('get-templates', 'ScheduleController::getScheduleTemplates');
    $routes->get('delete-template', 'ScheduleController::deleteScheduleTemplate');
    $routes->get('upcoming', 'ScheduleController::getUpcomingSchedules');
    $routes->post('save-draft', 'ScheduleController::saveDraftScheduledMessage');
    $routes->get('get-drafts', 'ScheduleController::getDraftScheduledMessages');
    $routes->get('delete-draft', 'ScheduleController::deleteDraftScheduledMessage');
    $routes->post('mark-failed', 'ScheduleController::markScheduleFailed');
    $routes->post('retry-failed', 'ScheduleController::retryFailedSchedule');
    $routes->get('search', 'ScheduleController::searchScheduledMessages');
    $routes->post('parse', 'ScheduleController::parseNaturalSchedule');
});

$routes->group('status', function($routes) {
    $routes->get('my-update', 'StatusController::myUpdate');
    $routes->get('fetch-updates', 'StatusController::fetchUpdates');
    $routes->post('create', 'StatusController::create');
    $routes->post('edit', 'StatusController::edit');
    $routes->post('view', 'StatusController::view');
    $routes->get('viewers/(:num)', 'StatusController::viewers/$1');
    $routes->post('change-availability', 'StatusController::changeAvailability');
    $routes->post('archive', 'StatusController::archive');
    $routes->get('archived', 'StatusController::archivedList');
    $routes->delete('delete/(:num)', 'StatusController::delete/$1');
    $routes->post('react', 'StatusController::react');
    $routes->get('reactions/(:num)', 'StatusController::reactions/$1');
    $routes->post('reply', 'StatusController::reply');
    $routes->get('replies/(:num)', 'StatusController::replies/$1');
    $routes->post('share', 'StatusController::share');
    $routes->get('shares/(:num)', 'StatusController::shares/$1');
    $routes->post('mute', 'StatusController::mute');
    $routes->get('muted', 'StatusController::mutedList');
    $routes->get('search', 'StatusController::search');
    $routes->get('analytics', 'StatusController::analytics');
    $routes->post('upload', 'StatusController::upload');
    $routes->get('my', 'StatusController::my');
    $routes->get('feed', 'StatusController::feed');
    $routes->get('privacy', 'StatusController::privacy');
    $routes->post('save-privacy', 'StatusController::savePrivacy');
    $routes->post('report', 'StatusController::report');
    $routes->post('cleanup-expired', 'StatusController::cleanupExpired');
});

$routes->group('moments', function($routes) {
    $routes->post('schedule', 'ScheduledMomentController::create');
    $routes->get('list', 'ScheduledMomentController::list');
    $routes->get('published', 'ScheduledMomentController::published');
    $routes->get('details/(:num)', 'ScheduledMomentController::details/$1');
    $routes->post('edit', 'ScheduledMomentController::edit');
    $routes->post('duplicate', 'ScheduledMomentController::duplicate');
    $routes->delete('delete/(:num)', 'ScheduledMomentController::delete/$1');
    $routes->get('drafts', 'ScheduledMomentController::drafts');
    $routes->post('save-draft', 'ScheduledMomentController::saveDraft');
    $routes->delete('delete-draft/(:num)', 'ScheduledMomentController::deleteDraft/$1');
    $routes->get('calendar', 'ScheduledMomentController::calendar');
    $routes->post('upload', 'ScheduledMomentController::upload');
    $routes->post('publish', 'ScheduledMomentController::publish');
    $routes->post('cancel', 'ScheduledMomentController::cancel');
    $routes->get('process-scheduled', 'ScheduledMomentController::processScheduled');
});

// ---------------------------------------------------------------------------
// Humming Bird Connect — B2B API (PHASE 2). Isolated from the consumer app.
// Guarded by the connect-auth filter (Config\Filters) — never runs on the
// Flutter/JWT/Socket.IO routes above.
// ---------------------------------------------------------------------------
$routes->group('api/v1/connect', ['namespace' => 'App\Controllers\Connect'], function ($routes) {
    $routes->get('auth/test', 'AuthController::test');

    // Phase 3 — external user mapping/identity linking (users:write scope).
    $routes->post('users/map', 'UserController::map', ['filter' => 'connect-auth:scope=users%3Awrite']);
    $routes->get('users/mapping', 'UserController::mapping', ['filter' => 'connect-auth:scope=users%3Awrite']);
    $routes->delete('users/mapping', 'UserController::unmap', ['filter' => 'connect-auth:scope=users%3Awrite']);

    // Phase 4 — inbound message delivery (messages:send / messages:read scope).
    $routes->post('messages/send', 'MessageController::send', ['filter' => 'connect-auth:scope=messages%3Asend']);
    $routes->get('messages/lookup/(:any)', 'MessageController::lookup/$1', ['filter' => 'connect-auth:scope=messages%3Aread']);
    $routes->get('messages/lookup', 'MessageController::lookup', ['filter' => 'connect-auth:scope=messages%3Aread']);

    // Phase 7 — message status (messages:status or messages:read scope).
    $routes->get('messages/status/(:any)/events', 'MessageStatusController::events/$1', ['filter' => 'connect-auth:scope=messages%3Astatus']);
    $routes->get('messages/status/(:any)', 'MessageStatusController::show/$1', ['filter' => 'connect-auth:scope=messages%3Astatus']);
    $routes->get('messages/status', 'MessageStatusController::show', ['filter' => 'connect-auth:scope=messages%3Astatus']);

    // Phase 7 — webhook management (webhooks:manage scope).
    $routes->get('webhooks', 'WebhookController::index', ['filter' => 'connect-auth:scope=webhooks%3Amanage']);
    $routes->post('webhooks', 'WebhookController::store', ['filter' => 'connect-auth:scope=webhooks%3Amanage']);
    $routes->get('webhooks/(:num)', 'WebhookController::show/$1', ['filter' => 'connect-auth:scope=webhooks%3Amanage']);
    $routes->patch('webhooks/(:num)', 'WebhookController::update/$1', ['filter' => 'connect-auth:scope=webhooks%3Amanage']);
    $routes->post('webhooks/(:num)/test', 'WebhookController::test/$1', ['filter' => 'connect-auth:scope=webhooks%3Amanage']);
    $routes->delete('webhooks/(:num)', 'WebhookController::destroy/$1', ['filter' => 'connect-auth:scope=webhooks%3Amanage']);

    // Phase 9 — Connect admin dashboard (operator-facing). Reuses the same
    // connect-auth HMAC pipeline; the filter additionally demands credential
    // role 'admin' for every `admin:connect:*` scope, and tenant/env isolation
    // is enforced server-side in ConnectAdminService / ConnectAdminListService.
    $routes->get('admin/dashboard', 'AdminDashboardController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aoverview']);
    $routes->get('admin/integrations', 'AdminIntegrationController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->get('admin/credentials', 'AdminIntegrationController::credentials', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->patch('admin/integrations/(:num)/status', 'AdminIntegrationController::setStatus/$1', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Amanage']);
    $routes->get('admin/messages/(:num)', 'AdminMessageController::show/$1', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->get('admin/messages', 'AdminMessageController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->get('admin/delivery-events', 'AdminDeliveryController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->get('admin/webhooks', 'AdminWebhookController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->get('admin/webhook-deliveries', 'AdminWebhookController::deliveries', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aview']);
    $routes->patch('admin/webhooks/(:num)/status', 'AdminWebhookController::setStatus/$1', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Amanage']);
    $routes->patch('admin/webhooks/(:num)', 'AdminWebhookController::update/$1', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Amanage']);
    $routes->get('admin/usage', 'AdminUsageController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Ausage']);
    $routes->get('admin/audit-logs', 'AdminAuditController::index', ['filter' => 'connect-auth:scope=admin%3Aconnect%3Aaudit']);
});
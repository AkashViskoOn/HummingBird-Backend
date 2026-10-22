<?php

namespace App\Daemon;

/**
 * DISABLED: This daemon duplicates the socket server's cron (app.js:131)
 * which calls GET /message/send-scheduled-messages every 60s and broadcasts
 * socket events (scheduled-message-sent, message-receive, new-message).
 *
 * Running both would risk double delivery and the daemon lacks socket
 * integration. The socket-server cron is the canonical scheduler.
 *
 * To re-enable, uncomment the run() body and ensure the socket cron
 * is disabled instead.
 */
class ScheduledMessageDaemon
{
    public function run()
    {
        echo "[ScheduledMessageDaemon] Disabled — socket server cron handles scheduling.\n";
    }
}

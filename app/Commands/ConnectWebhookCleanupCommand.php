<?php

namespace App\Commands;

use App\Services\Connect\ConnectWebhookDeliveryService;
use CodeIgniter\CLI\BaseCommand;
use Config\Connect as ConnectConfig;

/**
 * Humming Bird Connect — webhook delivery retention cleanup (Phase 7).
 *
 * Purges terminal (delivered/failed) connect_webhook_deliveries rows older
 * than Connect::$webhookDeliveryRetentionDays:
 *
 *   php spark connect:webhook-cleanup
 *   php spark connect:webhook-cleanup --days 60   # override retention
 *
 * Safe to run under cron. Never blocks pending/processing/retrying rows.
 */
class ConnectWebhookCleanupCommand extends BaseCommand
{
    protected $group       = 'Connect';
    protected $name        = 'connect:webhook-cleanup';
    protected $description = 'Purges expired terminal webhook delivery rows.';

    public function run(array $params)
    {
        $deliveries = new ConnectWebhookDeliveryService();
        $config     = config(ConnectConfig::class);
        $days       = (int) ($config->webhookDeliveryRetentionDays);

        $index = array_search('--days', $params, true);
        if ($index !== false && isset($params[$index + 1]) && (int) $params[$index + 1] > 0) {
            $days = (int) $params[$index + 1];
        }

        echo "[ConnectWebhookCleanup] purging terminal deliveries older than {$days} day(s)\n";

        $purged = $deliveries->purgeOlderThan($days);

        echo "[ConnectWebhookCleanup] purged {$purged} delivery row(s)\n";

        return EXIT_SUCCESS;
    }
}
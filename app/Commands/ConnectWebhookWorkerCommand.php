<?php

namespace App\Commands;

use App\Services\Connect\ConnectWebhookProcessor;
use CodeIgniter\CLI\BaseCommand;
use Config\Connect as ConnectConfig;
use Throwable;

/**
 * Humming Bird Connect — webhook delivery worker (Phase 7).
 *
 * Claims durably-queued connect_webhook_deliveries rows and POSTs them to the
 * tenant endpoint with an HMAC signature:
 *
 *   php spark connect:webhook-worker
 *   php spark connect:webhook-worker --once   # process a single batch, then exit
 *
 * Mirrors connect:worker (same daemon/--once contract, same config shape via
 * Connect::$webhookWorker*).
 */
class ConnectWebhookWorkerCommand extends BaseCommand
{
    protected $group       = 'Connect';
    protected $name        = 'connect:webhook-worker';
    protected $description = 'Runs the Humming Bird Connect webhook delivery worker.';

    public function run(array $params)
    {
        $processor    = new ConnectWebhookProcessor();
        $config       = config(ConnectConfig::class);
        $batchSize    = $config->webhookWorkerBatchSize;
        $sleepSeconds = $config->webhookWorkerSleepSeconds;

        $once = in_array('--once', $params, true) || in_array('-once', $params, true);

        echo "[ConnectWebhookWorker] batch={$batchSize} sleep={$sleepSeconds}s "
            . "maxAttempts={$config->webhookWorkerMaxAttempts} "
            . "staleTimeout={$config->webhookWorkerStaleTimeout}s"
            . ($once ? " [once]\n" : "\n");

        do {
            try {
                $start = microtime(true);
                $processed = $processor->run($batchSize);
                $elapsed = round(microtime(true) - $start, 3);

                if ($processed > 0) {
                    echo "[ConnectWebhookWorker] processed {$processed} delivery(ies) in {$elapsed}s\n";
                }
            } catch (Throwable $e) {
                echo "[ConnectWebhookWorker] pass error: " . $e->getMessage() . "\n";
                log_message('error', 'Connect webhook worker pass error: ' . $e->getMessage());
            }

            if ($once) {
                break;
            }

            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        } while (true);

        echo "[ConnectWebhookWorker] done\n";

        return EXIT_SUCCESS;
    }
}
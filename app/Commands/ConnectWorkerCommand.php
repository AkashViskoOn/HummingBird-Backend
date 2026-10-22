<?php

namespace App\Commands;

use App\Services\Connect\ConnectMessageProcessor;
use CodeIgniter\CLI\BaseCommand;
use Config\Connect as ConnectConfig;
use Throwable;

/**
 * Humming Bird Connect — background worker (Phase 5).
 *
 * Processes durably-queued connect_message_outbox records into the existing
 * Humming Bird message system. Run in daemon mode for continuous processing:
 *
 *   php spark connect:worker
 *   php spark connect:worker --once     # process a single batch, then exit
 *
 * The loop is safe to run under cron (--once) or pm2/upstart (daemon). Sleeps
 * `Connect::$workerSleepSeconds` between passes; processes up to
 * `Connect::$workerBatchSize` jobs per pass.
 */
class ConnectWorkerCommand extends BaseCommand
{
    protected $group       = 'Connect';
    protected $name        = 'connect:worker';
    protected $description = 'Runs the Humming Bird Connect outbox worker.';

    public function run(array $params)
    {
        $processor    = new ConnectMessageProcessor();
        $config       = config(ConnectConfig::class);
        $batchSize    = $config->workerBatchSize;
        $sleepSeconds = $config->workerSleepSeconds;

        $once = in_array('--once', $params, true) || in_array('-once', $params, true);

        echo "[ConnectWorker] worker id prefix={$config->workerIdPrefix} "
            . "batch={$batchSize} sleep={$sleepSeconds}s "
            . "maxAttempts={$config->workerMaxAttempts} "
            . "staleTimeout={$config->workerStaleTimeout}s"
            . ($once ? " [once]\n" : "\n");

        do {
            try {
                $start = microtime(true);
                $processed = $processor->run($batchSize);
                $elapsed = round(microtime(true) - $start, 3);

                if ($processed > 0) {
                    echo "[ConnectWorker] processed {$processed} job(s) in {$elapsed}s\n";
                }
            } catch (Throwable $e) {
                // Never kill the loop on a transient infrastructure error.
                echo "[ConnectWorker] pass error: " . $e->getMessage() . "\n";
                log_message('error', 'Connect worker pass error: ' . $e->getMessage());
            }

            if ($once) {
                break;
            }

            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        } while (true);

        echo "[ConnectWorker] done\n";

        return EXIT_SUCCESS;
    }
}

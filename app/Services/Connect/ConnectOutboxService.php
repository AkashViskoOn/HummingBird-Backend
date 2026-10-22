<?php

namespace App\Services\Connect;

use App\Models\Connect\ConnectMessageOutboxModel;
use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use DateTime;

/**
 * Humming Bird Connect — outbox claim / completion / retry / recovery (Phase 5).
 *
 * Row-level operations on `connect_message_outbox`. The outbox is a durable,
 * tenant-scoped processing queue that decouples the Connect API (which only
 * persists the accepted operation and returns HTTP 202) from the background
 * worker that finally creates the existing Humming Bird message.
 *
 * Concurrency: claiming is an atomic compare-and-set UPDATE
 * (`UPDATE ... SET status='processing', locked_* ... WHERE id=? AND status IN
 * (pending,retrying) AND available_at <= NOW()`) wrapped in a short
 * transaction. MySQL row-locks the matched row during the UPDATE and SQLite
 * serializes writes, so two concurrent workers can never both claim the same
 * job. The lock is only held long enough to claim — the actual message
 * creation / Socket / FCM work happens OUTSIDE this transaction.
 */
class ConnectOutboxService
{
    private ConnectMessageOutboxModel $outbox;

    public function __construct(?ConnectMessageOutboxModel $outbox = null)
    {
        $db = Database::connect(config(Database::class)->defaultGroup);
        $this->outbox = $outbox ?? new ConnectMessageOutboxModel($db);
    }

    /**
     * Atomically claim up to `$batchSize` due jobs for `$workerId`.
     *
     * Returns list of claimed outbox rows (already marked 'processing').
     * A job is claimable when it is 'pending' or 'retrying' AND its
     * available_at has passed. Stale 'processing' rows (locked too long) are
     * claimed by callers via recoverStale() first.
     */
    public function claimBatch(int $batchSize, string $workerId): array
    {
        $claimed = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $row = $this->claimOne($workerId);
            if ($row === null) {
                break;
            }
            $claimed[] = $row;
        }

        return $claimed;
    }

    /**
     * Claim a single due job. Returns the claimed row or null when none.
     */
    public function claimOne(string $workerId): ?array
    {
        $db = $this->db();
        $now = new DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');

        $db->transBegin();

        $row = $this->outbox
            ->whereIn('status', [ConnectConfig::OUTBOX_STATUS_PENDING, ConnectConfig::OUTBOX_STATUS_RETRYING])
            ->where('available_at <=', $nowStr)
            ->orderBy('available_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->first();

        if ($row === null || $row === []) {
            $db->transRollback();

            return null;
        }

        // Atomic claim: only succeeds if still claimable (guards the race when
        // a second worker selects the same row in the same instant).
        $db
            ->table('connect_message_outbox')
            ->where('id', $row['id'])
            ->whereIn('status', [ConnectConfig::OUTBOX_STATUS_PENDING, ConnectConfig::OUTBOX_STATUS_RETRYING])
            ->where('available_at <=', $nowStr)
            ->update([
                'status'     => ConnectConfig::OUTBOX_STATUS_PROCESSING,
                'locked_at'  => $nowStr,
                'locked_by'  => substr($workerId, 0, 64),
                'attempts'   => (int) $row['attempts'] + 1,
                'updated_at' => $nowStr,
            ]);

        $affected = (int) $db->affectedRows();

        if ($affected === 0) {
            $db->transRollback();

            return null;
        }

        $db->transCommit();

        return $this->outbox->find($row['id']);
    }

    /**
     * Recover jobs stuck in 'processing' for longer than `$staleTimeout`
     * seconds (a worker crashed mid-job). Returns them to 'retrying' so they
     * are re-claimed by the normal due scan. Returns the number recovered.
     */
    public function recoverStale(string $workerId, int $staleTimeout): int
    {
        $db = $this->db();
        $cutoff = (new DateTime())->modify("-{$staleTimeout} seconds")->format('Y-m-d H:i:s');
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $db->table('connect_message_outbox')
            ->where('status', ConnectConfig::OUTBOX_STATUS_PROCESSING)
            ->where('locked_at IS NOT NULL')
            ->where('locked_at <', $cutoff)
            ->set('status', ConnectConfig::OUTBOX_STATUS_RETRYING)
            ->set('available_at', $nowStr)
            ->set('locked_at', null)
            ->set('locked_by', null)
            ->set('last_error_code', ConnectConfig::OUTBOX_ERR_STALE_JOB)
            ->set('last_error_message', 'Processing job recovered after stale timeout')
            ->set('updated_at', $nowStr)
            ->update();

        return (int) max(0, (int) $db->affectedRows());
    }

    /**
     * Mark a job retrying (temporary failure). Increments nothing here — the
     * attempt counter is already bumped at claim time.
     */
    public function markRetrying(int $outboxId, string $errorCode, string $errorMessage): void
    {
        $delay = $this->retryDelayForAttempt((array) $this->outbox->find($outboxId));
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');
        $availableAt = (new DateTime())->modify("+{$delay} seconds")->format('Y-m-d H:i:s');

        $this->outbox->update($outboxId, [
            'status'              => ConnectConfig::OUTBOX_STATUS_RETRYING,
            'available_at'        => $availableAt,
            'locked_at'           => null,
            'locked_by'           => null,
            'last_error_code'     => substr($errorCode, 0, 64),
            'last_error_message'  => substr($errorMessage, 0, 1000),
            'updated_at'          => $nowStr,
        ]);
    }

    /**
     * Mark a job permanently failed (retry policy exhausted).
     */
    public function markFailed(int $outboxId, string $errorCode, string $errorMessage): void
    {
        $nowStr = (new DateTime())->format('Y-m-d H:i:s');

        $this->outbox->update($outboxId, [
            'status'              => ConnectConfig::OUTBOX_STATUS_FAILED,
            'locked_at'           => null,
            'locked_by'           => null,
            'last_error_code'     => substr($errorCode, 0, 64),
            'last_error_message'  => substr($errorMessage, 0, 1000),
            'updated_at'          => $nowStr,
        ]);
    }

    /**
     * Mark a job completed.
     */
    public function markCompleted(int $outboxId, ?string $processedAt = null): void
    {
        $nowStr = ($processedAt ?? (new DateTime())->format('Y-m-d H:i:s'));

        $this->outbox->update($outboxId, [
            'status'          => ConnectConfig::OUTBOX_STATUS_COMPLETED,
            'processed_at'    => $nowStr,
            'locked_at'       => null,
            'locked_by'       => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_at'      => $nowStr,
        ]);
    }

    /**
     * Exponential-backoff delay (seconds) for the next retry of `$row`.
     */
    public function retryDelayForAttempt(array $row): int
    {
        $attempt = (int) ($row['attempts'] ?? 1);
        $delays  = ConnectConfig::WORKER_RETRY_DELAYS;

        // Attempt N runs `$delays[N-1]` seconds after attempt N-1 failed.
        if ($attempt <= 1) {
            return (int) $delays[0];
        }

        $idx = $attempt - 1;

        return (int) ($delays[$idx] ?? $delays[count($delays) - 1]);
    }

    private function db(): BaseConnection
    {
        return Database::connect(config(Database::class)->defaultGroup);
    }
}

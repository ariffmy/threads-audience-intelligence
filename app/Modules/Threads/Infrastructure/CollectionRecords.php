<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use PDOStatement;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

/** Prepared, scoped persistence operations; caller application services own transactions. */
final readonly class CollectionRecords
{
    public function __construct(private QueueRepository $sql)
    {
    }

    public function latestExhaustedWindow(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("SELECT MAX(window_end) FROM threads_sync_runs WHERE organization_id=? AND threads_account_id=? AND mode='posts' AND status='exhausted' AND expires_at>UTC_TIMESTAMP(6)", $parameters);
    }

    public function lockAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT id FROM threads_accounts WHERE organization_id=? AND id=? FOR UPDATE', $parameters);
    }

    public function activeRunCount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("SELECT COUNT(*) FROM threads_sync_runs WHERE organization_id=? AND threads_account_id=? AND status IN ('pending','running')", $parameters);
    }

    public function insertRun(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('INSERT INTO threads_sync_runs (organization_id,threads_account_id,mode,root_id,root_version,window_start,window_end,privacy_epoch,credential_generation,grant_revision,expires_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))', $parameters);
    }

    public function lastInsertId(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT LAST_INSERT_ID()', $parameters);
    }

    public function insertCheckpoint(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("INSERT INTO threads_sync_checkpoints (organization_id,threads_account_id,run_id,cursor_hashes) VALUES (?,?,?,'[]')", $parameters);
    }
}

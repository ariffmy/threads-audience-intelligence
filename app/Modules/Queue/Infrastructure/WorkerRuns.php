<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Infrastructure;

use Tai\Modules\Queue\Application\WorkerBudget;

final readonly class WorkerRuns
{
    public function __construct(private QueueRepository $repository, private int $retentionDays)
    {
    }

    public function start(string $org, string $correlation): string
    {
        $this->repository->execute("INSERT INTO worker_runs (organization_id,correlation_id,status,expires_at) VALUES (?,?,'running',TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))", [$org, $correlation, $this->retentionDays]);
        return (string) $this->repository->execute('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function finish(string $org, string $id, string $status, int $processed, WorkerBudget $budget, ?string $code): void
    {
        $this->repository->execute('UPDATE worker_runs SET status=?,processed_jobs=?,duration_ms=?,peak_memory_bytes=?,safe_error_code=?,finished_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?', [$status, $processed, $budget->elapsedMs(), memory_get_peak_usage(true), $code, $org, $id]);
    }
}

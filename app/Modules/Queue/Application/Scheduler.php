<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

use Tai\Modules\Queue\Infrastructure\ScheduleRepository;

final readonly class Scheduler
{
    public function __construct(private QueueService $queue, private ScheduleRepository $repository)
    {
    }

    public function dispatchDue(string $organizationId): int
    {
        \Tai\Modules\Queue\Domain\JobPayload::identifier($organizationId);
        return $this->queue->transaction(function () use ($organizationId): int {
            // One maintenance schedule per tenant. No per-minute missed-slot backfill.
            $rows = $this->repository->due($organizationId);
            foreach ($rows as $row) {
                $this->queue->dispatch($organizationId, 'retention.sweep', [], 'schedule:' . $row['id'] . ':' . str_replace(['-', ' ', ':', '.'], '', $row['next_due_at']));
                $this->repository->advance($organizationId, (string) $row['id'], (int) $row['interval_seconds']);
            }
            return count($rows);
        });
    }
}

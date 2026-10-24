<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

final class WorkerBudget
{
    private readonly int $started;

    public function __construct(public readonly int $seconds, public readonly int $maxJobs, public readonly int $memoryMb)
    {
        if ($seconds < 1 || $seconds > 25 || $maxJobs < 1 || $maxJobs > 100 || $memoryMb < 16 || $memoryMb > 96) {
            throw new \InvalidArgumentException('Worker limits must fit 1–25 seconds, 1–100 jobs and 16–96 MiB.');
        }
        $this->started = hrtime(true);
    }

    public function canStart(int $processed = 0): bool
    {
        // Reserve one second for DB publication and exit bookkeeping.
        return $processed < $this->maxJobs && $this->elapsedMs() < ($this->seconds - 1) * 1000
            && memory_get_peak_usage(true) < $this->memoryMb * 1048576;
    }

    public function assertWithin(): void
    {
        if ($this->elapsedMs() >= $this->seconds * 1000 || memory_get_peak_usage(true) >= $this->memoryMb * 1048576) {
            throw new \Tai\Modules\Queue\Domain\BudgetExceeded('Worker budget exhausted.');
        }
    }

    public function elapsedMs(): int
    {
        return (int) ((hrtime(true) - $this->started) / 1000000);
    }
}

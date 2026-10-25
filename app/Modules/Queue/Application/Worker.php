<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

use Tai\Modules\Privacy\Application\PrivacyService;
use Tai\Modules\Queue\Domain\JobFailure;
use Tai\Modules\Queue\Domain\LostLease;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Queue\Infrastructure\WorkerRuns;
use Tai\Shared\Infrastructure\Logger;

final class Worker
{
    private bool $stopping = false;

    /** @param array<string, JobHandler> $handlers Explicit allowlist from composition root. */
    public function __construct(
        private readonly QueueService $queue,
        private readonly WorkerRuns $runs,
        private readonly Scheduler $scheduler,
        private readonly PrivacyService $privacy,
        private readonly array $handlers,
        private readonly Logger $logger,
        private readonly \Tai\Modules\Queue\Infrastructure\WorkerLock $locks,
        private readonly \Tai\Modules\Operations\Infrastructure\Maintenance $maintenance,
    ) {
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(string $organizationId, WorkerBudget $budget): array
    {
        JobPayload::identifier($organizationId);
        $lock = $this->locks->acquire($organizationId);
        if ($lock === null) {
            return ['status' => 'overlap', 'processed' => 0];
        }
        $runId = null;
        $processed = 0;
        $correlation = bin2hex(random_bytes(16));
        try {
            $runId = $this->runs->start($organizationId, $correlation);
            $this->logger->event('worker.started', ['organization_id' => $organizationId, 'correlation_id' => $correlation]);
            if ($budget->canStart()) {
                $this->privacy->configure($organizationId);
                $this->queue->recover($organizationId, 20);
                $this->scheduler->dispatchDue($organizationId);
            }
            while (!$this->stopping && $budget->canStart($processed)) {
                $claim = $this->queue->claim($organizationId);
                if ($claim === null) {
                    break;
                }
                $start = hrtime(true);
                try {
                    $handler = $this->handlers[$claim->type] ?? throw new JobFailure(false);
                    $result = $handler->prepare($claim, $budget);
                    $budget->assertWithin();
                    $this->queue->complete($claim, function () use ($handler, $claim, $result, $budget): void {
                        $handler->publish($claim, $result);
                        $budget->assertWithin();
                    }, (int) ((hrtime(true) - $start) / 1000000));
                } catch (LostLease) {
                    $this->logger->event('worker.lease_lost', ['organization_id' => $organizationId, 'correlation_id' => $claim->correlationId]);
                } catch (\Throwable $exception) {
                    $budgetExceeded = $exception instanceof \Tai\Modules\Queue\Domain\BudgetExceeded;
                    $databaseTransient = $exception instanceof \PDOException && in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 1969], true);
                    $providerTransient = $exception instanceof \Tai\Modules\Threads\Domain\ProviderFailure && $exception->transient;
                    try {
                        $this->queue->fail($claim, $budgetExceeded || $databaseTransient || $providerTransient || ($exception instanceof JobFailure && $exception->transient),
                            $budgetExceeded ? 'budget_exhausted' : ($providerTransient ? $exception->safeCode : ($exception instanceof JobFailure ? $exception->getMessage() : 'handler_failed')), (int) ((hrtime(true) - $start) / 1000000));
                    } catch (LostLease) {
                        // Leave changed/expired ownership to recovery; never publish a late result.
                    }
                    $this->logger->event('worker.job_failed', ['organization_id' => $organizationId, 'correlation_id' => $claim->correlationId, 'status' => 'failed']);
                }
                $processed++;
                if (str_starts_with($claim->type, 'threads.')) {
                    break; // One provider unit per whole invocation, including failed/ambiguous calls.
                }
            }
            if (!$this->stopping && $budget->canStart($processed)) {
                $this->maintenance->prune($organizationId);
            }
            $this->runs->finish($organizationId, $runId, 'succeeded', $processed, $budget, null);
            $this->logger->event('worker.finished', ['organization_id' => $organizationId, 'correlation_id' => $correlation, 'status' => 'succeeded']);
            return ['status' => 'succeeded', 'processed' => $processed, 'duration_ms' => $budget->elapsedMs(), 'peak_memory_bytes' => memory_get_peak_usage(true)];
        } catch (\Throwable $exception) {
            if ($runId !== null) {
                $this->runs->finish($organizationId, $runId, 'failed', $processed, $budget, 'worker_failed');
            }
            throw $exception;
        } finally {
            $this->locks->release($lock);
        }
    }

}

<?php

declare(strict_types=1);

namespace Tai\Modules\Privacy\Infrastructure;

use Tai\Modules\Privacy\Application\PrivacyService;
use Tai\Modules\Privacy\Domain\RetentionPolicy;
use Tai\Modules\Queue\Application\JobHandler;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\Claim;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

final readonly class RetentionHandler implements JobHandler
{
    public function __construct(private QueueRepository $repository, private QueueService $queue, private PrivacyService $privacy, private RetentionPolicy $policy, private PrivateFileStore $files, private ?\Tai\Modules\Threads\Application\ThreadsPrivacy $threads = null)
    {
    }

    public function prepare(Claim $claim, WorkerBudget $budget): array
    {
        $budget->assertWithin();
        return ['threads_intents' => $this->threads?->prepareSweep($claim->organizationId) ?? []];
    }

    public function publish(Claim $claim, array $result): void
    {
        // QueueService already owns the scope and job locks. All units are <=100 rows.
        $org = $claim->organizationId;
        $scope = $this->repository->scope($org);
        $blocked = $scope['blocked_at'] !== null;
        $threadsMore = $this->threads?->sweep($org, $result['threads_intents'] ?? []) ?? false;
        $filesRemoved = $this->files->purge($org);
        $effectsRemoved = $this->repository->execute('DELETE FROM demo_effects WHERE organization_id=? AND (expires_at<=UTC_TIMESTAMP(6) OR created_at<TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY id LIMIT 100', [$org, -$this->policy->sourceDays])->rowCount();
        $rows = $this->repository->execute("SELECT id FROM jobs WHERE organization_id=? AND job_type IN ('demo.page','demo.failure')
            AND (?=1 OR (status<>'running' AND (expires_at<=UTC_TIMESTAMP(6) OR created_at<TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))))) ORDER BY id LIMIT 100 FOR UPDATE", [$org, (int) $blocked, -$this->policy->logDays])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $this->repository->execute('DELETE FROM demo_effects WHERE organization_id=? AND job_id=?', [$org, $id]);
            $this->repository->execute('DELETE FROM job_attempts WHERE organization_id=? AND job_id=?', [$org, $id]);
            $this->repository->execute('DELETE FROM jobs WHERE organization_id=? AND id=?', [$org, $id]);
        }
        // Maintenance jobs/attempts have finite retention too; never delete the current claim.
        $old = $this->repository->execute("SELECT id FROM jobs WHERE organization_id=? AND job_type='retention.sweep' AND status IN ('succeeded','failed','cancelled') AND expires_at<=UTC_TIMESTAMP(6) ORDER BY id LIMIT 100", [$org])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($old as $id) {
            $this->repository->execute('DELETE FROM job_attempts WHERE organization_id=? AND job_id=?', [$org, $id]);
            $this->repository->execute('DELETE FROM jobs WHERE organization_id=? AND id=?', [$org, $id]);
        }
        $auditRemoved = $this->repository->execute('DELETE FROM audit_events WHERE organization_id=? AND (expires_at<=UTC_TIMESTAMP(6) OR occurred_at<TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY id LIMIT 100', [$org, -$this->policy->auditDays])->rowCount();
        $runsRemoved = $this->repository->execute("DELETE FROM worker_runs WHERE organization_id=? AND (expires_at<=UTC_TIMESTAMP(6) OR started_at<TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY id LIMIT 100", [$org, -$this->policy->logDays])->rowCount();
        $requestsCompleted = 0;
        if ($blocked) {
            $remaining = $this->repository->execute("SELECT id FROM jobs WHERE organization_id=? AND job_type IN ('demo.page','demo.failure') LIMIT 1", [$org])->fetchColumn();
            $fileRemaining = $this->repository->execute('SELECT id FROM private_files WHERE organization_id=? LIMIT 1', [$org])->fetchColumn();
            if ($remaining === false && $fileRemaining === false) {
                $requestsCompleted = $this->repository->execute("UPDATE deletion_requests SET status='completed',primary_completed_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND status='blocked' ORDER BY id LIMIT 100", [$org])->rowCount();
                if ($requestsCompleted > 0) {
                    $this->privacy->audit($org, 'privacy.purged');
                }
            }
        }
        if ($threadsMore || max(count($rows), count($old), $filesRemoved, $effectsRemoved, $auditRemoved, $runsRemoved, $requestsCompleted) === 100) {
            $this->queue->dispatch($org, 'retention.sweep', [], 'purge-next:' . $claim->id);
        }
        $this->privacy->audit($org, 'retention.swept');
    }
}

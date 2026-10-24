<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Infrastructure;

use Tai\Modules\Queue\Application\JobHandler;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\Claim;
use Tai\Modules\Queue\Domain\JobFailure;

final readonly class DemoHandler implements JobHandler
{
    public function __construct(private QueueRepository $repository, private QueueService $queue, private int $sourceDays = 90)
    {
    }

    public function prepare(Claim $claim, WorkerBudget $budget): array
    {
        $budget->assertWithin();
        if ($claim->type === 'demo.failure') {
            throw new JobFailure($claim->payload['mode'] === 'transient');
        }
        return ['value' => $claim->payload['page']];
    }

    public function publish(Claim $claim, array $result): void
    {
        $this->repository->execute('INSERT INTO demo_effects (organization_id,job_id,effect_value,expires_at) SELECT organization_id,id,?,LEAST(expires_at,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) FROM jobs WHERE organization_id=? AND id=?', [$result['value'], $this->sourceDays, $claim->organizationId, $claim->id]);
        $this->repository->execute('UPDATE jobs SET checkpoint_json=? WHERE organization_id=? AND id=?', [json_encode(['page' => $claim->payload['page']], JSON_THROW_ON_ERROR), $claim->organizationId, $claim->id]);
        if ($claim->payload['page'] < $claim->payload['pages']) {
            $payload = $claim->payload;
            $payload['page']++;
            $this->queue->dispatch($claim->organizationId, 'demo.page', $payload, 'demo:' . $payload['run'] . ':' . $payload['page']);
        }
    }

    public function visibleEffects(string $organizationId): array
    {
        \Tai\Modules\Queue\Domain\JobPayload::identifier($organizationId);
        return $this->repository->execute("SELECT e.effect_value FROM demo_effects e JOIN privacy_scopes s ON s.organization_id=e.organization_id JOIN organizations o ON o.id=e.organization_id WHERE e.organization_id=? AND s.blocked_at IS NULL AND o.status='active' AND o.blocked_at IS NULL AND e.expires_at>UTC_TIMESTAMP(6) AND e.created_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)) ORDER BY e.id LIMIT 100", [$organizationId, -$this->sourceDays])->fetchAll();
    }
}

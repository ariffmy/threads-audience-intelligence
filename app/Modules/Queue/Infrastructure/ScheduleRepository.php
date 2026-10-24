<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Infrastructure;

final readonly class ScheduleRepository
{
    public function __construct(private QueueRepository $repository)
    {
    }

    public function due(string $org): array
    {
        $this->repository->scope($org);
        $this->repository->execute("INSERT INTO scheduled_tasks (organization_id,schedule_key,interval_seconds,next_due_at) VALUES (?,'retention',3600,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE schedule_key=VALUES(schedule_key)", [$org]);
        return $this->repository->execute('SELECT id,next_due_at,interval_seconds FROM scheduled_tasks WHERE organization_id=? AND enabled=1 AND next_due_at<=UTC_TIMESTAMP(6) ORDER BY next_due_at,id LIMIT 10 FOR UPDATE', [$org])->fetchAll();
    }

    public function advance(string $org, string $id, int $seconds): void
    {
        $this->repository->execute('UPDATE scheduled_tasks SET next_due_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$seconds, $org, $id]);
    }
}

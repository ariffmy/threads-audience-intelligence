<?php

declare(strict_types=1);

namespace Tai\Modules\Operations\Infrastructure;

use PDO;

final class OperationsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recentEvents(string $organizationId): array
    {
        $query = $this->pdo->prepare('SELECT event_type,occurred_at FROM audit_events WHERE organization_id=? AND expires_at>UTC_TIMESTAMP(6) ORDER BY occurred_at DESC,id DESC LIMIT 5');
        $query->execute([$organizationId]);
        return $query->fetchAll();
    }

    public function queueStatistics(string $organizationId): array
    {
        \Tai\Modules\Queue\Domain\JobPayload::identifier($organizationId);
        $query = $this->pdo->prepare('SELECT status,COUNT(*) AS total FROM jobs WHERE organization_id=? GROUP BY status');
        $query->execute([$organizationId]);
        $counts = array_fill_keys(['ready', 'running', 'retry_wait', 'succeeded', 'failed', 'cancelled'], 0);
        foreach ($query->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $query = $this->pdo->prepare("SELECT MAX(IF(status='succeeded',finished_at,NULL)) AS last_success_at,MAX(started_at) AS last_started_at,
            SUM(status='failed') AS errors,SUM(processed_jobs) AS processed,MAX(duration_ms) AS longest_ms,MAX(peak_memory_bytes) AS peak_bytes,
            TIMESTAMPDIFF(SECOND,MAX(IF(status='succeeded',finished_at,NULL)),UTC_TIMESTAMP(6)) AS success_age_seconds
            FROM worker_runs WHERE organization_id=? AND expires_at>UTC_TIMESTAMP(6)");
        $query->execute([$organizationId]);
        $runs = $query->fetch();
        $query = $this->pdo->prepare("SELECT safe_error_code,finished_at FROM job_attempts WHERE organization_id=? AND safe_error_code IS NOT NULL AND expires_at>UTC_TIMESTAMP(6) ORDER BY id DESC LIMIT 5");
        $query->execute([$organizationId]);
        $errors = $query->fetchAll();
        $query = $this->pdo->prepare("SELECT COUNT(*) FROM worker_runs WHERE organization_id=? AND status='running' AND started_at<TIMESTAMPADD(SECOND,-60,UTC_TIMESTAMP(6))");
        $query->execute([$organizationId]);
        return ['counts' => $counts, 'last_success_at' => $runs['last_success_at'], 'last_started_at' => $runs['last_started_at'],
            'worker_errors' => (int) $runs['errors'], 'unfinished_runs' => (int) $query->fetchColumn(),
            'processed_jobs' => (int) $runs['processed'], 'longest_ms' => $runs['longest_ms'] === null ? null : (int) $runs['longest_ms'],
            'peak_bytes' => $runs['peak_bytes'] === null ? null : (int) $runs['peak_bytes'],
            'heartbeat' => $runs['success_age_seconds'] === null ? 'never_run' : ((int) $runs['success_age_seconds'] > 180 ? 'stale' : 'recent'), 'recent_errors' => $errors];
    }
}

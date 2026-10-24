<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Infrastructure;

use PDO;
use Tai\Modules\Queue\Domain\Claim;

final readonly class QueueRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $query = $this->pdo->prepare($sql);
        foreach (array_values($parameters) as $index => $value) {
            $query->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $query->execute();
        return $query;
    }

    public function scope(string $organizationId): array
    {
        $org = $this->execute('SELECT status,privacy_epoch,blocked_at FROM organizations WHERE id=? FOR UPDATE', [$organizationId])->fetch();
        if (!$org) {
            throw new \DomainException('Organization unavailable.');
        }
        $this->execute('INSERT INTO privacy_scopes (organization_id,privacy_epoch) VALUES (?,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id)', [$organizationId, $org['privacy_epoch']]);
        $scope = $this->execute('SELECT privacy_epoch,blocked_at FROM privacy_scopes WHERE organization_id=? FOR UPDATE', [$organizationId])->fetch();
        $scope['privacy_epoch'] = $org['privacy_epoch'];
        $scope['organization_active'] = $org['status'] === 'active' && $org['blocked_at'] === null;
        return $scope;
    }

    public function dispatch(string $organizationId, string $type, string $json, string $key, int $epoch, int $priority, int $attempts, ?string $availableAt, int $days): string
    {
        $this->execute("INSERT INTO jobs (organization_id,job_type,payload_json,dispatch_key,scope_privacy_epoch,priority,max_attempts,available_at,expires_at)
            VALUES (?,?,?,?,?,?,?,COALESCE(?,UTC_TIMESTAMP(6)),TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", [$organizationId, $type, $json, $key, $epoch, $priority, $attempts, $availableAt, $days]);
        $row = $this->execute('SELECT id,job_type,payload_json FROM jobs WHERE organization_id=? AND dispatch_key=?', [$organizationId, $key])->fetch();
        if ($row['job_type'] !== $type || $row['payload_json'] !== $json) {
            throw new \InvalidArgumentException('Dispatch key already has different work.');
        }
        return (string) $row['id'];
    }

    public function due(string $organizationId, bool $blocked): array|false
    {
        return $this->execute("SELECT * FROM jobs WHERE organization_id=? AND status IN ('ready','retry_wait')
            AND available_at<=UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6) AND attempts<max_attempts
            AND (?=0 OR job_type='retention.sweep') ORDER BY priority DESC,available_at,id LIMIT 1 FOR UPDATE", [$organizationId, (int) $blocked])->fetch();
    }

    public function current(string $organizationId, string $id): array|false
    {
        return $this->execute('SELECT *,lease_expires_at>UTC_TIMESTAMP(6) AS lease_valid FROM jobs WHERE organization_id=? AND id=? FOR UPDATE', [$organizationId, $id])->fetch();
    }

    public function reject(string $org, string $id, string $status, ?string $code): void
    {
        $this->execute('UPDATE jobs SET status=?,safe_error_code=?,finished_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?', [$status, $code, $org, $id]);
    }

    public function start(array $row, int $epoch, string $token, int $generation, int $leaseSeconds, string $correlation): void
    {
        $this->execute("UPDATE jobs SET status='running',attempts=attempts+1,claim_generation=?,lease_token_hash=?,
            lease_expires_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),scope_privacy_epoch=?,updated_at=UTC_TIMESTAMP(6),safe_error_code=NULL
            WHERE organization_id=? AND id=?", [$generation, hash('sha256', $token, true), $leaseSeconds, $epoch, $row['organization_id'], $row['id']]);
        $this->execute("INSERT INTO job_attempts (organization_id,job_id,attempt_no,claim_generation,correlation_id,status,expires_at)
            VALUES (?,?,?,?,?,'running',?)", [$row['organization_id'], $row['id'], (int) $row['attempts'] + 1, $generation, $correlation, $row['expires_at']]);
    }

    public function transition(Claim $claim, string $status, ?string $code, int $delay, int $durationMs): void
    {
        $this->execute("UPDATE jobs SET status=?,safe_error_code=?,available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),
            finished_at=IF(?='retry_wait',NULL,UTC_TIMESTAMP(6)),lease_token_hash=NULL,lease_expires_at=NULL,updated_at=UTC_TIMESTAMP(6)
            WHERE organization_id=? AND id=? AND claim_generation=?", [$status, $code, $delay, $status, $claim->organizationId, $claim->id, $claim->generation]);
        $this->execute('UPDATE job_attempts SET status=?,safe_error_code=?,finished_at=UTC_TIMESTAMP(6),duration_ms=?,peak_memory_bytes=? WHERE organization_id=? AND job_id=? AND claim_generation=?', [$status, $code, $durationMs, memory_get_peak_usage(true), $claim->organizationId, $claim->id, $claim->generation]);
    }

    public function expiredRunning(string $org, int $limit): array
    {
        return $this->execute("SELECT * FROM jobs WHERE organization_id=? AND status='running' AND lease_expires_at<=UTC_TIMESTAMP(6) ORDER BY lease_expires_at,id LIMIT ? FOR UPDATE", [$org, $limit])->fetchAll();
    }

    public function expirePending(string $org, int $limit): void
    {
        $this->execute("UPDATE jobs SET status='cancelled',safe_error_code='job_expired',finished_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
            WHERE organization_id=? AND status IN ('ready','retry_wait') AND expires_at<=UTC_TIMESTAMP(6) ORDER BY id LIMIT ?", [$org, $limit]);
    }
}

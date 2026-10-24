<?php

declare(strict_types=1);

namespace Tai\Modules\Privacy\Infrastructure;

use Tai\Modules\Privacy\Domain\RetentionPolicy;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

final readonly class PrivacyRepository
{
    public function __construct(private QueueRepository $repository)
    {
    }

    public function scope(string $org): array
    {
        return $this->repository->scope($org);
    }

    public function configure(string $org, RetentionPolicy $policy): void
    {
        $current = $this->repository->execute('SELECT source_days,export_days,log_days,audit_days,backup_days FROM retention_policies WHERE organization_id=? ORDER BY id DESC LIMIT 1', [$org])->fetch(\PDO::FETCH_NUM);
        if ($current === false || array_map('intval', $current) !== $policy->values()) {
            $this->repository->execute('INSERT INTO retention_policies (organization_id,source_days,export_days,log_days,audit_days,backup_days) VALUES (?,?,?,?,?,?)', [$org, ...$policy->values()]);
        }
    }

    public function hasRequest(string $org, string $key): bool
    {
        return $this->repository->execute('SELECT id FROM deletion_requests WHERE organization_id=? AND request_key=?', [$org, $key])->fetchColumn() !== false;
    }

    public function block(string $org, string $key, int $epoch, int $backupDays): void
    {
        $this->repository->execute('UPDATE organizations SET privacy_epoch=?,data_version=data_version+1 WHERE id=?', [$epoch, $org]);
        $this->repository->execute('UPDATE privacy_scopes SET privacy_epoch=?,blocked_at=COALESCE(blocked_at,UTC_TIMESTAMP(6)),updated_at=UTC_TIMESTAMP(6) WHERE organization_id=?', [$epoch, $org]);
        $this->repository->execute("INSERT INTO deletion_requests (organization_id,request_key,target_kind,scope_epoch,status,backup_expiry_at) VALUES (?,?,'synthetic_scope',?,'blocked',TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))", [$org, $key, $epoch, $backupDays]);
    }

    public function audit(string $org, string $event, int $days): void
    {
        $this->repository->execute('INSERT INTO audit_events (organization_id,event_type,safe_metadata_json,correlation_id,expires_at) VALUES (?,?,?, ?,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))', [$org, $event, '{}', bin2hex(random_bytes(16)), $days]);
    }
}

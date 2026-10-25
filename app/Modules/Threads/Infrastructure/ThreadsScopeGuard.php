<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use Tai\Modules\Queue\Application\ScopeGuard;
use Tai\Modules\Queue\Domain\LostLease;

final readonly class ThreadsScopeGuard implements ScopeGuard
{
    public function __construct(private ThreadsRepository $repository)
    {
    }

    public function lock(string $organizationId): void
    {
        // One account per organization, locked before every job (including retention).
        $this->repository->sql->execute('SELECT id FROM threads_accounts WHERE organization_id=? ORDER BY id FOR UPDATE', [$organizationId])->fetchAll();
    }

    public function dispatch(string $organizationId, string $type, array $payload, string $jobId): void
    {
        if (!str_starts_with($type, 'threads.')) {
            return;
        }
        $a = $this->repository->account($organizationId, $payload['account']);
        if ($a['blocked_at'] !== null || in_array($a['status'], ['disconnected', 'revoked', 'deleting', 'reauth_required'], true)
            || (isset($payload['run']) && !$this->repository->active($a))) {
            throw new \DomainException('Threads account is unavailable.');
        }
        $this->repository->sql->execute('UPDATE jobs SET threads_account_id=?,account_privacy_epoch=?,credential_generation=?,grant_revision=?
            WHERE organization_id=? AND id=? AND threads_account_id IS NULL',
            [$a['id'], (int) $a['privacy_epoch'], (int) $a['credential_generation'], (int) $a['grant_revision'], $organizationId, $jobId]);
    }

    public function eligible(array $row): bool
    {
        if ($row['threads_account_id'] === null) {
            return true;
        }
        try {
            $this->assertCurrent($row);
        } catch (LostLease) {
            $this->repository->sql->reject((string) $row['organization_id'], (string) $row['id'], 'cancelled', 'scope_changed');
            $this->failed((string) $row['organization_id'], (string) $row['id'], true, 'scope_changed');
            return false;
        }
        $a = $this->repository->account((string) $row['organization_id'], (string) $row['threads_account_id']);
        if ((bool) $a['request_busy'] || (bool) $a['rate_wait']) {
            // A pre-call deferral consumes no queue delivery attempt or provider reservation.
            $this->repository->sql->execute('UPDATE jobs SET available_at=GREATEST(COALESCE(?,UTC_TIMESTAMP(6)),COALESCE(?,UTC_TIMESTAMP(6)),TIMESTAMPADD(SECOND,1,UTC_TIMESTAMP(6))) WHERE organization_id=? AND id=?',
                [$a['request_until'], $a['next_allowed_at'], $row['organization_id'], $row['id']]);
            return false;
        }
        return true;
    }

    public function failed(string $organizationId, string $jobId, bool $terminal, string $code): void
    {
        $row = $this->repository->sql->execute('SELECT threads_account_id,payload_json,lease_token_hash FROM jobs WHERE organization_id=? AND id=?', [$organizationId, $jobId])->fetch();
        if (!$row || $row['threads_account_id'] === null) {
            return;
        }
        $payload = json_decode($row['payload_json'], true, 8);
        if ($terminal && isset($payload['run'])) {
            $this->repository->sql->execute("UPDATE threads_sync_runs SET status='partial',safe_error_code=? WHERE organization_id=? AND threads_account_id=? AND id=? AND status IN ('pending','running')", [$code, $organizationId, $row['threads_account_id'], $payload['run']]);
        }
        $this->repository->sql->execute('UPDATE threads_accounts SET safe_error_code=? WHERE organization_id=? AND id=?', [$code, $organizationId, $row['threads_account_id']]);
        $this->repository->sql->execute('UPDATE threads_accounts SET request_key=NULL,request_until=NULL WHERE organization_id=? AND id=? AND request_key=?',
            [$organizationId, $row['threads_account_id'], substr(bin2hex((string) $row['lease_token_hash']), 0, 32)]);
    }

    public function claimed(\Tai\Modules\Queue\Domain\Claim $claim, int $leaseSeconds): void
    {
        if (!str_starts_with($claim->type, 'threads.')) {
            return;
        }
        // Close the claim-to-prepare gap without counting a provider attempt before a call is prepared.
        $this->repository->sql->execute('UPDATE threads_accounts SET request_key=?,request_until=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?',
            [substr(hash('sha256', $claim->token), 0, 32), $leaseSeconds, $claim->organizationId, $claim->payload['account']]);
    }

    public function assertCurrent(array $row): void
    {
        if (!str_starts_with($row['job_type'], 'threads.')) {
            if ($row['threads_account_id'] !== null) {
                throw new LostLease('Invalid job scope.');
            }
            return;
        }
        if ($row['threads_account_id'] === null) {
            throw new LostLease('Missing account fence.');
        }
        $a = $this->repository->account((string) $row['organization_id'], (string) $row['threads_account_id']);
        $payload = json_decode($row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
        if (($payload['account'] ?? '') !== (string) $a['id'] || $a['blocked_at'] !== null
            || in_array($a['status'], ['disconnected', 'revoked', 'deleting', 'reauth_required'], true)
            || (int) $row['account_privacy_epoch'] !== (int) $a['privacy_epoch']
            || (int) $row['credential_generation'] !== (int) $a['credential_generation']
            || (int) $row['grant_revision'] !== (int) $a['grant_revision']) {
            throw new LostLease('Account fence changed.');
        }
        if (isset($payload['run'])) {
            $run = $this->repository->run((string) $a['organization_id'], (string) $a['id'], $payload['run']);
            if (!$this->repository->active($a) || !(bool) $run['valid'] || !in_array($run['status'], ['pending', 'running'], true)
                || (int) $run['privacy_epoch'] !== (int) $a['privacy_epoch'] || (int) $run['credential_generation'] !== (int) $a['credential_generation']
                || (int) $run['grant_revision'] !== (int) $a['grant_revision'] || (int) $run['version'] !== $payload['version']
                || $row['job_type'] !== 'threads.' . $run['mode']) {
                throw new LostLease('Collection fence changed.');
            }
            if ($run['root_id'] !== null) {
                try {
                    $root = $this->repository->source((string) $a['organization_id'], (string) $a['id'], (string) $run['root_id']);
                    if ($root['kind'] !== 'owned_post' || (int) $root['source_version'] !== (int) $run['root_version']) {
                        throw new \DomainException();
                    }
                } catch (\DomainException) {
                    throw new LostLease('Owned source fence changed.');
                }
            }
        }
    }
}

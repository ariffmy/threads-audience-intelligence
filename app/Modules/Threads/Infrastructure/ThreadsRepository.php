<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use Tai\Modules\Queue\Infrastructure\QueueRepository;
use Tai\Shared\Http\HttpException;

final readonly class ThreadsRepository
{
    public function __construct(public QueueRepository $sql, public int $sourceDays = 90, public int $logDays = 30, private int $idleSeconds = 1800)
    {
    }

    public function account(string $org, string $account): array
    {
        $row = $this->sql->execute('SELECT *,IF(profile_expires_at>UTC_TIMESTAMP(6) AND created_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)),username,NULL) AS username,token_expires_at>UTC_TIMESTAMP(6) AS token_valid,
            permission_expires_at>UTC_TIMESTAMP(6) AS permission_valid,
            request_until>UTC_TIMESTAMP(6) AS request_busy,next_allowed_at>UTC_TIMESTAMP(6) AS rate_wait
            FROM threads_accounts WHERE organization_id=? AND id=?', [-$this->sourceDays, $org, $account])->fetch();
        return $row ?: throw new HttpException(404, 'Threads account unavailable.');
    }

    public function authorize(array $identity): string
    {
        $org = (string) $identity['organization_id'];
        $row = $this->sql->execute("SELECT m.user_id FROM memberships m JOIN users u ON u.id=m.user_id
            JOIN organizations o ON o.id=m.organization_id JOIN auth_sessions s ON s.organization_id=m.organization_id AND s.user_id=m.user_id
            WHERE m.organization_id=? AND m.user_id=? AND s.id=? AND m.role='owner' AND m.status='active'
            AND u.status='active' AND u.is_installation_admin=1 AND s.revoked_at IS NULL
            AND s.session_version=u.session_version AND s.absolute_expires_at>UTC_TIMESTAMP(6)
            AND s.last_seen_at>TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) AND o.status='active' AND o.blocked_at IS NULL",
            [$org, (string) $identity['user_id'], (string) $identity['auth_session_id'], -$this->idleSeconds])->fetch();
        if (!$row) {
            throw new HttpException(403, 'Administrator authorization is required.');
        }
        return $org;
    }

    public function credential(string $org, string $account): array
    {
        return $this->sql->execute('SELECT *,expires_at>UTC_TIMESTAMP(6) AS valid,TIMESTAMPDIFF(SECOND,issued_at,UTC_TIMESTAMP(6)) AS age_seconds
            FROM threads_credentials WHERE organization_id=? AND threads_account_id=?', [$org, $account])->fetch()
            ?: throw new \DomainException('Credential unavailable.');
    }

    public function storeCredential(array $account, string $kind, string $envelope, string $expiry, bool $verified = false): void
    {
        $this->sql->execute('INSERT INTO threads_credentials (organization_id,threads_account_id,generation,kind,envelope,issued_at,expires_at,verified_at)
            VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),?,IF(?=1,UTC_TIMESTAMP(6),NULL))
            ON DUPLICATE KEY UPDATE generation=VALUES(generation),kind=VALUES(kind),envelope=VALUES(envelope),issued_at=VALUES(issued_at),expires_at=VALUES(expires_at),verified_at=VALUES(verified_at)',
            [$account['organization_id'], $account['id'], (int) $account['credential_generation'], $kind, $envelope, $expiry, (int) $verified]);
        $this->sql->execute('UPDATE threads_accounts SET token_expires_at=? WHERE organization_id=? AND id=?', [$expiry, $account['organization_id'], $account['id']]);
    }

    public function run(string $org, string $account, string $run): array
    {
        return $this->sql->execute('SELECT r.*,c.version,c.cursor_envelope,c.cursor_hashes,r.expires_at>UTC_TIMESTAMP(6) AS valid
            FROM threads_sync_runs r JOIN threads_sync_checkpoints c ON c.organization_id=r.organization_id AND c.threads_account_id=r.threads_account_id AND c.run_id=r.id
            WHERE r.organization_id=? AND r.threads_account_id=? AND r.id=?', [$org, $account, $run])->fetch()
            ?: throw new \DomainException('Collection unavailable.');
    }

    public function source(string $org, string $account, string $id): array
    {
        return $this->sql->execute('SELECT * FROM content_items WHERE organization_id=? AND threads_account_id=? AND id=?
            AND blocked_at IS NULL AND expires_at>UTC_TIMESTAMP(6) AND first_collected_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))',
            [$org, $account, $id, -$this->sourceDays])->fetch() ?: throw new \DomainException('Source unavailable.');
    }

    public function active(array $account): bool
    {
        if ($account['status'] !== 'active' || $account['blocked_at'] !== null || !(bool) $account['token_valid'] || !(bool) $account['permission_valid']) {
            return false;
        }
        return (int) $this->sql->execute("SELECT COUNT(*) FROM threads_account_grants g JOIN threads_credentials c ON c.organization_id=g.organization_id AND c.threads_account_id=g.threads_account_id
            WHERE g.organization_id=? AND g.threads_account_id=? AND g.revision=? AND g.expires_at>UTC_TIMESTAMP(6)
            AND c.generation=? AND c.kind='long' AND c.verified_at IS NOT NULL AND c.expires_at>UTC_TIMESTAMP(6)",
            [$account['organization_id'], $account['id'], (int) $account['grant_revision'], (int) $account['credential_generation']])->fetchColumn() === 2;
    }

    public function cancelWork(string $org, string $account, string $error): void
    {
        $this->sql->execute("UPDATE jobs SET status='cancelled',safe_error_code=?,lease_token_hash=NULL,lease_expires_at=NULL,finished_at=UTC_TIMESTAMP(6)
            WHERE organization_id=? AND threads_account_id=? AND status IN ('ready','retry_wait','running')", [$error, $org, $account]);
        $this->sql->execute("UPDATE job_attempts a JOIN jobs j ON j.organization_id=a.organization_id AND j.id=a.job_id
            SET a.status='cancelled',a.finished_at=UTC_TIMESTAMP(6),a.safe_error_code=?
            WHERE j.organization_id=? AND j.threads_account_id=? AND a.status='running'", [$error, $org, $account]);
        $this->sql->execute("UPDATE threads_sync_runs SET status='cancelled',safe_error_code=? WHERE organization_id=? AND threads_account_id=? AND status IN ('pending','running')", [$error, $org, $account]);
    }

    public function visible(string $org, string $account): array
    {
        $a = $this->account($org, $account);
        if (!$this->active($a)) {
            return [];
        }
        return $this->sql->execute('SELECT c.id,c.remote_media_id,c.kind,c.root_id,c.body,c.username,c.posted_at,c.permalink,c.source_version
            FROM content_items c LEFT JOIN content_items root ON root.organization_id=c.organization_id AND root.threads_account_id=c.threads_account_id AND root.id=c.root_id
            WHERE c.organization_id=? AND c.threads_account_id=? AND c.blocked_at IS NULL AND c.expires_at>UTC_TIMESTAMP(6)
            AND c.first_collected_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))
            AND (c.root_id IS NULL OR (root.blocked_at IS NULL AND root.expires_at>UTC_TIMESTAMP(6) AND root.first_collected_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))))
            ORDER BY c.id LIMIT 100', [$org, $account, -$this->sourceDays, -$this->sourceDays])->fetchAll();
    }

    public function overview(array $identity): array
    {
        $org = $this->authorize($identity);
        $id = $this->sql->execute('SELECT id FROM threads_accounts WHERE organization_id=?', [$org])->fetchColumn();
        $account = $id === false ? null : $this->account($org, (string) $id);
        $items = $account === null ? [] : $this->visible($org, (string) $id);
        $grants = $account === null ? [] : $this->sql->execute('SELECT permission,expires_at FROM threads_account_grants WHERE organization_id=? AND threads_account_id=? ORDER BY permission', [$org, $id])->fetchAll();
        $runs = $account === null || $account['blocked_at'] !== null ? [] : $this->sql->execute('SELECT id,mode,status,pages,records_seen,records_retained,unknown_time,safe_error_code,window_start,window_end
            FROM threads_sync_runs WHERE organization_id=? AND threads_account_id=? AND expires_at>UTC_TIMESTAMP(6) AND created_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)) ORDER BY id DESC LIMIT 10', [$org, $id, -$this->logDays])->fetchAll();
        return compact('account', 'items', 'grants', 'runs');
    }
}

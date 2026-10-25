<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use PDOStatement;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

/** Prepared, scoped persistence operations; caller application services own transactions. */
final readonly class PrivacyRecords
{
    public function __construct(private QueueRepository $sql)
    {
    }

    public function organizationExists(array $parameters): PDOStatement
    {
        return $this->sql->execute('SELECT id FROM organizations WHERE id=?', $parameters);
    }

    public function lockAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT id FROM threads_accounts WHERE organization_id=? AND id=? FOR UPDATE', $parameters);
    }

    public function markRevoked(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_accounts SET status='revoked' WHERE organization_id=? AND id=? AND blocked_at IS NOT NULL", $parameters);
    }

    public function lockScopeAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT * FROM threads_accounts WHERE organization_id=? AND scope_key=? FOR UPDATE', $parameters);
    }

    public function request(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT target_kind,scope_key,source_hash FROM threads_deletions WHERE organization_id=? AND request_key=?', $parameters);
    }

    public function insertIntent(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('INSERT INTO threads_deletions (organization_id,request_key,target_kind,scope_key,source_hash) VALUES (?,?,?,?,?)', $parameters);
    }

    public function bumpFence(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET privacy_epoch=privacy_epoch+1,credential_generation=credential_generation+?,request_key=NULL,request_until=NULL WHERE organization_id=? AND id=?', $parameters);
    }

    public function disconnect(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_accounts SET blocked_at=COALESCE(blocked_at,UTC_TIMESTAMP(6)),status='disconnected',safe_error_code='privacy_blocked',token_expires_at=NULL,permission_expires_at=NULL WHERE organization_id=? AND id=?", $parameters);
    }

    public function deleteCredentials(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_credentials WHERE organization_id=? AND threads_account_id=?', $parameters);
    }

    public function deleteGrants(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_account_grants WHERE organization_id=? AND threads_account_id=?', $parameters);
    }

    public function deleteStates(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_oauth_states WHERE organization_id=? AND threads_account_id=?', $parameters);
    }

    public function reopenIntent(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_deletions SET status='blocked',primary_completed_at=NULL WHERE organization_id=? AND request_key=?", $parameters);
    }

    public function blockSuppressedSources(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE content_items c JOIN threads_deletions d ON d.organization_id=c.organization_id AND d.scope_key=?
            AND d.target_kind='threads_source' AND d.source_hash=c.suppression_hash
            SET c.blocked_at=COALESCE(c.blocked_at,UTC_TIMESTAMP(6)) WHERE c.organization_id=? AND c.threads_account_id=?", $parameters);
    }

    public function isSuppressed(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("SELECT 1 FROM threads_deletions WHERE organization_id=? AND
            (target_kind='threads_organization' OR (scope_key=? AND (target_kind='threads_account' OR source_hash=?))) LIMIT 1", $parameters);
    }

    public function account(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT * FROM threads_accounts WHERE organization_id=?', $parameters);
    }

    public function expiredSources(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT suppression_hash FROM content_items WHERE organization_id=? AND threads_account_id=? AND blocked_at IS NULL
            AND (expires_at<=UTC_TIMESTAMP(6) OR first_collected_at<=TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY expires_at,id LIMIT 10', $parameters);
    }

    public function purgeExpiredHandle(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET username=NULL,profile_expires_at=NULL WHERE organization_id=? AND id=? AND (profile_expires_at<=UTC_TIMESTAMP(6) OR created_at<=TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))', $parameters);
    }

    public function purgeExpiredGrants(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_account_grants WHERE organization_id=? AND threads_account_id=? AND expires_at<=UTC_TIMESTAMP(6)', $parameters);
    }

    public function purgeExpiredCredential(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_credentials WHERE organization_id=? AND threads_account_id=? AND expires_at<=UTC_TIMESTAMP(6)', $parameters);
    }

    public function purgeStates(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_oauth_states WHERE organization_id=? AND threads_account_id=? AND (expires_at<=UTC_TIMESTAMP(6) OR consumed_at IS NOT NULL)', $parameters);
    }

    public function purgeRequests(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_request_reservations WHERE organization_id=? AND threads_account_id=? AND (?=1 OR expires_at<=UTC_TIMESTAMP(6) OR started_at<=TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY started_at LIMIT 100', $parameters);
    }

    public function expiredRuns(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT r.id FROM threads_sync_runs r LEFT JOIN content_items c ON c.organization_id=r.organization_id AND c.threads_account_id=r.threads_account_id AND c.id=r.root_id
            WHERE r.organization_id=? AND r.threads_account_id=? AND (?=1 OR r.expires_at<=UTC_TIMESTAMP(6) OR r.created_at<=TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)) OR c.blocked_at IS NOT NULL OR c.expires_at<=UTC_TIMESTAMP(6)) ORDER BY r.id LIMIT 100', $parameters);
    }

    public function deleteCheckpoint(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_sync_checkpoints WHERE organization_id=? AND threads_account_id=? AND run_id=?', $parameters);
    }

    public function deleteRun(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM threads_sync_runs WHERE organization_id=? AND threads_account_id=? AND id=?', $parameters);
    }

    public function purgeReplies(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("DELETE c FROM content_items c JOIN (
            SELECT x.id FROM content_items x LEFT JOIN content_items root ON root.organization_id=x.organization_id AND root.threads_account_id=x.threads_account_id AND root.id=x.root_id
            WHERE x.organization_id=? AND x.threads_account_id=? AND x.kind='reply' AND (?=1 OR x.blocked_at IS NOT NULL OR x.expires_at<=UTC_TIMESTAMP(6) OR root.blocked_at IS NOT NULL OR root.expires_at<=UTC_TIMESTAMP(6))
            ORDER BY x.id LIMIT 100) batch ON batch.id=c.id", $parameters);
    }

    public function unreferencedRoots(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("SELECT c.id FROM content_items c WHERE c.organization_id=? AND c.threads_account_id=? AND c.kind='owned_post'
            AND (?=1 OR c.blocked_at IS NOT NULL OR c.expires_at<=UTC_TIMESTAMP(6))
            AND NOT EXISTS (SELECT 1 FROM content_items child WHERE child.organization_id=c.organization_id AND child.threads_account_id=c.threads_account_id AND child.root_id=c.id)
            AND NOT EXISTS (SELECT 1 FROM threads_sync_runs r WHERE r.organization_id=c.organization_id AND r.threads_account_id=c.threads_account_id AND r.root_id=c.id)
            ORDER BY c.id LIMIT 100", $parameters);
    }

    public function deleteSource(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM content_items WHERE organization_id=? AND threads_account_id=? AND id=?', $parameters);
    }

    public function expiredJobs(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("SELECT id FROM jobs WHERE organization_id=? AND threads_account_id=? AND status<>'running'
            AND (?=1 OR expires_at<=UTC_TIMESTAMP(6)) ORDER BY id LIMIT 100", $parameters);
    }

    public function deleteAttempts(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM job_attempts WHERE organization_id=? AND job_id=?', $parameters);
    }

    public function deleteJob(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('DELETE FROM jobs WHERE organization_id=? AND id=?', $parameters);
    }

    public function hasSources(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT 1 FROM content_items WHERE organization_id=? AND threads_account_id=? LIMIT 1', $parameters);
    }

    public function clearIdentity(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET remote_user_id=NULL,username=NULL,last_sync_at=NULL,window_started_at=NULL,window_requests=0,next_allowed_at=NULL WHERE organization_id=? AND id=?', $parameters);
    }

    public function completeAccountIntents(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_deletions SET status='primary_complete',primary_completed_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND scope_key=?", $parameters);
    }

    public function completeSourceIntents(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_deletions d SET d.status='primary_complete',d.primary_completed_at=UTC_TIMESTAMP(6)
                WHERE d.organization_id=? AND d.scope_key=? AND d.target_kind='threads_source'
                AND NOT EXISTS (SELECT 1 FROM content_items c WHERE c.organization_id=d.organization_id AND c.threads_account_id=? AND c.suppression_hash=d.source_hash)", $parameters);
    }
}

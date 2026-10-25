<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use PDOStatement;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

/** Prepared, scoped persistence operations; caller application services own transactions. */
final readonly class AccountRecords
{
    public function __construct(private QueueRepository $sql)
    {
    }

    public function hasOrganizationSuppression(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("SELECT 1 FROM threads_deletions WHERE organization_id=? AND target_kind='threads_organization' LIMIT 1", $parameters);
    }

    public function lockOrganizationAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT * FROM threads_accounts WHERE organization_id=? FOR UPDATE', $parameters);
    }

    public function insertAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('INSERT INTO threads_accounts (organization_id,scope_key) VALUES (?,?)', $parameters);
    }

    public function lastInsertId(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT LAST_INSERT_ID()', $parameters);
    }

    public function beginConnection(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_accounts SET status='pending',credential_generation=credential_generation+1,grant_revision=grant_revision+1,
                permission_expires_at=NULL,token_expires_at=NULL,request_key=NULL,request_until=NULL,safe_error_code=NULL WHERE organization_id=? AND id=?", $parameters);
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

    public function insertState(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('INSERT INTO threads_oauth_states (state_hash,organization_id,threads_account_id,user_id,auth_session_id,session_hash,redirect_uri,expected_generation,expected_epoch,expires_at)
                VALUES (?,?,?,?,?,?,?,?,?,TIMESTAMPADD(MINUTE,10,UTC_TIMESTAMP(6)))', $parameters);
    }

    public function lockAccounts(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT id FROM threads_accounts WHERE organization_id=? FOR UPDATE', $parameters);
    }

    public function lockState(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT *,expires_at>UTC_TIMESTAMP(6) AS valid FROM threads_oauth_states WHERE state_hash=? AND organization_id=? FOR UPDATE', $parameters);
    }

    public function consumeState(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_oauth_states SET consumed_at=UTC_TIMESTAMP(6) WHERE state_hash=?', $parameters);
    }

    public function saveIdentity(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET remote_user_id=? WHERE organization_id=? AND id=?', $parameters);
    }

    public function requireReauthorization(array $parameters = []): PDOStatement
    {
        return $this->sql->execute("UPDATE threads_accounts SET status='reauth_required',safe_error_code=? WHERE organization_id=? AND id=?", $parameters);
    }

    public function lockAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('SELECT id FROM threads_accounts WHERE organization_id=? AND id=? FOR UPDATE', $parameters);
    }

    public function invalidateCredential(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET credential_generation=credential_generation+1 WHERE organization_id=? AND id=?', $parameters);
    }

    public function replaceEnvelope(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_credentials SET envelope=?,generation=generation+1 WHERE organization_id=? AND threads_account_id=?', $parameters);
    }
}

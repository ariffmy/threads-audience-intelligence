<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use PDOStatement;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

/** Prepared, scoped persistence operations; caller application services own transactions. */
final readonly class RequestRecords
{
    public function __construct(private QueueRepository $sql)
    {
    }

    public function resetWindow(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET window_requests=IF(window_started_at IS NULL OR window_started_at<=TIMESTAMPADD(HOUR,-1,UTC_TIMESTAMP(6)),0,window_requests),
            window_started_at=IF(window_started_at IS NULL OR window_started_at<=TIMESTAMPADD(HOUR,-1,UTC_TIMESTAMP(6)),UTC_TIMESTAMP(6),window_started_at) WHERE organization_id=? AND id=?', $parameters);
    }

    public function reserveAccount(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET request_key=?,request_until=TIMESTAMPADD(SECOND,90,UTC_TIMESTAMP(6)),window_requests=window_requests+1,
            next_allowed_at=IF(window_requests>=60,TIMESTAMPADD(HOUR,1,window_started_at),NULL) WHERE organization_id=? AND id=?', $parameters);
    }

    public function insertReservation(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('INSERT INTO threads_request_reservations (organization_id,threads_account_id,request_key,operation,credential_generation,expires_at)
            VALUES (?,?,?,?,?,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))', $parameters);
    }

    public function finishReservation(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_request_reservations SET status=?,safe_error_code=? WHERE organization_id=? AND threads_account_id=? AND request_key=?', $parameters);
    }

    public function releaseReservation(array $parameters = []): PDOStatement
    {
        return $this->sql->execute('UPDATE threads_accounts SET request_key=NULL,request_until=NULL,
            next_allowed_at=IF(?=1,GREATEST(COALESCE(next_allowed_at,UTC_TIMESTAMP(6)),TIMESTAMPADD(SECOND,60,UTC_TIMESTAMP(6))),next_allowed_at)
            WHERE organization_id=? AND id=? AND request_key=?', $parameters);
    }
}

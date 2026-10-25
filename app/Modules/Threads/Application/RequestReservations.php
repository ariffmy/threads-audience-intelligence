<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Application;

use Tai\Modules\Threads\Infrastructure\ThreadsRepository;

final readonly class RequestReservations
{
    public function __construct(private ThreadsRepository $repository, private \Tai\Modules\Threads\Infrastructure\RequestRecords $records)
    {
    }

    /** Caller holds organization/account locks; no remote call occurs in this transaction. */
    public function reserve(array $a, string $operation, ?string $claimReservation = null): string
    {
        if (($a['request_busy'] && ($claimReservation === null || !hash_equals((string) $a['request_key'], $claimReservation))) || $a['rate_wait']) {
            throw new \DomainException('Fixture request is deferred.');
        }
        $this->records->resetWindow([$a['organization_id'], $a['id']]);
        $current = $this->repository->account((string) $a['organization_id'], (string) $a['id']);
        if ((int) $current['window_requests'] >= 60) {
            throw new \DomainException('Fixture request allowance exhausted.');
        }
        $key = bin2hex(random_bytes(16));
        $this->records->reserveAccount([$key, $a['organization_id'], $a['id']]);
        $this->records->insertReservation([$a['organization_id'], $a['id'], $key, $operation, (int) $a['credential_generation'], $this->repository->logDays]);
        return $key;
    }

    public function finish(array $a, string $key, ?string $error = null): void
    {
        $this->records->finishReservation(
            [$error === null ? 'succeeded' : 'failed', $error, $a['organization_id'], $a['id'], $key]);
        $this->records->releaseReservation([(int) ($error === 'provider_throttled'), $a['organization_id'], $a['id'], $key]);
    }
}

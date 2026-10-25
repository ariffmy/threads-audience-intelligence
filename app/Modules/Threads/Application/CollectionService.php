<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Application;

use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Threads\Infrastructure\ThreadsRepository;
use Tai\Shared\Domain\UserFacingException;

final readonly class CollectionService
{
    public function __construct(private ThreadsRepository $repository, private QueueService $queue, private AccountService $accounts, private \Tai\Modules\Threads\Infrastructure\CollectionRecords $records)
    {
    }

    public function incremental(array $identity, string $account, string $end): string
    {
        $org = $this->repository->authorize($identity);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $end, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $end) {
            throw new UserFacingException('Select a valid UTC end time.');
        }
        $last = $this->records->latestExhaustedWindow([$org, $account])->fetchColumn();
        $endTime = $date->getTimestamp();
        $startTime = $last === null || $last === false ? $endTime - 2592000 : max($endTime - 2592000, min($endTime - 1, strtotime($last . ' UTC') - 3600));
        return $this->start($identity, $account, 'posts', gmdate('Y-m-d H:i:s', $startTime), $end);
    }

    public function start(array $identity, string $account, string $mode, string $start, string $end, ?string $root = null): string
    {
        $this->accounts->assertFixtureEnabled();
        JobPayload::identifier($account);
        if ($root !== null) {
            JobPayload::identifier($root);
        }
        foreach ([$start, $end] as $value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
                throw new UserFacingException('Select a valid UTC collection window.');
            }
        }
        $length = strtotime($end . ' UTC') - strtotime($start . ' UTC');
        if (!in_array($mode, ['posts', 'replies', 'conversation'], true) || $length < 1 || $length > 2592000 || (($mode === 'posts') !== ($root === null))) {
            throw new UserFacingException('Select one owned root and a collection window of at most 30 days.');
        }
        return $this->queue->transaction(function () use ($identity, $account, $mode, $start, $end, $root): string {
            $org = $this->repository->authorize($identity);
            $this->repository->sql->scope($org);
            $this->records->lockAccount([$org, $account])->fetch();
            $a = $this->repository->account($org, $account);
            if (!$this->repository->active($a)) {
                throw new UserFacingException('The fixture account requires valid identity and grants before collection.');
            }
            $source = $root === null ? null : $this->repository->source($org, $account, $root);
            if ($source !== null && $source['kind'] !== 'owned_post') {
                throw new UserFacingException('Only a root verified through the owned-post list can be collected.');
            }
            $active = (int) $this->records->activeRunCount([$org, $account])->fetchColumn();
            if ($active >= 10) {
                throw new UserFacingException('The fixture has reached its pending collection limit.');
            }
            $this->records->insertRun([$org, $account, $mode, $root, $source === null ? null : (int) $source['source_version'], $start, $end,
                    (int) $a['privacy_epoch'], (int) $a['credential_generation'], (int) $a['grant_revision'], $this->repository->logDays]);
            $run = (string) $this->records->lastInsertId()->fetchColumn();
            $this->records->insertCheckpoint([$org, $account, $run]);
            $this->queue->dispatch($org, 'threads.' . $mode, ['account' => $account, 'run' => $run, 'version' => 1], 'threads:page:' . $run . ':1');
            return $run;
        });
    }
}

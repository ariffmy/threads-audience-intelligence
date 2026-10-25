<?php

declare(strict_types=1);

namespace Tai\Bootstrap;

use Tai\Modules\Privacy\Infrastructure\DeletionLedger;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Threads\Application\AccountService;
use Tai\Modules\Threads\Application\CollectionService;
use Tai\Modules\Threads\Application\RequestReservations;
use Tai\Modules\Threads\Application\ThreadsClient;
use Tai\Modules\Threads\Application\ThreadsPrivacy;
use Tai\Modules\Threads\Infrastructure\DisabledTransport;
use Tai\Modules\Threads\Infrastructure\FixtureTransport;
use Tai\Modules\Threads\Infrastructure\ThreadsJobHandler;
use Tai\Modules\Threads\Infrastructure\ThreadsRepository;
use Tai\Modules\Threads\Infrastructure\TokenVault;
use Tai\Shared\Infrastructure\Config;

final readonly class ThreadsComposition
{
    public AccountService $accounts;
    public CollectionService $collection;
    public ThreadsPrivacy $privacy;
    public ThreadsJobHandler $handler;
    public ThreadsClient $client;

    public function __construct(public ThreadsRepository $repository, QueueService $queue, Config $config, ?FixtureTransport $fixture = null)
    {
        $enabled = $config->get('app.env') !== 'production' && $config->get('threads.fixture_enabled') === true;
        $transport = $enabled ? ($fixture ?? new FixtureTransport()) : new DisabledTransport();
        $this->client = new ThreadsClient($transport, rtrim($config->get('app.url'), '/') . '/threads/oauth/callback');
        $reservations = new RequestReservations($repository, new \Tai\Modules\Threads\Infrastructure\RequestRecords($repository->sql));
        $ledger = new DeletionLedger($config, true);
        $vault = static function () use ($config, $ledger): TokenVault {
            $ledger->identifierHash('1', str_repeat('0', 32), '0');
            return TokenVault::fromFile($config);
        };
        $this->privacy = new ThreadsPrivacy($repository, $queue, $ledger, new \Tai\Modules\Threads\Infrastructure\PrivacyRecords($repository->sql));
        $this->accounts = new AccountService($repository, $queue, $this->client, $reservations, $config, $vault, new \Tai\Modules\Threads\Infrastructure\AccountRecords($repository->sql));
        $this->collection = new CollectionService($repository, $queue, $this->accounts, new \Tai\Modules\Threads\Infrastructure\CollectionRecords($repository->sql));
        $this->handler = new ThreadsJobHandler($repository, $queue, $this->client, $reservations, $this->accounts, $this->privacy, $vault);
    }
}

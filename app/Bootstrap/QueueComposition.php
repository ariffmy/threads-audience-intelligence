<?php

declare(strict_types=1);

namespace Tai\Bootstrap;

use PDO;
use Tai\Modules\Privacy\Application\PrivacyService;
use Tai\Modules\Privacy\Domain\RetentionPolicy;
use Tai\Modules\Privacy\Infrastructure\DeletionLedger;
use Tai\Modules\Privacy\Infrastructure\RetentionHandler;
use Tai\Modules\Privacy\Infrastructure\PrivateFileStore;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Application\RetryPolicy;
use Tai\Modules\Queue\Application\Scheduler;
use Tai\Modules\Queue\Application\Worker;
use Tai\Modules\Queue\Infrastructure\DemoHandler;
use Tai\Modules\Queue\Infrastructure\QueueRepository;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Logger;

final readonly class QueueComposition
{
    public QueueRepository $repository;
    public QueueService $queue;
    public PrivacyService $privacy;
    public DeletionLedger $ledger;
    public DemoHandler $demo;
    public Worker $worker;
    public PrivateFileStore $files;
    public ThreadsComposition $threads;

    public function __construct(PDO $pdo, Config $config, Logger $logger, ?\Tai\Modules\Threads\Infrastructure\FixtureTransport $fixture = null)
    {
        // Bound lock waits and individual statements on supported MariaDB servers.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
        $pdo->exec('SET SESSION max_statement_time=1');
        $this->repository = new QueueRepository($pdo);
        $threadsRepository = new \Tai\Modules\Threads\Infrastructure\ThreadsRepository($this->repository, $config->get('privacy.source_days'), $config->get('privacy.log_days'), $config->get('session.idle_seconds'));
        $this->queue = new QueueService($pdo, $this->repository, new RetryPolicy($config->get('queue.retry_base_seconds'), $config->get('queue.retry_cap_seconds')), $config->get('queue.lease_seconds'), $config->get('privacy.log_days'), new \Tai\Modules\Threads\Infrastructure\ThreadsScopeGuard($threadsRepository));
        $this->threads = new ThreadsComposition($threadsRepository, $this->queue, $config, $fixture);
        $policy = new RetentionPolicy($config->get('privacy.source_days'), $config->get('privacy.export_days'), $config->get('privacy.log_days'), $config->get('privacy.audit_days'), $config->get('privacy.backup_days'));
        $this->ledger = new DeletionLedger($config);
        $this->privacy = new PrivacyService($this->queue, new \Tai\Modules\Privacy\Infrastructure\PrivacyRepository($this->repository), $this->ledger, $policy);
        $this->demo = new DemoHandler($this->repository, $this->queue, $policy->sourceDays);
        $this->files = new PrivateFileStore($this->queue, $this->repository, $config);
        $retention = new RetentionHandler($this->repository, $this->queue, $this->privacy, $policy, $this->files, $this->threads->privacy);
        $this->worker = new Worker($this->queue, new \Tai\Modules\Queue\Infrastructure\WorkerRuns($this->repository, $policy->logDays),
            new Scheduler($this->queue, new \Tai\Modules\Queue\Infrastructure\ScheduleRepository($this->repository)), $this->privacy,
            ['demo.page' => $this->demo, 'demo.failure' => $this->demo, 'retention.sweep' => $retention,
                'threads.token' => $this->threads->handler, 'threads.validate' => $this->threads->handler,
                'threads.posts' => $this->threads->handler, 'threads.replies' => $this->threads->handler,
                'threads.conversation' => $this->threads->handler], $logger,
            new \Tai\Modules\Queue\Infrastructure\WorkerLock($config->storage('locks')), new \Tai\Modules\Operations\Infrastructure\Maintenance($pdo, $config));
    }
}

<?php

declare(strict_types=1);

use Tai\Bootstrap\QueueComposition;
use Tai\Modules\Operations\Infrastructure\OperationsRepository;
use Tai\Modules\Queue\Application\Scheduler;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\Claim;
use Tai\Modules\Queue\Domain\LostLease;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Logger;
use TaiTests\Suite;
use function TaiTests\check;
use function TaiTests\raises;

return static function (Suite $suite, Config $config, PDO $pdo, string $directory, string $configFile, array $settings): void {
    check($config->get('app.env') === 'test' && preg_match('/^tai_test_[a-f0-9]{16}$/D', $config->get('database.name')) === 1);
    check(strlen((string) $pdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn()) === 32);
    $settings['privacy'] = ['ledger_path' => $directory . '/independent-ledger', 'ledger_key' => bin2hex(random_bytes(32))];
    $queueFile = $directory . '/queue-config.php';
    file_put_contents($queueFile, '<?php return ' . var_export($settings, true) . ';');
    putenv('TAI_CONFIG_FILE=' . $queueFile);
    $queueConfig = Config::load($config->root);
    $runtime = new QueueComposition($pdo, $queueConfig, new Logger($queueConfig->storage('logs')));
    $repo = $runtime->repository;
    $newOrg = static function () use ($repo): string {
        $repo->execute('INSERT INTO organizations (name,slug) VALUES (?,?)', ['Queue synthetic fixture', 'queue-' . bin2hex(random_bytes(8))]);
        return (string) $repo->execute('SELECT LAST_INSERT_ID()')->fetchColumn();
    };
    $dispatch = static function (string $org, int $pages = 1, int $attempts = 5) use ($runtime): string {
        $run = bin2hex(random_bytes(16));
        return $runtime->queue->dispatch($org, 'demo.page', ['page' => 1, 'pages' => $pages, 'run' => $run], 'demo:' . $run . ':1', 0, $attempts);
    };
    $state = static fn (string $org, string $id): array => $repo->execute('SELECT * FROM jobs WHERE organization_id=? AND id=?', [$org, $id])->fetch();
    $publish = static function (Claim $claim) use ($runtime): void {
        $result = $runtime->demo->prepare($claim, new WorkerBudget(25, 20, 96));
        $runtime->queue->complete($claim, fn () => $runtime->demo->publish($claim, $result));
    };
    $startProbe = static function (string $mode, string $org) use ($config, $queueConfig): array {
        $probe = bin2hex(random_bytes(8));
        $process = proc_open([PHP_BINARY, __DIR__ . '/queue-worker.php', $mode, $org, $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $config->root, null, ['bypass_shell' => true, 'create_no_window' => true]);
        check(is_resource($process));
        $ready = $queueConfig->storage('locks') . '/' . $probe . '.ready';
        $deadline = hrtime(true) + 4_000_000_000;
        while (!is_file($ready) && hrtime(true) < $deadline && proc_get_status($process)['running']) {
            clearstatcache(true, $ready); usleep(10000);
        }
        if (!is_file($ready)) {
            proc_terminate($process);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            throw new RuntimeException('Probe did not reach its barrier.');
        }
        return [$process, $pipes, $probe];
    };
    $finishProbe = static function (array $probe) use ($queueConfig): string {
        [$process, $pipes, $key] = $probe;
        touch($queueConfig->storage('locks') . '/' . $key . '.go');
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Probe failed: ' . $error);
        return $output;
    };
    try {
        $suite->test('All fifteen migrations install cleanly in a separate marker-owned local database', static function () use ($queueConfig): void {
            $name = 'tai_test_' . bin2hex(random_bytes(8)); $marker = bin2hex(random_bytes(16));
            $server = new PDO('mysql:host=' . $queueConfig->get('database.host') . ';port=' . $queueConfig->get('database.port') . ';charset=utf8mb4', $queueConfig->get('database.username'), $queueConfig->get('database.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
            $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $server->exec('USE `' . $name . '`');
            $server->exec('CREATE TABLE _tai_test_marker (marker CHAR(32) NOT NULL) ENGINE=InnoDB');
            $server->prepare('INSERT INTO _tai_test_marker VALUES (?)')->execute([$marker]);
            try {
                $migrator = new \Tai\Shared\Infrastructure\Migrator($server, $queueConfig->root . '/database/migrations');
                check($migrator->migrate() === 15 && $migrator->migrate() === 0);
            } finally {
                if (preg_match('/^tai_test_[a-f0-9]{16}$/D', $name) && $server->query('SELECT DATABASE()')->fetchColumn() === $name
                    && hash_equals($marker, (string) $server->query('SELECT marker FROM _tai_test_marker')->fetchColumn())) {
                    $server->exec('DROP DATABASE `' . $name . '`');
                }
            }
        });
        $suite->test('Queue creates scoped jobs, schedules work, deduplicates keys and rejects key reuse', static function () use ($runtime, $newOrg, $repo): void {
            $org = $newOrg(); $payload = ['page' => 1, 'pages' => 1, 'run' => bin2hex(random_bytes(16))];
            $id = $runtime->queue->dispatch($org, 'demo.page', $payload, 'same', 10, 3, gmdate('Y-m-d H:i:s', time() + 300));
            check($id === $runtime->queue->dispatch($org, 'demo.page', $payload, 'same'));
            check($runtime->queue->claim($org) === null);
            raises(fn () => $runtime->queue->dispatch($org, 'demo.failure', ['mode' => 'transient'], 'same'), InvalidArgumentException::class);
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 1);
        });
        $suite->test('Atomic claim stores token digest and excludes a second connection', static function () use ($dispatch, $newOrg, $runtime, $state, $queueConfig): void {
            $org = $newOrg(); $id = $dispatch($org);
            $first = $runtime->queue->claim($org); check($first !== null);
            $otherPdo = (new \Tai\Shared\Infrastructure\Database($queueConfig))->connection();
            $other = new QueueComposition($otherPdo, $queueConfig, new Logger($queueConfig->storage('logs')));
            check($other->queue->claim($org) === null);
            check(hash_equals(hash('sha256', $first->token, true), $state($org, $id)['lease_token_hash']));
            check($state($org, $id)['lease_token_hash'] !== $first->token);
        });
        $suite->test('Disabled organizations and changed organization epochs fence existing work', static function () use ($dispatch, $newOrg, $runtime, $repo, $publish): void {
            $org = $newOrg(); $dispatch($org); $claim = $runtime->queue->claim($org);
            $repo->execute("UPDATE organizations SET status='blocked' WHERE id=?", [$org]);
            raises(fn () => $publish($claim), LostLease::class);
            raises(fn () => $dispatch($org), DomainException::class);
            $repo->execute("UPDATE organizations SET status='active',privacy_epoch=privacy_epoch+1 WHERE id=?", [$org]);
            raises(fn () => $publish($claim), LostLease::class);
            check($runtime->demo->visibleEffects($org) === []);
        });
        $suite->test('Job completion atomically publishes one effect, checkpoint and continuation', static function () use ($newOrg, $dispatch, $runtime, $publish, $state, $repo): void {
            $org = $newOrg(); $id = $dispatch($org, 2); $claim = $runtime->queue->claim($org); $publish($claim);
            check($state($org, $id)['status'] === 'succeeded');
            check(json_decode($state($org, $id)['checkpoint_json'], true)['page'] === 1);
            check(count($runtime->demo->visibleEffects($org)) === 1);
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 2);
            raises(fn () => $publish($claim), LostLease::class);
        });
        $suite->test('Publication rollback never leaves a partial effect, checkpoint or continuation', static function () use ($newOrg, $dispatch, $runtime, $state, $repo): void {
            $org = $newOrg(); $id = $dispatch($org, 2); $claim = $runtime->queue->claim($org);
            raises(fn () => $runtime->queue->complete($claim, static function () use ($runtime, $claim): void {
                $runtime->demo->publish($claim, ['value' => 1]);
                throw new RuntimeException('injected rollback');
            }), RuntimeException::class);
            check(count($runtime->demo->visibleEffects($org)) === 0 && $state($org, $id)['checkpoint_json'] === null);
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 1);
        });
        $suite->test('Transient retries use available_at, bounded attempts and a terminal failed state', static function () use ($newOrg, $dispatch, $runtime, $repo, $state): void {
            $org = $newOrg(); $id = $dispatch($org, 1, 2); $claim = $runtime->queue->claim($org);
            $runtime->queue->fail($claim, true, 'transient_failure');
            check($state($org, $id)['status'] === 'retry_wait' && $runtime->queue->claim($org) === null);
            $repo->execute('UPDATE jobs SET available_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?', [$org, $id]);
            $next = $runtime->queue->claim($org); check($next->attempt === 2);
            $runtime->queue->fail($next, true);
            check($state($org, $id)['status'] === 'failed' && $runtime->queue->claim($org) === null);
        });
        $suite->test('Permanent failures and corrupt payloads stop without leaking exception text', static function () use ($newOrg, $runtime, $repo, $state): void {
            $org = $newOrg();
            $id = $runtime->queue->dispatch($org, 'demo.failure', ['mode' => 'permanent'], 'permanent');
            $runtime->worker->run($org, new WorkerBudget(25, 5, 96));
            check($state($org, $id)['status'] === 'failed' && $state($org, $id)['attempts'] === 1);
            $bad = $runtime->queue->dispatch($org, 'demo.failure', ['mode' => 'transient'], 'corrupt');
            $repo->execute('UPDATE jobs SET payload_json=? WHERE organization_id=? AND id=?', ['{"token":"DO-NOT-LOG"}', $org, $bad]);
            check($runtime->queue->claim($org) === null && $state($org, $bad)['safe_error_code'] === 'invalid_payload');
        });
        $suite->test('Expired leases reject completion and recover with a new ownership generation', static function () use ($newOrg, $dispatch, $runtime, $repo, $publish, $state): void {
            $org = $newOrg(); $id = $dispatch($org); $old = $runtime->queue->claim($org);
            $repo->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$org, $id]);
            raises(fn () => $publish($old), LostLease::class);
            check($runtime->queue->recover($org) === 1);
            check($state($org, $id)['safe_error_code'] === 'lease_expired');
            $repo->execute('UPDATE jobs SET available_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?', [$org, $id]);
            $next = $runtime->queue->claim($org); check($next->generation === $old->generation + 1 && $next->token !== $old->token);
            raises(fn () => $publish($old), LostLease::class); $publish($next);
        });
        $suite->test('Forged tenant, token, generation and epoch cannot publish a claimed result', static function () use ($newOrg, $dispatch, $runtime, $publish): void {
            $org = $newOrg(); $other = $newOrg(); $dispatch($org); $claim = $runtime->queue->claim($org);
            foreach ([[$other, $claim->token, $claim->generation, $claim->privacyEpoch], [$org, random_bytes(32), $claim->generation, $claim->privacyEpoch], [$org, $claim->token, $claim->generation + 1, $claim->privacyEpoch], [$org, $claim->token, $claim->generation, $claim->privacyEpoch + 1]] as [$tenant, $token, $generation, $epoch]) {
                $forged = new Claim($claim->id, $tenant, $claim->type, $claim->payload, $token, $generation, $claim->attempt, $claim->maxAttempts, $epoch, $claim->correlationId);
                raises(fn () => $publish($forged), LostLease::class);
            }
            check($runtime->demo->visibleEffects($org) === []); $publish($claim);
        });
        $suite->test('Lease expiry during publication rolls back effects and continuation', static function () use ($newOrg, $dispatch, $runtime, $repo): void {
            $org = $newOrg(); $id = $dispatch($org, 2); $claim = $runtime->queue->claim($org);
            raises(fn () => $runtime->queue->complete($claim, static function () use ($runtime, $claim, $repo, $org, $id): void {
                $runtime->demo->publish($claim, ['value' => 1]);
                $repo->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$org, $id]);
            }), LostLease::class);
            check($runtime->demo->visibleEffects($org) === []);
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 1);
        });
        $suite->test('Lease recovery stops poison jobs at the maximum attempt count', static function () use ($newOrg, $dispatch, $runtime, $repo, $state): void {
            $org = $newOrg(); $id = $dispatch($org, 1, 1); $runtime->queue->claim($org);
            $repo->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$org, $id]);
            $runtime->queue->recover($org);
            check($state($org, $id)['status'] === 'failed');
        });
        $suite->test('Two competing CLI processes publish every logical job exactly once locally', static function () use ($newOrg, $dispatch, $startProbe, $finishProbe, $repo): void {
            $org = $newOrg(); for ($i = 0; $i < 20; $i++) $dispatch($org);
            $first = $startProbe('loop', $org); $second = $startProbe('loop', $org);
            // Release both before waiting for either process.
            $config = Config::load(dirname(__DIR__));
            touch($config->storage('locks') . '/' . $first[2] . '.go');
            touch($config->storage('locks') . '/' . $second[2] . '.go');
            $ids = array_merge(json_decode($finishProbe($first), true), json_decode($finishProbe($second), true));
            check(count($ids) === 20 && count(array_unique($ids)) === 20);
            check((int) $repo->execute('SELECT COUNT(*) FROM demo_effects WHERE organization_id=?', [$org])->fetchColumn() === 20);
        });
        foreach (['after_claim', 'after_prepare', 'after_writes', 'after_continuation'] as $mode) {
            $suite->test('Killed worker recovers safely: ' . $mode, static function () use ($mode, $newOrg, $dispatch, $startProbe, $repo, $runtime, $publish): void {
                $org = $newOrg(); $id = $dispatch($org, 2); [$process, $pipes] = $startProbe($mode, $org);
                proc_terminate($process); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                // The server rolls back any lost-connection publication transaction.
                $repo->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$org, $id]);
                check((int) $repo->execute('SELECT COUNT(*) FROM demo_effects WHERE organization_id=?', [$org])->fetchColumn() === 0);
                check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 1);
                check($runtime->queue->recover($org) === 1);
                $repo->execute('UPDATE jobs SET available_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?', [$org, $id]);
                $publish($runtime->queue->claim($org));
                check((int) $repo->execute('SELECT COUNT(*) FROM demo_effects WHERE organization_id=?', [$org])->fetchColumn() === 1);
                check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 2);
            });
        }
        $suite->test('Deletion during in-flight preparation blocks reads and rejects the late result', static function () use ($newOrg, $dispatch, $startProbe, $finishProbe, $runtime, $publish, $repo): void {
            $org = $newOrg(); $dispatch($org); $publish($runtime->queue->claim($org));
            $key = $runtime->files->create($org, 'synthetic artifact');
            $dispatch($org); $probe = $startProbe('deletion_race', $org);
            $runtime->privacy->deleteSyntheticScope($org, bin2hex(random_bytes(16)));
            check($runtime->demo->visibleEffects($org) === []);
            raises(fn () => $runtime->files->read($org, $key), DomainException::class);
            raises(fn () => $dispatch($org), DomainException::class);
            check($finishProbe($probe) === 'lost_lease');
            $runtime->worker->run($org, new WorkerBudget(25, 10, 96));
            check((int) $repo->execute("SELECT COUNT(*) FROM jobs WHERE organization_id=? AND job_type<>'retention.sweep'", [$org])->fetchColumn() === 0);
            check($repo->execute('SELECT status FROM deletion_requests WHERE organization_id=?', [$org])->fetchColumn() === 'completed');
            check((int) $repo->execute('SELECT COUNT(*) FROM private_files WHERE organization_id=?', [$org])->fetchColumn() === 0);
        });
        $suite->test('Recovery after deletion cancels expired work and never revives it', static function () use ($newOrg, $dispatch, $runtime, $repo, $state): void {
            $org = $newOrg(); $id = $dispatch($org); $runtime->queue->claim($org);
            $runtime->privacy->deleteSyntheticScope($org, bin2hex(random_bytes(16)));
            $repo->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$org, $id]);
            $runtime->queue->recover($org); check($state($org, $id)['status'] === 'cancelled');
        });
        $suite->test('Deletion intents replay idempotently and signed-ledger tampering is rejected', static function () use ($newOrg, $runtime, $repo, $queueConfig): void {
            $org = $newOrg(); $key = bin2hex(random_bytes(16));
            // Crash-equivalent intent with no primary DB state yet.
            $runtime->ledger->append($org, $key);
            $runtime->ledger->replay($runtime->privacy->applyIntent(...));
            $runtime->ledger->replay($runtime->privacy->applyIntent(...));
            check((int) $repo->execute('SELECT COUNT(*) FROM deletion_requests WHERE organization_id=?', [$org])->fetchColumn() === 1);
            $path = $queueConfig->get('privacy.ledger_path') . '/deletions.jsonl';
            $valid = file_get_contents($path);
            file_put_contents($path, str_replace('synthetic_scope', 'tampered_scope_', $valid));
            try { raises(fn () => $runtime->ledger->replay($runtime->privacy->applyIntent(...)), RuntimeException::class); }
            finally { file_put_contents($path, $valid); }
        });
        $suite->test('Missing independent ledger fails closed before changing privacy state', static function () use ($newOrg, $dispatch, $runtime, $repo, $queueConfig, $settings, $directory, $queueFile): void {
            $org = $newOrg(); $dispatch($org);
            $broken = $settings; $broken['privacy']['ledger_path'] = '';
            $path = $directory . '/missing-ledger-config.php';
            file_put_contents($path, '<?php return ' . var_export($broken, true) . ';'); putenv('TAI_CONFIG_FILE=' . $path);
            try {
                $ledger = new \Tai\Modules\Privacy\Infrastructure\DeletionLedger(Config::load($queueConfig->root));
                raises(fn () => $ledger->append($org, bin2hex(random_bytes(16))), RuntimeException::class);
                check($repo->execute('SELECT blocked_at FROM privacy_scopes WHERE organization_id=?', [$org])->fetchColumn() === null);
            } finally { putenv('TAI_CONFIG_FILE=' . $queueFile); unlink($path); }
        });
        $suite->test('A nonexistent deletion target cannot append a poison ledger record', static function () use ($runtime, $queueConfig): void {
            $path = $queueConfig->get('privacy.ledger_path') . '/deletions.jsonl';
            $before = hash_file('sha256', $path);
            raises(fn () => $runtime->privacy->deleteSyntheticScope('999999999999999999', bin2hex(random_bytes(16))), DomainException::class);
            check(hash_equals($before, hash_file('sha256', $path)));
        });
        $suite->test('A private-file failure prevents deletion completion and retries safely', static function () use ($newOrg, $runtime, $repo, $queueConfig): void {
            $org = $newOrg(); $key = $runtime->files->create($org, 'synthetic file');
            $path = $queueConfig->storage('exports') . '/' . $org . '/' . $key;
            unlink($path); mkdir($path);
            $runtime->privacy->deleteSyntheticScope($org, bin2hex(random_bytes(16)));
            $runtime->worker->run($org, new WorkerBudget(25, 5, 96));
            check($repo->execute('SELECT status FROM deletion_requests WHERE organization_id=?', [$org])->fetchColumn() === 'blocked');
            check((int) $repo->execute("SELECT COUNT(*) FROM jobs WHERE organization_id=? AND status='retry_wait'", [$org])->fetchColumn() > 0);
            rmdir($path);
            $repo->execute("UPDATE jobs SET available_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND status='retry_wait'", [$org]);
            $runtime->worker->run($org, new WorkerBudget(25, 5, 96));
            check($repo->execute('SELECT status FROM deletion_requests WHERE organization_id=?', [$org])->fetchColumn() === 'completed');
        });
        $suite->test('Retention and deletion are scoped and keep a second tenant intact', static function () use ($newOrg, $dispatch, $runtime, $repo, $publish): void {
            $first = $newOrg(); $second = $newOrg();
            $firstId = $dispatch($first); $secondId = $dispatch($second);
            $publish($runtime->queue->claim($first)); $publish($runtime->queue->claim($second));
            $repo->execute('UPDATE jobs SET expires_at=TIMESTAMPADD(DAY,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$first, $firstId]);
            $expired = $runtime->files->create($first, 'expired synthetic export');
            $retained = $runtime->files->create($second, 'retained synthetic export');
            $repo->execute('UPDATE private_files SET expires_at=TIMESTAMPADD(DAY,-1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$first]);
            raises(fn () => $runtime->files->read($first, $expired), DomainException::class);
            raises(fn () => $runtime->files->read($first, $retained), DomainException::class);
            raises(fn () => $runtime->files->read($first, '../escape'), InvalidArgumentException::class);
            $runtime->worker->run($first, new WorkerBudget(25, 10, 96));
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=? AND id=?', [$first, $firstId])->fetchColumn() === 0);
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=? AND id=?', [$second, $secondId])->fetchColumn() === 1);
            check($runtime->files->read($second, $retained) === 'retained synthetic export');
            check(count($runtime->demo->visibleEffects($second)) === 1);
            raises(fn () => $repo->execute('INSERT INTO demo_effects (organization_id,job_id,effect_value,expires_at) VALUES (?,?,1,UTC_TIMESTAMP(6))', [$first, $secondId]), PDOException::class);
        });
        $suite->test('Synthetic scope purge advances across bounded chunks without marking early completion', static function () use ($newOrg, $dispatch, $runtime, $repo): void {
            $org = $newOrg(); for ($i = 0; $i < 101; $i++) $dispatch($org);
            $runtime->privacy->deleteSyntheticScope($org, bin2hex(random_bytes(16)));
            $runtime->worker->run($org, new WorkerBudget(25, 1, 96));
            check((int) $repo->execute("SELECT COUNT(*) FROM jobs WHERE organization_id=? AND job_type<>'retention.sweep'", [$org])->fetchColumn() === 1);
            check($repo->execute('SELECT status FROM deletion_requests WHERE organization_id=?', [$org])->fetchColumn() === 'blocked');
            $runtime->worker->run($org, new WorkerBudget(25, 2, 96));
            check((int) $repo->execute("SELECT COUNT(*) FROM jobs WHERE organization_id=? AND job_type<>'retention.sweep'", [$org])->fetchColumn() === 0);
            check($repo->execute('SELECT status FROM deletion_requests WHERE organization_id=?', [$org])->fetchColumn() === 'completed');
        });
        $suite->test('Unfinished private artifacts remain unreadable and inventoried for cleanup', static function () use ($newOrg, $runtime, $repo): void {
            $org = $newOrg(); $key = $runtime->files->create($org, 'synthetic file');
            $repo->execute('UPDATE private_files SET is_ready=0 WHERE organization_id=? AND storage_key=?', [$org, $key]);
            raises(fn () => $runtime->files->read($org, $key), DomainException::class);
            raises(fn () => $runtime->queue->transaction(fn () => $runtime->files->create($org, 'nested write')), LogicException::class);
            check((int) $repo->execute('SELECT COUNT(*) FROM private_files WHERE organization_id=?', [$org])->fetchColumn() === 1);
        });
        $suite->test('Scheduled slots deduplicate and downtime coalesces into one job', static function () use ($newOrg, $runtime, $repo): void {
            $org = $newOrg(); $scheduler = new Scheduler($runtime->queue, new \Tai\Modules\Queue\Infrastructure\ScheduleRepository($repo));
            check($scheduler->dispatchDue($org) === 1 && $scheduler->dispatchDue($org) === 0);
            $repo->execute('UPDATE scheduled_tasks SET next_due_at=TIMESTAMPADD(DAY,-10,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            check($scheduler->dispatchDue($org) === 1 && $scheduler->dispatchDue($org) === 0);
            check((int) $repo->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=?', [$org])->fetchColumn() === 2);
        });
        $suite->test('CLI enqueue and bounded worker commands execute without a shell or HTTP trigger', static function () use ($newOrg, $config, $repo): void {
            $org = $newOrg();
            foreach ([['queue:demo', '--organization=' . $org, '--pages=2'], ['queue:run', '--organization=' . $org, '--max-jobs=3', '--max-seconds=25', '--memory-mb=96']] as $args) {
                $process = proc_open(array_merge([PHP_BINARY, $config->root . '/bin/console.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                check(proc_close($process) === 0, 'CLI failed: ' . $error);
            }
            $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
            check($result['processed'] === 3 && $result['duration_ms'] < 25000 && $result['peak_memory_bytes'] < 96 * 1048576);
            check((int) $repo->execute('SELECT COUNT(*) FROM demo_effects WHERE organization_id=?', [$org])->fetchColumn() === 2);
        });
        $suite->test('Worker max-jobs, overlap lock, graceful stop and one-second reserve are finite', static function () use ($newOrg, $dispatch, $runtime, $repo, $queueConfig): void {
            $org = $newOrg(); $dispatch($org, 20);
            $result = $runtime->worker->run($org, new WorkerBudget(25, 2, 96));
            check($result['processed'] === 2 && $result['duration_ms'] < 25000 && $result['peak_memory_bytes'] < 96 * 1048576);
            $lock = fopen($queueConfig->storage('locks') . '/worker-' . $org . '.lock', 'c');
            check(flock($lock, LOCK_EX | LOCK_NB));
            try { check($runtime->worker->run($org, new WorkerBudget(25, 2, 96))['status'] === 'overlap'); }
            finally { fclose($lock); }
            check($runtime->worker->run($org, new WorkerBudget(1, 2, 96))['processed'] === 0);
            $runtime->worker->stop();
            check($runtime->worker->run($org, new WorkerBudget(25, 2, 96))['processed'] === 0);
        });
        $suite->test('Operational statistics use real scoped rows and safe retained error codes', static function () use ($newOrg, $dispatch, $runtime, $publish, $repo, $pdo): void {
            $org = $newOrg(); $other = $newOrg();
            $dispatch($org); $publish($runtime->queue->claim($org));
            $id = $dispatch($org); $runtime->queue->fail($runtime->queue->claim($org), true, 'transient_failure');
            $dispatch($other);
            $stats = (new OperationsRepository($pdo))->queueStatistics($org);
            check($stats['counts']['succeeded'] === 1 && $stats['counts']['retry_wait'] === 1 && $stats['counts']['ready'] === 0);
            check($stats['last_success_at'] === null && $stats['peak_bytes'] === null);
            check($stats['recent_errors'][0]['safe_error_code'] === 'transient_failure');
        });
    } finally {
        putenv('TAI_CONFIG_FILE=' . $configFile);
        unlink($queueFile);
    }
};

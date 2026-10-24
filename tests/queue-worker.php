<?php

declare(strict_types=1);

// Synthetic fault/concurrency probe, never an application entry point.
require dirname(__DIR__) . '/app/Bootstrap/autoload.php';
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $config = \Tai\Shared\Infrastructure\Config::load(dirname(__DIR__));
    $pdo = (new \Tai\Shared\Infrastructure\Database($config))->connection();
    if ($config->get('app.env') !== 'test' || !preg_match('/^tai_test_[a-f0-9]{16}$/D', $config->get('database.name'))
        || strlen((string) $pdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn()) !== 32) {
        throw new RuntimeException('Refusing unmarked/non-test DB.');
    }
    $runtime = new \Tai\Bootstrap\QueueComposition($pdo, $config, new \Tai\Shared\Infrastructure\Logger($config->storage('logs')));
    [$unused, $mode, $org, $probe] = $argv;
    if (!in_array($mode, ['loop', 'after_claim', 'after_prepare', 'after_writes', 'after_continuation', 'deletion_race'], true) || !preg_match('/^[a-f0-9]{16}$/D', $probe)) {
        throw new RuntimeException('Invalid probe.');
    }
    $ready = $config->storage('locks') . '/' . $probe . '.ready';
    $go = $config->storage('locks') . '/' . $probe . '.go';
    $wait = static function () use ($ready, $go): void {
        touch($ready);
        $deadline = hrtime(true) + 8_000_000_000;
        while (!is_file($go) && hrtime(true) < $deadline) {
            clearstatcache(true, $go);
            usleep(10000);
        }
        if (!is_file($go)) {
            throw new RuntimeException('Barrier timed out.');
        }
    };
    if ($mode === 'loop') {
        $wait();
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $claim = $runtime->queue->claim($org);
            if ($claim === null) {
                break;
            }
            $result = $runtime->demo->prepare($claim, new \Tai\Modules\Queue\Application\WorkerBudget(25, 30, 96));
            $runtime->queue->complete($claim, fn () => $runtime->demo->publish($claim, $result));
            $ids[] = $claim->id;
        }
        echo json_encode($ids, JSON_THROW_ON_ERROR);
    } else {
        $claim = $runtime->queue->claim($org) ?? throw new RuntimeException('No work to claim.');
        if ($mode === 'after_claim') {
            $wait();
        }
        $result = $runtime->demo->prepare($claim, new \Tai\Modules\Queue\Application\WorkerBudget(25, 1, 96));
        if ($mode === 'after_prepare') {
            // Stand-in for an ambiguous external effect; deliberately outside DB tx.
            file_put_contents($config->storage('locks') . '/' . $probe . '.external', "synthetic-call\n", FILE_APPEND);
            $wait();
        }
        if ($mode === 'deletion_race') {
            $wait();
        }
        try {
            $runtime->queue->complete($claim, static function () use ($runtime, $claim, $result, $mode, $wait): void {
                if ($mode === 'after_writes') {
                    $runtime->repository->execute('INSERT INTO demo_effects (organization_id,job_id,effect_value,expires_at) SELECT organization_id,id,?,expires_at FROM jobs WHERE organization_id=? AND id=?', [$result['value'], $claim->organizationId, $claim->id]);
                    $runtime->repository->execute('UPDATE jobs SET checkpoint_json=? WHERE organization_id=? AND id=?', ['{"page":1}', $claim->organizationId, $claim->id]);
                    $wait();
                } else {
                    $runtime->demo->publish($claim, $result);
                    if ($mode === 'after_continuation') {
                        $wait();
                    }
                }
            });
            echo 'completed';
        } catch (\Tai\Modules\Queue\Domain\LostLease) {
            echo 'lost_lease';
        }
    }
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Synthetic probe failed safely.\n");
    exit(1);
}

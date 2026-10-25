<?php

declare(strict_types=1);

// Fault-injection probe, never a production entry point.
require dirname(__DIR__) . '/app/Bootstrap/autoload.php';
date_default_timezone_set('UTC');
try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
    [$script, $mode, $org, $probe, $marker] = $argv;
    if (!in_array($mode, ['claim', 'prepared', 'publication', 'complete'], true) || !preg_match('/^[a-f0-9]{16}$/D', $probe)) throw new RuntimeException('Invalid probe.');
    \Tai\Modules\Queue\Domain\JobPayload::identifier($org);
    $config = \Tai\Shared\Infrastructure\Config::load(dirname(__DIR__));
    if ($config->get('app.env') !== 'test' || !in_array($config->get('database.host'), ['localhost', '127.0.0.1', '::1'], true)
        || !preg_match('/^tai_test_[a-f0-9]{16}$/D', $config->get('database.name')) || !preg_match('/^[a-f0-9]{32}$/D', $marker)) throw new RuntimeException('Unmarked target refused.');
    $pdo = (new \Tai\Shared\Infrastructure\Database($config))->connection();
    if (!hash_equals($marker, (string) $pdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn())) throw new RuntimeException('Marker mismatch.');
    $fake = new \Tai\Modules\Threads\Infrastructure\FixtureTransport(static function (\Tai\Modules\Threads\Domain\ProviderRequest $request): array {
        if ($request->operation === 'posts') return ['status' => 200, 'body' => '{"data":[{"id":"910001","owner":{"id":"900001"},"timestamp":"2026-10-25T00:00:00Z"}],"paging":{"next":"ignored","cursors":{"after":"resume-cursor"}}}'];
        return (new \Tai\Modules\Threads\Infrastructure\FixtureTransport())->send($request);
    });
    $runtime = new \Tai\Bootstrap\QueueComposition($pdo, $config, new \Tai\Shared\Infrastructure\Logger($config->storage('logs')), $fake);
    $barrier = static function () use ($config, $probe): void {
        file_put_contents($config->storage('locks') . '/' . $probe . '.threads-ready', 'ready');
        $deadline = hrtime(true) + 8_000_000_000;
        do { usleep(10000); clearstatcache(); } while (!is_file($config->storage('locks') . '/' . $probe . '.threads-go') && hrtime(true) < $deadline);
        if (hrtime(true) >= $deadline) throw new RuntimeException('Barrier timeout.');
    };
    $claim = $runtime->queue->claim($org);
    if ($claim === null) { echo '{"claimed":false}'; exit(0); }
    if ($mode === 'claim') $barrier();
    $result = $runtime->threads->handler->prepare($claim, new \Tai\Modules\Queue\Application\WorkerBudget(25, 1, 96));
    if ($mode === 'prepared') $barrier();
    $runtime->queue->complete($claim, static function () use ($runtime, $claim, $result, $mode, $barrier): void {
        $runtime->threads->handler->publish($claim, $result);
        if ($mode === 'publication') $barrier();
    });
    echo '{"claimed":true}';
} catch (Throwable $error) {
    fwrite(STDERR, 'Threads probe failed: ' . $error::class); exit(1);
}

<?php

declare(strict_types=1);

use Tai\Modules\Privacy\Domain\RetentionPolicy;
use Tai\Modules\Queue\Application\RetryPolicy;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\JobPayload;
use TaiTests\Suite;
use function TaiTests\check;
use function TaiTests\raises;

return static function (Suite $suite): void {
    $suite->test('Queue payloads allow only bounded synthetic references and maintenance', static function (): void {
        $run = str_repeat('a', 32);
        check(JobPayload::validate('demo.page', ['page' => 1, 'pages' => 20, 'run' => $run]) !== '');
        foreach ([['provider.call', []], ['retention.sweep', ['url' => 'https://example.test']], ['demo.page', ['page' => 0, 'pages' => 1, 'run' => $run]], ['demo.page', ['page' => 1, 'pages' => 21, 'run' => $run]], ['demo.failure', ['mode' => 'transient', 'token' => 'secret']]] as [$type, $payload]) {
            raises(fn () => JobPayload::validate($type, $payload), InvalidArgumentException::class);
        }
    });
    $suite->test('Retry backoff grows exponentially with bounded jitter and a finite cap', static function (): void {
        $retry = new RetryPolicy(10, 100);
        foreach ([1 => [10, 12], 2 => [20, 25], 3 => [40, 50], 10 => [100, 100]] as $attempt => [$min, $max]) {
            $delay = $retry->delay($attempt);
            check($delay >= $min && $delay <= $max);
        }
        raises(fn () => $retry->delay(0), InvalidArgumentException::class);
        raises(fn () => new RetryPolicy(10, 5), InvalidArgumentException::class);
    });
    $suite->test('Worker rejects limits above hosting ceilings and reserves exit time', static function (): void {
        foreach ([[26, 1, 16], [25, 101, 96], [25, 1, 97], [0, 1, 16]] as $limits) {
            raises(fn () => new WorkerBudget(...$limits), InvalidArgumentException::class);
        }
        check(!(new WorkerBudget(1, 1, 96))->canStart());
        check(!(new WorkerBudget(25, 1, 96))->canStart(1));
    });
    $suite->test('Worker budget detects actual peak-memory pressure and elapsed wall time in a child process', static function (): void {
        $code = 'require ' . var_export(dirname(__DIR__) . '/app/Bootstrap/autoload.php', true) . ';'
            . '$memoryBudget = new \\Tai\\Modules\\Queue\\Application\\WorkerBudget(25, 1, 16);'
            . '$fixture = str_repeat("x", 18 * 1048576); unset($fixture);'
            . 'if ($memoryBudget->canStart()) exit(1);'
            . 'try { $memoryBudget->assertWithin(); exit(2); } catch (\\Tai\\Modules\\Queue\\Domain\\BudgetExceeded) {}'
            . '$timeBudget = new \\Tai\\Modules\\Queue\\Application\\WorkerBudget(1, 1, 96); usleep(1050000);'
            . 'try { $timeBudget->assertWithin(); exit(3); } catch (\\Tai\\Modules\\Queue\\Domain\\BudgetExceeded) {}'
            . 'echo "budgets-detected";';
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
        check(is_resource($process));
        $output = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $output === 'budgets-detected');
    });
    $suite->test('Retention defaults are provisional finite maximums and accept shorter policies', static function (): void {
        check((new RetentionPolicy())->values() === [90, 7, 30, 90, 30]);
        check((new RetentionPolicy(1, 1, 1, 1, 1))->values() === [1, 1, 1, 1, 1]);
        raises(fn () => new RetentionPolicy(91), InvalidArgumentException::class);
        raises(fn () => new RetentionPolicy(90, 0), InvalidArgumentException::class);
    });
};

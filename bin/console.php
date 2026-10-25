<?php

declare(strict_types=1);

use Tai\Modules\Identity\Application\AdministratorProvisioner;
use Tai\Modules\Identity\Application\PasswordHasher;
use Tai\Modules\Identity\Infrastructure\AuditLog;
use Tai\Modules\Identity\Infrastructure\IdentityRepository;
use Tai\Bootstrap\QueueComposition;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Operations\Infrastructure\Maintenance;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Database;
use Tai\Shared\Infrastructure\Logger;
use Tai\Shared\Infrastructure\Migrator;
use Tai\Shared\Infrastructure\Terminal;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
umask(0077);
require dirname(__DIR__) . '/app/Bootstrap/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$logger = null;
try {
    $command = $argv[1] ?? 'help';
    if (!in_array($command, ['help', 'key:generate', 'config:check', 'db:check', 'migrate', 'migrate:status', 'admin:create', 'maintenance:prune', 'queue:run', 'queue:demo', 'privacy:delete-synthetic', 'privacy:replay-ledger', 'privacy:replay-threads-ledger'], true)) {
        throw new UserFacingException('Unknown command. Run php bin/console.php help.');
    }
    $options = [];
    $allowed = match ($command) {
        'admin:create' => ['email', 'name', 'password-stdin'],
        'queue:run' => ['organization', 'max-seconds', 'max-jobs', 'memory-mb'],
        'queue:demo' => ['organization', 'pages', 'key'],
        'privacy:delete-synthetic' => ['organization', 'key'],
        'privacy:replay-ledger', 'privacy:replay-threads-ledger' => ['offset'],
        'maintenance:prune' => ['organization'],
        default => [],
    };
    foreach (array_slice($argv, 2) as $argument) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/sD', $argument, $matches)
            || !in_array($matches[1], $allowed, true) || isset($options[$matches[1]])
            || ($matches[1] === 'password-stdin' ? isset($matches[2]) : !isset($matches[2]))) {
            throw new UserFacingException('Invalid command option. Password arguments are never accepted.');
        }
        $options[$matches[1]] = $matches[2] ?? true;
    }
    if ($command === 'help') {
        echo "Threads Audience Intelligence · Phase 3B fixture\n\n"
            . "  key:generate        Print a new application key (store privately).\n"
            . "  config:check        Validate configuration and private storage.\n"
            . "  db:check            Check the configured database connection.\n"
            . "  migrate:status      Show pending/applied or damaged migration history.\n"
            . "  migrate             Apply pending SQL migrations under a DB lock.\n"
            . "  admin:create --email=... --name=... [--password-stdin]\n"
            . "                      Initialize one administrator and one organization.\n"
            . "  maintenance:prune --organization=ID   Prune scoped audit and installation auth/log data.\n"
            . "  queue:run --organization=ID [--max-seconds=25 --max-jobs=20 --memory-mb=96]\n"
            . "  queue:demo --organization=ID [--pages=3 --key=<32 hex>] (local/test only)\n"
            . "  privacy:delete-synthetic --organization=ID --key=<32 hex> (local/test only)\n"
            . "  privacy:replay-ledger [--offset=0] (isolated local/test restore only)\n"
            . "  privacy:replay-threads-ledger [--offset=0] (isolated local/test restore only)\n";
        exit(0);
    }
    if ($command === 'key:generate') {
        echo bin2hex(random_bytes(32)) . "\n";
        exit(0);
    }
    $config = Config::load(dirname(__DIR__));
    $budget = $command === 'queue:run' ? new WorkerBudget(
        (int) ($options['max-seconds'] ?? $config->get('queue.max_seconds')),
        (int) ($options['max-jobs'] ?? $config->get('queue.max_jobs')),
        (int) ($options['memory-mb'] ?? $config->get('queue.memory_mb')),
    ) : null;
    foreach (['organization', 'max-seconds', 'max-jobs', 'memory-mb', 'pages', 'offset'] as $numeric) {
        if (isset($options[$numeric]) && (!preg_match('/^[0-9]{1,18}$/D', $options[$numeric]) || ($numeric !== 'offset' && (int) $options[$numeric] < 1))) {
            throw new UserFacingException('Invalid numeric command option.');
        }
    }
    if (in_array($command, ['queue:demo', 'privacy:delete-synthetic', 'privacy:replay-ledger', 'privacy:replay-threads-ledger'], true) && $config->get('app.env') === 'production') {
        throw new UserFacingException('Synthetic and restore commands require an isolated local/test environment.');
    }
    if (in_array($command, ['queue:run', 'queue:demo', 'privacy:delete-synthetic', 'maintenance:prune'], true)) {
        JobPayload::identifier($options['organization'] ?? '');
    }
    $logger = new Logger($config->storage('logs'));
    if ($command === 'config:check') {
        $config->storage('sessions');
        echo 'Configuration valid · environment: ' . $config->get('app.env') . " · private storage ready.\n";
        exit(0);
    }
    $pdo = (new Database($config, in_array($command, ['migrate', 'migrate:status', 'admin:create'], true)))->connection();
    $migrator = new Migrator($pdo, $config->root . '/database/migrations');
    switch ($command) {
        case 'db:check':
            echo 'Database connected · ' . $pdo->query('SELECT VERSION()')->fetchColumn() . " · UTC connection.\n";
            break;
        case 'migrate:status':
            $damaged = false;
            foreach ($migrator->status() as $row) {
                echo $row['version'] . '  ' . $row['status'] . "\n";
                $damaged = $damaged || !in_array($row['status'], ['pending', 'applied'], true);
            }
            exit($damaged ? 1 : 0);
        case 'migrate':
            echo 'Applied ' . $migrator->migrate() . " migration(s).\n";
            $logger->event('cli.migrate', ['operation' => 'migrate', 'status' => 'succeeded']);
            break;
        case 'admin:create':
            if (!is_string($options['email'] ?? null) || !is_string($options['name'] ?? null)) {
                throw new UserFacingException('Supply --email and --name. The password must come from a hidden prompt or stdin.');
            }
            foreach ($migrator->status() as $row) {
                if ($row['status'] !== 'applied') {
                    throw new UserFacingException('Apply and verify migrations before creating the administrator.');
                }
            }
            $password = Terminal::password(isset($options['password-stdin']));
            try {
                $provisioner = new AdministratorProvisioner(new IdentityRepository($pdo), new PasswordHasher($config->get('security.bcrypt_cost')), new AuditLog($pdo, $config->get('security.audit_days')));
                $provisioner->create($options['email'], $options['name'], $password);
            } finally {
                unset($password);
            }
            echo "Administrator created. Sign in at the configured application address.\n";
            break;
        case 'maintenance:prune':
            foreach ((new Maintenance($pdo, $config))->prune($options['organization']) as $kind => $count) {
                echo $kind . ': removed ' . $count . " expired item(s).\n";
            }
            break;
        case 'queue:run':
        case 'queue:demo':
        case 'privacy:delete-synthetic':
        case 'privacy:replay-ledger':
        case 'privacy:replay-threads-ledger':
            foreach ($migrator->status() as $row) {
                if ($row['status'] !== 'applied') {
                    throw new UserFacingException('Apply and verify migrations before running queue/privacy commands.');
                }
            }
            $runtime = new QueueComposition($pdo, $config, $logger);
            if ($command === 'queue:run') {
                if (function_exists('pcntl_async_signals')) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGTERM, static fn () => $runtime->worker->stop());
                    pcntl_signal(SIGINT, static fn () => $runtime->worker->stop());
                }
                echo json_encode($runtime->worker->run($options['organization'], $budget), JSON_THROW_ON_ERROR) . "\n";
            } elseif ($command === 'queue:demo') {
                $key = $options['key'] ?? bin2hex(random_bytes(16));
                $pages = (int) ($options['pages'] ?? 3);
                $id = $runtime->queue->dispatch($options['organization'], 'demo.page', ['page' => 1, 'pages' => $pages, 'run' => $key], 'demo:' . $key . ':1');
                echo 'Synthetic job queued: ' . $id . "\n";
            } elseif ($command === 'privacy:delete-synthetic') {
                $runtime->privacy->deleteSyntheticScope($options['organization'], $options['key'] ?? '');
                echo "Synthetic scope blocked; purge queued.\n";
            } elseif ($command === 'privacy:replay-threads-ledger') {
                echo json_encode($runtime->threads->privacy->ledger->replay($runtime->threads->privacy->apply(...), (int) ($options['offset'] ?? 0)), JSON_THROW_ON_ERROR) . "\n";
            } else {
                echo json_encode($runtime->ledger->replay($runtime->privacy->applyIntent(...), (int) ($options['offset'] ?? 0)), JSON_THROW_ON_ERROR) . "\n";
            }
            break;
    }
    exit(0);
} catch (Throwable $exception) {
    try {
        $logger?->event('cli.failed', ['error_type' => $exception::class, 'operation' => $command ?? 'startup']);
    } catch (Throwable) {
        // No credential-bearing exception text is sent to fallback logging.
    }
    fwrite(STDERR, ($exception instanceof UserFacingException ? $exception->getMessage() : 'Command failed safely. Check private configuration, database access and application logs.') . "\n");
    exit(1);
}

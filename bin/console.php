<?php

declare(strict_types=1);

use Tai\Modules\Identity\Application\AdministratorProvisioner;
use Tai\Modules\Identity\Application\PasswordHasher;
use Tai\Modules\Identity\Infrastructure\AuditLog;
use Tai\Modules\Identity\Infrastructure\IdentityRepository;
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
    if (!in_array($command, ['help', 'key:generate', 'config:check', 'db:check', 'migrate', 'migrate:status', 'admin:create', 'maintenance:prune'], true)) {
        throw new UserFacingException('Unknown command. Run php bin/console.php help.');
    }
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if ($command !== 'admin:create' || !preg_match('/^--(email|name|password-stdin)(?:=(.*))?$/sD', $argument, $matches)
            || isset($options[$matches[1]]) || ($matches[1] === 'password-stdin' && isset($matches[2]))) {
            throw new UserFacingException('Invalid command option. Password arguments are never accepted.');
        }
        $options[$matches[1]] = $matches[2] ?? true;
    }
    if ($command === 'help') {
        echo "Threads Audience Intelligence · Phase 1\n\n"
            . "  key:generate        Print a new application key (store privately).\n"
            . "  config:check        Validate configuration and private storage.\n"
            . "  db:check            Check the configured database connection.\n"
            . "  migrate:status      Show pending/applied or damaged migration history.\n"
            . "  migrate             Apply pending SQL migrations under a DB lock.\n"
            . "  admin:create --email=... --name=... [--password-stdin]\n"
            . "                      Initialize one administrator and one organization.\n"
            . "  maintenance:prune   Remove bounded expired authentication/log data.\n";
        exit(0);
    }
    if ($command === 'key:generate') {
        echo bin2hex(random_bytes(32)) . "\n";
        exit(0);
    }
    $config = Config::load(dirname(__DIR__));
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
            foreach ((new Maintenance($pdo, $config))->prune() as $kind => $count) {
                echo $kind . ': removed ' . $count . " expired item(s).\n";
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

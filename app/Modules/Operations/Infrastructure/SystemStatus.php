<?php

declare(strict_types=1);

namespace Tai\Modules\Operations\Infrastructure;

use PDO;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Migrator;

final class SystemStatus
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config, private readonly Migrator $migrator, private readonly OperationsRepository $operations)
    {
    }

    public function inspect(string $organizationId): array
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        $migrationRows = $this->migrator->status();
        $ready = count($migrationRows) > 0;
        foreach ($migrationRows as $row) {
            $ready = $ready && $row['status'] === 'applied';
        }
        $queue = $ready ? $this->operations->queueStatistics($organizationId) : null;
        $extensions = [];
        foreach (['PDO', 'pdo_mysql', 'mbstring', 'session', 'openssl'] as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        $checks = [
            ['name' => 'Database', 'ok' => true, 'detail' => 'Connected · ' . $version],
            ['name' => 'Schema', 'ok' => $ready, 'detail' => $ready ? 'All Phase 3B migrations applied' : 'Migration review required'],
            ['name' => 'Private storage', 'ok' => is_writable($this->config->storage('logs')) && is_writable($this->config->storage('sessions')), 'detail' => 'Logs and sessions outside public/'],
            ['name' => 'Transport', 'ok' => $this->config->secureCookies(), 'detail' => $this->config->secureCookies() ? 'HTTPS and Secure cookies configured' : 'Local loopback HTTP · development only'],
        ];
        $checks[] = ['name' => 'Worker heartbeat', 'ok' => $queue !== null && $queue['heartbeat'] === 'recent',
            'detail' => $queue === null ? 'Apply migrations first' : match ($queue['heartbeat']) {
                'recent' => 'Successful run within three minutes', 'stale' => 'No successful run within three minutes', default => 'No successful run recorded',
            }];
        return [
            'phase' => '3B', 'environment' => $this->config->get('app.env'), 'release' => $this->config->get('app.release'),
            'php_version' => PHP_VERSION, 'database_version' => $version,
            'target_database' => 'MariaDB 10.11', 'checked_at' => gmdate('c'),
            'checks' => $checks, 'extensions' => $extensions,
            'migrations' => array_map(static fn (array $row): array => ['version' => $row['version'], 'status' => $row['status']], $migrationRows),
            'integrations' => ['threads' => 'Fixture only; live unavailable', 'openai' => 'Not implemented', 'queue' => 'Synthetic, fixture and retention jobs'],
            'queue' => $queue,
        ];
    }
}

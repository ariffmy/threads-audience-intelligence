<?php

declare(strict_types=1);

namespace Tai\Shared\Infrastructure;

final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    public function event(string $event, array $context = []): void
    {
        $safe = [];
        foreach (['error_type', 'status', 'route_name', 'actor_id', 'organization_id', 'correlation_id', 'operation'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $safe[$key] = substr((string) $context[$key], 0, 128);
            }
        }
        $record = json_encode([
            'at' => gmdate('c'),
            'event' => preg_replace('/[^a-z0-9_.-]/i', '', $event),
            'context' => $safe,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $path = $this->directory . '/application-' . gmdate('Y-m-d') . '.log';
        if (file_put_contents($path, $record, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Private log write failed.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }
    }
}

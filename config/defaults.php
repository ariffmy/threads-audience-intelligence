<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'url' => 'https://localhost',
        'key' => '',
        'name' => 'Threads Audience Intelligence',
        'release' => 'phase-1',
        'storage' => dirname(__DIR__) . '/storage',
    ],
    'database' => [
        'host' => '127.0.0.1', 'port' => 3306, 'name' => '',
        'username' => '', 'password' => '',
        'migration_username' => '', 'migration_password' => '',
    ],
    'session' => ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
    'security' => [
        'email_attempts' => 5, 'ip_attempts' => 30, 'window_seconds' => 900,
        'bcrypt_cost' => 12, 'audit_days' => 90, 'log_days' => 30,
    ],
];

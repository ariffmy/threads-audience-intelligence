<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'url' => 'https://localhost',
        'key' => '',
        'name' => 'Threads Audience Intelligence',
        'release' => 'phase-3b',
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
    'queue' => [
        'max_seconds' => 25, 'max_jobs' => 20, 'memory_mb' => 96,
        'lease_seconds' => 90, 'retry_base_seconds' => 60, 'retry_cap_seconds' => 14400,
    ],
    'privacy' => [
        // Provisional product maximums, not verified Meta policy requirements.
        'source_days' => 90, 'export_days' => 7, 'log_days' => 30,
        'audit_days' => 90, 'backup_days' => 30,
        'ledger_path' => '', 'ledger_key' => '',
    ],
    'threads' => [
        'fixture_enabled' => false,
        'keyring_path' => '',
    ],
];

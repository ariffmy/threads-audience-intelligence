<?php

declare(strict_types=1);

// Copy to ignored config/local.php for local use, or a restricted path outside
// public/ and set TAI_CONFIG_FILE to that absolute path. No dotenv parser needed.
return [
    'app' => [
        'env' => 'local',
        'url' => 'http://localhost:8080',
        'key' => '', // Generate with: php bin/console.php key:generate
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'threads_audience_local',
        'username' => '',
        'password' => '',
        'migration_username' => '', // Optional separate DDL identity for CLI only.
        'migration_password' => '',
    ],
    'queue' => ['max_seconds' => 25, 'max_jobs' => 20, 'memory_mb' => 96],
    'threads' => [
        'fixture_enabled' => false, // Local/test only. Production always refuses; no live transport exists.
        'keyring_path' => '', // Absolute private JSON file: current key ID and keys map of 64 lowercase hex secrets.
    ],
    'privacy' => [
        'source_days' => 90, 'export_days' => 7, 'log_days' => 30, 'audit_days' => 90, 'backup_days' => 30,
        // Deletion/replay require an absolute independent private directory
        // outside public/ and app.storage; protect and back up separately.
        'ledger_path' => '',
        'ledger_key' => '', // Separate 64 lowercase hex secret; provision privately.
    ],
];

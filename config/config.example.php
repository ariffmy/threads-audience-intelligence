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
];

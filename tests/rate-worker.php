<?php

declare(strict_types=1);

use Tai\Modules\Identity\Infrastructure\LoginRateLimiter;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Database;

require dirname(__DIR__) . '/app/Bootstrap/autoload.php';
$config = Config::load(dirname(__DIR__));
if ($config->get('app.env') !== 'test' || !preg_match('/^tai_test_[a-f0-9]{16}$/D', $config->get('database.name'))) exit(1);
$pdo = (new Database($config))->connection();
if (strlen((string) $pdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn()) !== 32) exit(1);
$limiter = new LoginRateLimiter($pdo, $config);
$allowed = 0;
for ($i = 0; $i < 4; $i++) {
    if ($limiter->consume('concurrent@example.test', '127.0.0.77', 1800000001) === 0) $allowed++;
}
echo $allowed;

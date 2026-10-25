<?php

declare(strict_types=1);

use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Database;
use TaiTests\Suite;
use function TaiTests\check;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/Bootstrap/autoload.php';
require __DIR__ . '/Support/Suite.php';
require __DIR__ . '/Support/MemorySession.php';
date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('memory_limit', '128M');
umask(0077);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
$directory = $root . '/storage/test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$suite = new Suite();
$server = null;
$testPdo = null;
$databaseName = 'tai_test_' . bin2hex(random_bytes(8));
$marker = bin2hex(random_bytes(16));
$started = hrtime(true);
try {
    (require __DIR__ . '/unit.php')($suite, $directory);
    (require __DIR__ . '/queue-unit.php')($suite);
    (require __DIR__ . '/threads-unit.php')($suite);
    // Never load the application's local/production DB configuration for tests.
    foreach (['TAI_ENV', 'TAI_APP_URL', 'TAI_APP_KEY', 'TAI_STORAGE_PATH', 'TAI_DB_HOST', 'TAI_DB_PORT', 'TAI_DB_NAME', 'TAI_DB_USER', 'TAI_DB_PASSWORD', 'TAI_MIGRATION_DB_USER', 'TAI_MIGRATION_DB_PASSWORD'] as $key) {
        putenv($key);
    }
    $configFile = $directory . '/config.php';
    $settings = [
        'app' => ['env' => 'test', 'url' => 'https://localhost', 'key' => bin2hex(random_bytes(32)), 'storage' => $directory . '/runtime'],
        'database' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => $databaseName, 'username' => '', 'password' => ''],
    ];
    file_put_contents($configFile, '<?php return ' . var_export($settings, true) . ';');
    putenv('TAI_CONFIG_FILE=' . $configFile);
    $suite->test('Native sessions: fixation, rotation, expiry, cookie flags and destroy', static function (): void {
        $process = proc_open([PHP_BINARY, __DIR__ . '/native-session.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process));
        $result = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Session probe did not exit cleanly: ' . $error);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        foreach (['strict', 'rotation', 'idle', 'absolute', 'destroyed', 'httponly', 'secure'] as $key) check($data[$key] === true, 'Session invariant failed: ' . $key);
        check($data['samesite'] === 'Lax' && $data['path'] === '/' && $data['domain'] === '');
    });
    if (!in_array('--database', $argv, true)) {
        $suite->skip('Database/authentication/HTTP integration suite: run --database with explicit local test credentials.');
    } else {
        $host = getenv('TAI_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TAI_TEST_DB_PORT') ?: 3306);
        $user = getenv('TAI_TEST_DB_USER');
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !$user || $port < 1 || $port > 65535) {
            throw new RuntimeException('Tests require explicit TAI_TEST_DB_USER and a loopback-only test server.');
        }
        $password = getenv('TAI_TEST_DB_PASSWORD') ?: '';
        $server = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $settings['database'] = ['host' => $host, 'port' => $port, 'name' => $databaseName, 'username' => $user, 'password' => $password];
        $settings['app']['url'] = 'http://127.0.0.1:8080';
        file_put_contents($configFile, '<?php return ' . var_export($settings, true) . ';');
        $config = Config::load($root);
        $testPdo = (new Database($config))->connection();
        $testPdo->exec('CREATE TABLE _tai_test_marker (marker CHAR(32) NOT NULL) ENGINE=InnoDB');
        $insert = $testPdo->prepare('INSERT INTO _tai_test_marker VALUES (?)');
        $insert->execute([$marker]);
        echo 'Database test engine: ' . $testPdo->query('SELECT VERSION()')->fetchColumn() . " (production target remains MariaDB 10.11).\n";
        (require __DIR__ . '/integration.php')($suite, $config, $testPdo, $directory);
        (require __DIR__ . '/http.php')($suite, $config, $testPdo, $directory, $configFile, $settings);
        (require __DIR__ . '/queue-integration.php')($suite, $config, $testPdo, $directory, $configFile, $settings);
        (require __DIR__ . '/threads-integration.php')($suite, $config, $testPdo, $directory, $configFile, $settings);
    }
} catch (Throwable $exception) {
    $suite->test('Test environment/bootstrap', static function () use ($exception): void { throw $exception; });
} finally {
    if ($server !== null && $testPdo !== null) {
        // Drop only this process's newly created, marker-verified random test DB.
        if (preg_match('/^tai_test_[a-f0-9]{16}$/D', $databaseName)
            && $testPdo->query('SELECT DATABASE()')->fetchColumn() === $databaseName
            && hash_equals($marker, (string) $testPdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn())) {
            $testPdo = null;
            $server->exec('DROP DATABASE `' . $databaseName . '`');
            echo "Temporary marker-verified test database removed.\n";
        }
    }
    if (isset($configFile) && is_file($configFile)) {
        unlink($configFile);
    }
}
echo sprintf("\n%d passed, %d failed, %d skipped · %.2fs · peak %.1f MiB\n", $suite->passed, $suite->failed, $suite->skipped, (hrtime(true) - $started) / 1e9, memory_get_peak_usage(true) / 1048576);
exit($suite->failed > 0 ? 1 : 0);

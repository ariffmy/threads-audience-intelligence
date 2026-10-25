<?php

declare(strict_types=1);

use Tai\Modules\Identity\Application\PasswordHasher;
use Tai\Shared\Infrastructure\Config;
use TaiTests\Suite;
use function TaiTests\check;

return static function (Suite $suite, Config $config, PDO $pdo, string $directory, string $configFile, array $settings): void {
    if (!extension_loaded('curl') || !function_exists('proc_open')) {
        $suite->skip('HTTP/CLI subprocess tests require curl and proc_open.');
        return;
    }
    $socket = stream_socket_server('tcp://localhost:0', $errorCode, $errorMessage);
    check($socket !== false, 'Cannot allocate local HTTP test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $address = 'localhost:' . substr($address, strrpos($address, ':') + 1);
    $settings['app']['url'] = 'http://' . $address;
    file_put_contents($configFile, '<?php return ' . var_export($settings, true) . ';');
    $password = 'HTTP-test-' . bin2hex(random_bytes(16));
    $query = $pdo->prepare('UPDATE users SET password_hash=?,is_installation_admin=1,status=\'active\' WHERE id=1');
    $query->execute([(new PasswordHasher())->hash($password)]);
    $pdo->exec('DELETE FROM login_throttles');
    $server = proc_open([
        PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=30', '-d', 'opcache.enable_cli=0',
        '-S', $address, '-t', $config->root . '/public', $config->root . '/bin/dev-router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/http.stdout.log', 'a'], 2 => ['file', $directory . '/http.stderr.log', 'a']], $pipes, $config->root, null, ['bypass_shell' => true, 'create_no_window' => true]);
    check(is_resource($server), 'Cannot start local HTTP test server.');
    fclose($pipes[0]);
    $client = static function (string $path, ?array $data = null, string $jar = 'admin', array $extraHeaders = []) use ($directory, $settings): array {
        $headers = [];
        if ($data !== null && !array_filter($extraHeaders, static fn (string $header): bool => str_starts_with(strtolower($header), 'origin:'))) {
            $extraHeaders[] = 'Origin: ' . $settings['app']['url'];
        }
        $handle = curl_init($settings['app']['url'] . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
            CURLOPT_COOKIEFILE => $directory . '/' . $jar . '.cookies', CURLOPT_COOKIEJAR => $directory . '/' . $jar . '.cookies',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))][] = trim($value); }
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => $extraHeaders,
        ]);
        if ($data !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
        unset($handle);
        check($error === 0 && is_string($body), 'Local HTTP request failed: curl=' . $error . ', HTTP=' . $status . ', bytes=' . (is_string($body) ? strlen($body) : 0));
        return ['body' => $body, 'status' => $status, 'headers' => $headers];
    };
    $csrf = static function (array $response): string {
        check(preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $response['body'], $match) === 1, 'Expected a CSRF form token.');
        return $match[1];
    };
    try {
        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $number, $message, .1);
            if ($probe !== false) { fclose($probe); $ready = true; break; }
            usleep(100000);
        }
        check($ready, 'Local test server did not become ready.');
        $suite->test('HTTP login form uses pinned Bootstrap and secure local cookie attributes', static function () use ($client, $csrf): void {
            $page = $client('/login');
            check($page['status'] === 200); $csrf($page);
            check(str_contains($page['body'], 'bootstrap-5.3.8.min.css'));
            $cookies = implode(';', $page['headers']['set-cookie'] ?? []);
            check(str_contains(strtolower($cookies), 'httponly') && str_contains($cookies, 'SameSite=Lax'));
            check(!str_contains($cookies, 'Domain='));
            check(str_contains($page['headers']['cache-control'][0], 'no-store'));
            check($page['headers']['referrer-policy'][0] === 'same-origin');
        });
        $suite->test('HTTP protects all application pages and returns JSON 401', static function () use ($client): void {
            foreach (['/dashboard', '/profile', '/settings', '/status', '/threads', '/threads/oauth/callback'] as $path) {
                $response = $client($path, null, 'anonymous');
                check($response['status'] === 303 && $response['headers']['location'][0] === '/login');
            }
            $response = $client('/status.json', null, 'anonymous', ['Accept: application/json']);
            check($response['status'] === 401 && str_contains($response['headers']['content-type'][0], 'application/json'));
            check($client('/register')['status'] === 404 && $client('/organizations')['status'] === 404);
        });
        $suite->test('HTTP login CSRF rejects missing and wrong tokens', static function () use ($client): void {
            check($client('/login', ['email' => 'owner@example.test', 'password' => 'test-only'])['status'] === 419);
            check($client('/login', ['_csrf' => str_repeat('0', 64)])['status'] === 419);
        });
        $suite->test('HTTP valid login regenerates the session and reaches dashboard', static function () use ($client, $csrf, $password, $directory): void {
            $page = $client('/login'); $token = $csrf($page);
            $before = file_get_contents($directory . '/admin.cookies');
            $response = $client('/login', ['_csrf' => $token, 'email' => 'owner@example.test', 'password' => $password]);
            check($response['status'] === 303 && $response['headers']['location'][0] === '/dashboard');
            check($before !== file_get_contents($directory . '/admin.cookies'));
            $dashboard = $client('/dashboard');
            check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Not implemented'));
            check($csrf($dashboard) !== $token);
        });
        $suite->test('HTTP profile updates are CSRF-protected and stored text renders escaped', static function () use ($client, $csrf): void {
            $token = $csrf($client('/profile'));
            check($client('/profile', ['display_name' => 'forged'])['status'] === 419);
            check($client('/profile', ['_csrf' => $token, 'display_name' => '<script>alert(1)</script>'])['status'] === 303);
            $page = $client('/profile');
            check(str_contains($page['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
            check(!str_contains($page['body'], '<script>alert(1)</script>'));
            check($client('/profile', ['_csrf' => $token, 'display_name' => 'Test Administrator'])['status'] === 303);
        });
        $suite->test('HTTP settings ignore submitted organization IDs and validate timezone', static function () use ($client, $csrf, $pdo): void {
            $token = $csrf($client('/settings'));
            check($client('/settings', ['_csrf' => $token, 'timezone' => 'Europe/London', 'organization_id' => 2])['status'] === 303);
            check($pdo->query('SELECT display_timezone FROM organizations WHERE id=1')->fetchColumn() === 'Europe/London');
            check($pdo->query('SELECT display_timezone FROM organizations WHERE id=2')->fetchColumn() === 'Asia/Kuala_Lumpur');
            check($client('/settings', ['_csrf' => $token, 'timezone' => 'invalid'])['status'] === 422);
        });
        $suite->test('HTTP status and Fetch JSON expose safe live health data', static function () use ($client, $pdo, $config): void {
            $queue = new \Tai\Bootstrap\QueueComposition($pdo, $config, new \Tai\Shared\Infrastructure\Logger($config->storage('logs')));
            $queue->queue->dispatch('2', 'demo.failure', ['mode' => 'permanent'], 'other-tenant-http');
            check($client('/status')['status'] === 200);
            $response = $client('/status.json?organization_id=2', null, 'admin', ['Accept: application/json']);
            check($response['status'] === 200);
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            check($data['phase'] === '3B' && count($data['migrations']) === 25);
            check($data['queue']['last_success_at'] === null && $data['queue']['counts']['ready'] === 0);
            check($data['integrations']['threads'] === 'Fixture only; live unavailable');
            foreach (['password', 'app.key', 'token_ciphertext', 'C:\\', 'D:\\'] as $secret) check(!str_contains($response['body'], $secret));
        });
        $suite->test('HTTP administrator and owner authorization is checked on each request', static function () use ($client, $pdo): void {
            $pdo->exec('UPDATE users SET is_installation_admin=0 WHERE id=1');
            try { check($client('/dashboard')['status'] === 403); }
            finally { $pdo->exec('UPDATE users SET is_installation_admin=1 WHERE id=1'); }
        });
        $suite->test('HTTP Threads page labels fixtures and mutation/callback routes fail closed', static function () use ($client, $csrf): void {
            $page = $client('/threads'); check($page['status'] === 200 && str_contains($page['body'], 'Synthetic data only'));
            $token = $csrf($page);
            foreach (['connect', 'disconnect', 'import', 'incremental', 'refresh', 'rotate', 'delete-source'] as $action) {
                check($client('/threads/' . $action, ['account' => '1'])['status'] === 419);
                check($client('/threads/' . $action)['status'] === 405);
            }
            check($client('/threads/connect', ['_csrf' => $token])['status'] === 422);
            $callback = $client('/threads/oauth/callback?code=fixture-bad');
            check($callback['status'] === 303 && $callback['headers']['location'][0] === '/threads?authorization=failed');
            check($callback['headers']['referrer-policy'][0] === 'no-referrer' && !str_contains($callback['body'], '<script'));
        });
        $suite->test('HTTP rejects forged Origin/Host, oversized bodies and unsupported input', static function () use ($client, $csrf): void {
            $token = $csrf($client('/profile'));
            check($client('/profile', ['_csrf' => $token, 'display_name' => 'forged'], 'admin', ['Origin: https://attacker.example'])['status'] === 403);
            check($client('/profile', ['_csrf' => $token, 'display_name' => 'forged'], 'admin', ['Origin: null'])['status'] === 403);
            check($client('/dashboard', null, 'admin', ['Host: attacker.example'])['status'] === 400);
            check($client('/login', ['oversized' => str_repeat('x', 17000)])['status'] === 413);
            check($client('/login', ['a' => 'b'], 'admin', ['Content-Type: application/json'])['status'] === 415);
        });
        $suite->test('HTTP private paths are unavailable and assets are served', static function () use ($client): void {
            foreach (['/config/local.php', '/storage/', '/AGENTS.md', '/database/migrations/0001_create_organizations.sql', '/.git/config', '/bin/console.php'] as $path) check($client($path)['status'] === 404);
            check($client('/assets/vendor/bootstrap-5.3.8.min.css')['status'] === 200);
            check($client('/assets/css/app.css')['status'] === 200);
        });
        $suite->test('HTTP logout requires POST+CSRF and an old session cannot be replayed', static function () use ($client, $csrf, $directory): void {
            check($client('/logout')['status'] === 405);
            check($client('/logout', [])['status'] === 419);
            $token = $csrf($client('/profile'));
            copy($directory . '/admin.cookies', $directory . '/replay.cookies');
            check($client('/logout', ['_csrf' => $token])['status'] === 303);
            check($client('/dashboard', null, 'replay')['status'] === 303);
            check($client('/dashboard')['status'] === 303);
        });
        $suite->test('HTTP failed logins are generic and eventually return 429 with Retry-After', static function () use ($client, $csrf, $pdo): void {
            $pdo->exec('DELETE FROM login_throttles');
            $token = $csrf($client('/login', null, 'limited'));
            for ($i = 0; $i < 5; $i++) {
                $response = $client('/login', ['_csrf' => $token, 'email' => 'owner@example.test', 'password' => 'wrong-password'], 'limited');
                check($response['status'] === 422 && str_contains($response['body'], 'Unable to sign in'));
            }
            $response = $client('/login', ['_csrf' => $token, 'email' => 'owner@example.test', 'password' => 'wrong-password'], 'limited');
            check($response['status'] === 429 && (int) $response['headers']['retry-after'][0] > 0);
        });
        $suite->test('HTTP database outages return a safe response without connection secrets', static function () use ($settings, $configFile, $directory, $config): void {
            // Use a new process/config file: production configuration is immutable
            // per release, and this test must not rely on opcode-cache hot reload.
            $socket = stream_socket_server('tcp://localhost:0');
            $address = stream_socket_get_name($socket, false); fclose($socket);
            $address = 'localhost:' . substr($address, strrpos($address, ':') + 1);
            $broken = $settings; $broken['database']['port'] = 1; $broken['app']['url'] = 'http://' . $address;
            $failureFile = $directory . '/unavailable-config.php';
            file_put_contents($failureFile, '<?php return ' . var_export($broken, true) . ';');
            putenv('TAI_CONFIG_FILE=' . $failureFile);
            $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-S', $address, '-t', $config->root . '/public', $config->root . '/bin/dev-router.php'], [1 => ['file', $directory . '/unavailable.log', 'a'], 2 => ['file', $directory . '/unavailable.log', 'a']], $unused, $config->root, null, ['bypass_shell' => true, 'create_no_window' => true]);
            putenv('TAI_CONFIG_FILE=' . $configFile);
            check(is_resource($process));
            try {
                $ready = false;
                for ($i = 0; $i < 30; $i++) {
                    $socket = @stream_socket_client('tcp://' . $address, $number, $message, .1);
                    if ($socket !== false) { fclose($socket); $ready = true; break; }
                    usleep(100000);
                }
                check($ready);
                $handle = curl_init('http://' . $address . '/login');
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
                $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                check($status === 503 && is_string($body), 'Expected unavailable status, received ' . $status);
                foreach (['SQLSTATE', 'PDOException', $settings['app']['key'], $settings['database']['name']] as $text) check(!str_contains($body, $text), 'Connection details appeared in unavailable response.');
                unset($handle);
            } finally { proc_terminate($process); proc_close($process); unlink($failureFile); }
        });
        $suite->test('CLI connection/migration commands succeed and unknown/password options fail', static function () use ($config): void {
            foreach ([['db:check'], ['migrate:status'], ['migrate']] as $arguments) {
                $process = proc_open(array_merge([PHP_BINARY, $config->root . '/bin/console.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                check(proc_close($process) === 0);
            }
            foreach ([['not-a-command'], ['admin:create', '--password=forbidden']] as $arguments) {
                $process = proc_open(array_merge([PHP_BINARY, $config->root . '/bin/console.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                check(proc_close($process) === 1 && !str_contains($error, 'forbidden'));
            }
        });
    } finally {
        proc_terminate($server);
        proc_close($server);
        file_put_contents($configFile, '<?php return ' . var_export($settings, true) . ';');
    }
};

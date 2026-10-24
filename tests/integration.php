<?php

declare(strict_types=1);

use Tai\Bootstrap\Composition;
use Tai\Modules\Access\Application\AdministratorGuard;
use Tai\Modules\Identity\Application\AdministratorProvisioner;
use Tai\Modules\Identity\Application\AuthenticationService;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Modules\Identity\Application\PasswordHasher;
use Tai\Modules\Identity\Infrastructure\AuditLog;
use Tai\Modules\Identity\Infrastructure\IdentityRepository;
use Tai\Modules\Identity\Infrastructure\LoginRateLimiter;
use Tai\Modules\Operations\Infrastructure\Maintenance;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Http\Request;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Logger;
use Tai\Shared\Infrastructure\Migrator;
use TaiTests\MemorySession;
use TaiTests\Suite;
use function TaiTests\check;
use function TaiTests\raises;

return static function (Suite $suite, Config $config, PDO $pdo, string $directory): void {
    check($config->get('app.env') === 'test' && str_starts_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 'tai_test_'));
    check(strlen((string) $pdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn()) === 32);
    $phaseOneDirectory = $directory . '/phase-one-migrations';
    mkdir($phaseOneDirectory);
    foreach (glob($config->root . '/database/migrations/000[1-6]_*.sql') as $file) {
        copy($file, $phaseOneDirectory . '/' . basename($file));
    }
    $migrator = new Migrator($pdo, $phaseOneDirectory);
    $suite->test('Database connectivity, native prepares and UTC connection', static function () use ($pdo): void {
        check(str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB'));
        check($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0);
        check($pdo->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00');
        $query = $pdo->prepare('SELECT ? AS value');
        $query->execute(["' OR 1=1 --"]);
        check($query->fetchColumn() === "' OR 1=1 --");
    });
    $suite->test('Clean migration, status and repeat execution', static function () use ($migrator): void {
        check(count(array_filter($migrator->status(), fn ($row) => $row['status'] === 'pending')) === 6);
        check($migrator->migrate() === 6);
        check($migrator->migrate() === 0);
        check(count(array_filter($migrator->status(), fn ($row) => $row['status'] === 'applied')) === 6);
    });
    $suite->test('Only necessary Phase 1 tables are created', static function () use ($pdo): void {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $expected = ['_tai_test_marker', 'audit_events', 'auth_sessions', 'login_throttles', 'memberships', 'organizations', 'schema_migrations', 'users'];
        sort($expected);
        check($tables === $expected);
    });
    $migrator = new Migrator($pdo, $config->root . '/database/migrations');
    $suite->test('Phase 1 to Phase 2 upgrade preserves identity tables and reruns cleanly', static function () use ($migrator): void {
        check($migrator->migrate() === 9);
        check($migrator->migrate() === 0);
        check(count(array_filter($migrator->status(), fn ($row) => $row['status'] === 'applied')) === 15);
    });
    $suite->test('Migration checksums reject modified historical SQL', static function () use ($pdo, $directory, $config): void {
        $changed = $directory . '/changed-migrations';
        mkdir($changed);
        foreach (glob($config->root . '/database/migrations/*.sql') as $file) copy($file, $changed . '/' . basename($file));
        file_put_contents($changed . '/0001_create_organizations.sql', "\n-- tampered\n", FILE_APPEND);
        $migrator = new Migrator($pdo, $changed);
        check($migrator->status()[0]['status'] === 'checksum_mismatch');
        raises(fn () => $migrator->migrate(), UserFacingException::class);
    });
    $suite->test('Interrupted migration history stops automatic execution', static function () use ($pdo, $migrator): void {
        $pdo->exec("UPDATE schema_migrations SET status='started' WHERE version='0001_create_organizations'");
        try {
            raises(fn () => $migrator->migrate(), UserFacingException::class);
        } finally {
            $pdo->exec("UPDATE schema_migrations SET status='applied' WHERE version='0001_create_organizations'");
        }
    });
    $suite->test('Migration lock excludes a competing connection', static function () use ($config, $pdo, $migrator): void {
        $other = (new \Tai\Shared\Infrastructure\Database($config))->connection();
        $lock = 'tai:migrate:' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
        $query = $other->prepare('SELECT GET_LOCK(?, 0)'); $query->execute([$lock]);
        try { raises(fn () => $migrator->migrate(), UserFacingException::class); }
        finally { $query = $other->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]); }
    });
    $passwords = new PasswordHasher();
    $password = 'Test-only-' . bin2hex(random_bytes(16));
    $audit = new AuditLog($pdo, 90);
    $repository = new IdentityRepository($pdo);
    $provisioner = new AdministratorProvisioner($repository, $passwords, $audit);
    $suite->test('CLI provisioning creates one administrator, organization and owner', static function () use ($provisioner, $password, $pdo, $passwords): void {
        $provisioner->create('Owner@example.test', 'Test Administrator', $password);
        check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
        check((int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn() === 1);
        check($pdo->query('SELECT role FROM memberships')->fetchColumn() === 'owner');
        $user = $pdo->query('SELECT email,password_hash FROM users')->fetch();
        check($user['email'] === 'owner@example.test' && $passwords->verify($password, $user['password_hash']));
        check($pdo->query('SELECT event_type FROM audit_events LIMIT 1')->fetchColumn() === 'admin.created');
    });
    $suite->test('A second administrator/bootstrap is rejected', static function () use ($provisioner, $password, $pdo): void {
        raises(fn () => $provisioner->create('second@example.test', 'Second', $password), UserFacingException::class);
        check((int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn() === 1);
        check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
    });
    $makeAuth = static function (?MemorySession $session = null) use ($pdo, $config, $passwords, $audit): array {
        $session ??= new MemorySession();
        return [new AuthenticationService(new IdentityRepository($pdo), $config, $session, $passwords, $audit, new LoginRateLimiter($pdo, $config)), $session];
    };
    $signIn = static function () use ($makeAuth, $pdo, $password): array {
        $pdo->exec('DELETE FROM login_throttles');
        [$auth, $session] = $makeAuth();
        check($auth->login('owner@example.test', $password, '127.0.0.1') === 0);
        return [$auth, $session];
    };
    $suite->test('Known and unknown accounts receive the same failed-login message', static function () use ($makeAuth, $pdo): void {
        $pdo->exec('DELETE FROM login_throttles');
        [$auth] = $makeAuth();
        $messages = [];
        foreach (['owner@example.test', 'unknown@example.test'] as $email) {
            try { $auth->login($email, 'wrong-test-password', '127.0.0.1'); }
            catch (HttpException $exception) { $messages[] = $exception->getMessage(); }
        }
        check(count($messages) === 2 && $messages[0] === $messages[1]);
    });
    $suite->test('Successful login rotates session/CSRF and registers only a session digest', static function () use ($makeAuth, $pdo, $password): void {
        $pdo->exec('DELETE FROM login_throttles');
        [$auth, $session] = $makeAuth();
        $oldId = $session->id(); $csrf = new Csrf($session); $oldToken = $csrf->token();
        check($auth->login('OWNER@example.test', $password, '127.0.0.1') === 0);
        check($session->id() !== $oldId && !$csrf->valid($oldToken));
        $identity = $auth->identity();
        check($identity !== null && (int) $identity['organization_id'] === 1);
        $query = $pdo->prepare('SELECT session_id_hash FROM auth_sessions WHERE id=?'); $query->execute([$identity['auth_session_id']]);
        check(strlen($query->fetchColumn()) === 32);
    });
    $suite->test('Logout revokes the database session and destroys local state', static function () use ($signIn, $pdo): void {
        [$auth, $session] = $signIn(); $identity = $auth->identity();
        $auth->logout($identity);
        check($session->destroyed && $auth->identity() === null);
        $query = $pdo->prepare('SELECT revoked_at FROM auth_sessions WHERE id=?'); $query->execute([$identity['auth_session_id']]);
        check($query->fetchColumn() !== null);
    });
    foreach (['revoked_at=UTC_TIMESTAMP(6)', 'last_seen_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 31 MINUTE)', 'absolute_expires_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE)'] as $change) {
        $suite->test('Session registry rejects ' . explode('=', $change)[0], static function () use ($signIn, $pdo, $change): void {
            [$auth] = $signIn(); $identity = $auth->identity();
            $query = $pdo->prepare('UPDATE auth_sessions SET ' . $change . ' WHERE id=?'); $query->execute([$identity['auth_session_id']]);
            check($auth->identity() === null);
        });
    }
    $suite->test('Session version revocation is enforced on the next request', static function () use ($signIn, $pdo): void {
        [$auth] = $signIn();
        $pdo->exec('UPDATE users SET session_version=session_version+1 WHERE id=1');
        check($auth->identity() === null);
    });
    $suite->test('Protected HTML and JSON routes deny anonymous access', static function () use ($config): void {
        $app = Composition::web($config, new MemorySession(), new Logger($config->storage('logs')));
        foreach (['/dashboard', '/profile', '/settings', '/status'] as $path) {
            $response = $app->handle(new Request('GET', $path, [], '127.0.0.1', '127.0.0.1:8080', false));
            check($response->status === 303 && $response->headers['Location'] === '/login');
        }
        check($app->handle(new Request('GET', '/status.json', [], '127.0.0.1', '127.0.0.1:8080', false, null, true))->status === 401);
    });
    $suite->test('CSRF, cross-origin and Host checks reject forged requests', static function () use ($config, $signIn): void {
        [, $session] = $signIn();
        $app = Composition::web($config, $session, new Logger($config->storage('logs')));
        check($app->handle(new Request('POST', '/profile', ['display_name' => 'forged'], '127.0.0.1', '127.0.0.1:8080', false))->status === 419);
        check($app->handle(new Request('POST', '/profile', ['_csrf' => (new Csrf($session))->token()], '127.0.0.1', '127.0.0.1:8080', false, 'https://external.example'))->status === 403);
        check($app->handle(new Request('GET', '/dashboard', [], '127.0.0.1', 'external.example', false))->status === 400);
    });
    $suite->test('Inactive membership and non-owner role cannot authorize writes', static function () use ($signIn, $pdo): void {
        [$auth] = $signIn(); $identity = $auth->identity();
        $pdo->exec("UPDATE memberships SET role='viewer' WHERE user_id=1");
        try {
            raises(fn () => (new AdministratorGuard())->assertAllowed($auth->identity()), HttpException::class, 403);
            raises(fn () => $auth->updateProfile($identity, 'not authorized'), HttpException::class, 403);
        } finally { $pdo->exec("UPDATE memberships SET role='owner' WHERE user_id=1"); }
    });
    $suite->test('Tenant references and mutation checks reject another organization', static function () use ($pdo, $signIn, $repository): void {
        $pdo->exec("INSERT INTO organizations (name,slug) VALUES ('Other fixture','other-fixture')");
        [$auth] = $signIn(); $identity = $auth->identity();
        $other = array_replace($identity, ['organization_id' => '2']);
        raises(fn () => $auth->updateTimezone($other, 'UTC'), HttpException::class, 403);
        raises(fn () => $repository->createSession(['user_id' => '1', 'organization_id' => '2', 'session_version' => 1], random_bytes(32), gmdate('Y-m-d H:i:s', time() + 3600), 'unused'), PDOException::class);
        check($pdo->query('SELECT display_timezone FROM organizations WHERE id=2')->fetchColumn() === 'Asia/Kuala_Lumpur');
    });
    $suite->test('Profile and settings writes are scoped, validated and audited', static function () use ($signIn, $pdo): void {
        [$auth] = $signIn(); $identity = $auth->identity();
        $auth->updateProfile($identity, '<script>inert</script>');
        $auth->updateTimezone($identity, 'UTC');
        check($pdo->query('SELECT display_name FROM users WHERE id=1')->fetchColumn() === '<script>inert</script>');
        raises(fn () => $auth->updateTimezone($identity, 'not-a-timezone'), UserFacingException::class);
        check((int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE event_type='settings.updated'")->fetchColumn() > 0);
        $auth->updateProfile($identity, 'Test Administrator');
    });
    $suite->test('Email throttling is case-normalized and shared across IPs', static function () use ($pdo, $config): void {
        $pdo->exec('DELETE FROM login_throttles'); $limiter = new LoginRateLimiter($pdo, $config); $now = 1800000001;
        for ($i = 0; $i < 5; $i++) check($limiter->consume('same@example.test', '127.0.0.' . ($i + 1), $now) === 0);
        check($limiter->consume('same@example.test', '127.0.0.80', $now) > 0);
        check($limiter->consume('same@example.test', '127.0.0.80', $now + 900) === 0);
    });
    $suite->test('IP throttling limits attempts across different email addresses', static function () use ($pdo, $config): void {
        $pdo->exec('DELETE FROM login_throttles'); $limiter = new LoginRateLimiter($pdo, $config);
        for ($i = 0; $i < 30; $i++) check($limiter->consume('user' . $i . '@example.test', '127.0.0.90', 1800000001) === 0);
        check($limiter->consume('other@example.test', '127.0.0.90', 1800000001) > 0);
    });
    $suite->test('Concurrent rate-limit updates allow exactly the configured allowance', static function () use ($pdo): void {
        $pdo->exec('DELETE FROM login_throttles'); $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/rate-worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(is_resource($process)); $processes[] = [$process, $pipes];
        }
        $allowed = 0;
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            check(proc_close($process) === 0, 'Rate-limit worker failed: ' . $error);
            $allowed += (int) $output;
        }
        check($allowed === 5, 'Concurrent allowance was not exactly five.');
    });
    $suite->test('Changing a password revokes every session and rejects the previous password', static function () use ($signIn, $pdo, $password, $passwords): void {
        [$first] = $signIn(); [$second, $session] = $signIn();
        $identity = $second->identity();
        raises(fn () => $second->changePassword($identity, 'wrong-password', 'another-strong-test-password'), UserFacingException::class);
        $next = 'New-test-' . bin2hex(random_bytes(16));
        $second->changePassword($identity, $password, $next);
        check($session->destroyed && $first->identity() === null);
        $hash = $pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
        check(!$passwords->verify($password, $hash) && $passwords->verify($next, $hash));
        check((int) $pdo->query('SELECT COUNT(*) FROM auth_sessions WHERE user_id=1 AND revoked_at IS NULL')->fetchColumn() === 0);
    });
    $suite->test('Bounded maintenance removes expired auth/audit data', static function () use ($pdo, $config): void {
        $pdo->exec("UPDATE login_throttles SET expires_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY)");
        $pdo->exec("UPDATE audit_events SET expires_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY) WHERE event_type='profile.updated'");
        $counts = (new Maintenance($pdo, $config))->prune('1');
        check($counts['login_throttles'] > 0 && $counts['audit_events'] > 0);
        check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
    });
};

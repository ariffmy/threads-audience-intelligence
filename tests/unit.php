<?php

declare(strict_types=1);

use Tai\Bootstrap\Application;
use Tai\Modules\Access\Application\AdministratorGuard;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Modules\Identity\Application\PasswordHasher;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Http\Request;
use Tai\Shared\Http\Response;
use Tai\Shared\Http\Router;
use Tai\Shared\Http\View;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Logger;
use TaiTests\MemorySession;
use TaiTests\Suite;
use function TaiTests\check;
use function TaiTests\raises;

return static function (Suite $suite, string $testDirectory): void {
    $suite->test('CSRF token is random, session-bound and rotated', static function (): void {
        $session = new MemorySession();
        $csrf = new Csrf($session);
        check(!$csrf->valid(''));
        $first = $csrf->token();
        check(strlen($first) === 64 && $csrf->valid($first));
        check(!$csrf->valid([$first]) && !$csrf->valid(str_repeat('0', 64)));
        check(!(new Csrf(new MemorySession()))->valid($first));
        $csrf->rotate();
        check(!$csrf->valid($first));
    });
    $suite->test('Password hashing verifies and rejects incorrect passwords', static function (): void {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('synthetic-password-for-unit-check');
        check($hash !== 'synthetic-password-for-unit-check');
        check($hasher->verify('synthetic-password-for-unit-check', $hash));
        check(!$hasher->verify('wrong-password', $hash));
        check(!$hasher->needsRehash($hash));
    });
    $suite->test('Password policy prevents weak input and bcrypt truncation', static function (): void {
        $hasher = new PasswordHasher();
        raises(fn () => $hasher->hash('short'), UserFacingException::class);
        raises(fn () => $hasher->hash(str_repeat('x', 73)), UserFacingException::class);
        raises(fn () => $hasher->hash("invalid-password\0data"), UserFacingException::class);
    });
    $suite->test('Administrator authorization requires active scoped ownership', static function (): void {
        $identity = ['user_id' => '1', 'organization_id' => '1', 'is_installation_admin' => 1, 'role' => 'owner', 'user_status' => 'active', 'membership_status' => 'active', 'organization_status' => 'active'];
        $guard = new AdministratorGuard();
        $guard->assertAllowed($identity);
        foreach (['is_installation_admin' => 0, 'role' => 'viewer', 'user_status' => 'disabled', 'membership_status' => 'disabled', 'organization_status' => 'blocked'] as $key => $value) {
            raises(fn () => $guard->assertAllowed(array_replace($identity, [$key => $value])), HttpException::class, 403);
        }
        raises(fn () => $guard->assertAllowed([]), HttpException::class, 403);
    });
    $suite->test('Router distinguishes protection, missing routes and methods', static function (): void {
        $router = new Router();
        $router->add('GET', '/private', 'private');
        $router->add('GET', '/login', 'login', false);
        $request = static fn (string $method, string $path): Request => new Request($method, $path, [], '127.0.0.1', 'localhost', false);
        check($router->match($request('GET', '/private'))['protected']);
        check(!$router->match($request('GET', '/login'))['protected']);
        check($router->match($request('HEAD', '/private'))['protected']);
        raises(fn () => $router->match($request('POST', '/private')), HttpException::class, 405);
        raises(fn () => $router->match($request('GET', '/missing')), HttpException::class, 404);
        check($router->allowedMethods('/private') === 'GET, HEAD');
    });
    $suite->test('Request validation rejects array, oversized and null input', static function (): void {
        $request = new Request('POST', '/', ['array' => [], 'long' => str_repeat('x', 300), 'null' => "a\0b"], '127.0.0.1', 'localhost', false);
        foreach (['array', 'long', 'null'] as $field) {
            raises(fn () => $request->string($field), HttpException::class, 422);
        }
    });
    $suite->test('Output escaping and redirect restrictions reject executable input', static function (): void {
        check(!str_contains(View::escape('<script>"x"</script>'), '<script>'));
        raises(fn () => Response::redirect('//external.example'), LogicException::class);
        raises(fn () => Response::redirect("/ok\r\nInjected: yes"), LogicException::class);
        check(Response::redirect('/login')->status === 303);
    });
    $suite->test('Security headers restrict resources, frames and caching', static function (): void {
        $headers = Application::securityHeaders(true);
        check(str_contains($headers['Content-Security-Policy'], "frame-ancestors 'none'"));
        check(!str_contains($headers['Content-Security-Policy'], 'unsafe-inline'));
        check($headers['Cache-Control'] === 'no-store, private');
        check($headers['Referrer-Policy'] === 'same-origin');
        check(isset($headers['Strict-Transport-Security']));
        check(!isset(Application::securityHeaders(false)['Strict-Transport-Security']));
    });
    $suite->test('Logger allowlist excludes credentials and untrusted payloads', static function () use ($testDirectory): void {
        $logger = new Logger($testDirectory);
        $logger->event('unit.redaction', ['status' => 403, 'password' => 'never-write-this', 'token' => 'never-write-this', 'body' => 'never-write-this']);
        $text = file_get_contents($testDirectory . '/application-' . gmdate('Y-m-d') . '.log');
        check(str_contains($text, 'unit.redaction') && !str_contains($text, 'never-write-this'));
    });
    $suite->test('Private path checks handle descendants and prefix collisions', static function (): void {
        check(Config::within('D:/example/public/config.php', 'D:/example/public'));
        check(!Config::within('D:/example/public-other/config.php', 'D:/example/public'));
    });
};

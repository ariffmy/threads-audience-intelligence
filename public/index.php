<?php

declare(strict_types=1);

use Tai\Bootstrap\Application;
use Tai\Bootstrap\Composition;
use Tai\Modules\Identity\Infrastructure\NativeSession;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Http\Request;
use Tai\Shared\Http\Response;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Logger;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
umask(0077);
require dirname(__DIR__) . '/app/Bootstrap/autoload.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$logger = null;
try {
    $config = Config::load(dirname(__DIR__));
    if (realpath($_SERVER['DOCUMENT_ROOT'] ?? '') !== realpath(__DIR__)) {
        throw new RuntimeException('Public document root required.');
    }
    $logger = new Logger($config->storage('logs'));
    $request = Request::fromGlobals();
    $session = new NativeSession($config);
    Composition::web($config, $session, $logger)->handle($request)->send($request->method === 'HEAD');
} catch (Throwable $exception) {
    $reference = bin2hex(random_bytes(16));
    try {
        $logger?->event('http.bootstrap_failed', ['error_type' => $exception::class, 'correlation_id' => $reference]);
    } catch (Throwable) {
        error_log('TAI private logging unavailable; reference ' . $reference);
    }
    $status = $exception instanceof HttpException ? $exception->status : 503;
    $response = new Response(
        '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Application unavailable</title><body><h1>Application unavailable</h1>'
        . '<p>The application could not start safely. Check private configuration and database readiness.</p><p>Reference: '
        . $reference . '</p></body></html>',
        $status, Application::securityHeaders(false),
    );
    $response->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
}

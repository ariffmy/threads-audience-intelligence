<?php

declare(strict_types=1);

namespace Tai\Bootstrap;

use Tai\Modules\Access\Application\AdministratorGuard;
use Tai\Modules\Identity\Application\AuthenticationService;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Modules\Identity\Domain\SessionStore;
use Tai\Modules\Identity\Http\AuthenticationController;
use Tai\Modules\Operations\Http\AdminController;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Http\Request;
use Tai\Shared\Http\Response;
use Tai\Shared\Http\Router;
use Tai\Shared\Http\View;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Logger;

final class Application
{
    private readonly Router $router;

    public function __construct(
        private readonly Config $config,
        private readonly SessionStore $session,
        private readonly AuthenticationService $auth,
        private readonly Csrf $csrf,
        private readonly AuthenticationController $login,
        private readonly AdminController $admin,
        private readonly View $view,
        private readonly Logger $logger,
    ) {
        $this->router = new Router();
        foreach ([
            ['GET', '/', 'home', false], ['GET', '/login', 'loginForm', false], ['POST', '/login', 'login', false],
            ['POST', '/logout', 'logout', true], ['GET', '/dashboard', 'dashboard', true],
            ['GET', '/profile', 'profile', true], ['POST', '/profile', 'saveProfile', true],
            ['POST', '/profile/password', 'password', true], ['GET', '/settings', 'settings', true],
            ['POST', '/settings', 'saveSettings', true], ['GET', '/status', 'system', true],
            ['GET', '/status.json', 'systemJson', true],
        ] as [$method, $path, $handler, $protected]) {
            $this->router->add($method, $path, $handler, $protected);
        }
    }

    public function handle(Request $request): Response
    {
        $identity = null;
        $correlation = bin2hex(random_bytes(16));
        try {
            $this->validateOriginAndHost($request);
            $route = $this->router->match($request);
            $identity = $this->auth->identity();
            if ($route['protected'] && $identity === null) {
                $response = $request->expectsJson
                    ? Response::json(['error' => 'Authentication required.'], 401)
                    : Response::redirect('/login');
            } else {
                if ($identity !== null) {
                    (new AdministratorGuard())->assertAllowed($identity);
                }
                if ($request->method === 'POST' && !$this->csrf->valid($request->input['_csrf'] ?? null)) {
                    throw new HttpException(419, 'Your form has expired. Reload the page and try again.');
                }
                $response = match ($route['handler']) {
                    'home' => Response::redirect($identity === null ? '/login' : '/dashboard'),
                    'loginForm' => $this->login->form($identity),
                    'login' => $identity === null ? $this->login->login($request) : Response::redirect('/dashboard'),
                    'logout' => $this->login->logout($identity),
                    'dashboard' => $this->admin->dashboard($identity),
                    'profile' => $this->admin->profile($identity),
                    'saveProfile' => $this->admin->saveProfile($request, $identity),
                    'password' => $this->admin->password($request, $identity),
                    'settings' => $this->admin->settings($identity),
                    'saveSettings' => $this->admin->saveSettings($request, $identity),
                    'system' => $this->admin->system($identity),
                    'systemJson' => $this->admin->systemJson($identity),
                };
            }
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpException ? $exception->status
                : ($exception instanceof UserFacingException ? 422 : 500);
            $message = $status === 500 ? 'The application could not complete this request. Please try again later.' : $exception->getMessage();
            $this->logger->event('http.request_rejected', ['status' => $status, 'error_type' => $exception::class, 'correlation_id' => $correlation]);
            if ($request->path === '/login' && $status === 422 && !$request->expectsJson) {
                $response = $this->login->form(null, 'Unable to sign in with those credentials.', 422);
            } elseif ($request->expectsJson) {
                $response = Response::json(['error' => $message, 'reference' => $correlation], $status);
            } else {
                $response = $this->view->render('error', [
                    'title' => 'Request could not be completed', 'statusCode' => $status, 'message' => $message,
                    'reference' => $correlation, 'identity' => null,
                ], $status);
            }
            if ($status === 405) {
                $response->headers['Allow'] = $this->router->allowedMethods($request->path);
            }
        } finally {
            $this->session->close();
        }
        $response->headers += self::securityHeaders($this->config->secureCookies());
        $response->headers['X-Request-ID'] = $correlation;
        return $response;
    }

    public static function securityHeaders(bool $https): array
    {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            // no-referrer makes Chromium serialize ordinary form POST origins as
            // "null". same-origin preserves our origin check without external referrers.
            'Referrer-Policy' => 'same-origin', 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Type' => 'text/html; charset=utf-8',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $headers;
    }

    private function validateOriginAndHost(Request $request): void
    {
        $url = parse_url($this->config->get('app.url'));
        $host = strtolower($url['host'] . (isset($url['port']) ? ':' . $url['port'] : ''));
        if (!hash_equals($host, $request->host) || ($this->config->secureCookies() && !$request->secure)) {
            throw new HttpException(400, 'Use the configured application address and transport.');
        }
        if ($request->method === 'POST' && $request->origin !== null
            && !hash_equals(rtrim($this->config->get('app.url'), '/'), $request->origin)) {
            throw new HttpException(403, 'The request origin could not be verified.');
        }
    }
}

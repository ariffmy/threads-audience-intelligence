<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Http;

use Tai\Modules\Identity\Application\AuthenticationService;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Shared\Http\Request;
use Tai\Shared\Http\Response;
use Tai\Shared\Http\View;

final class AuthenticationController
{
    public function __construct(private readonly AuthenticationService $auth, private readonly Csrf $csrf, private readonly View $view)
    {
    }

    public function form(?array $identity = null, ?string $error = null, int $status = 200): Response
    {
        if ($identity !== null) {
            return Response::redirect('/dashboard');
        }
        return $this->view->render('login', [
            'title' => 'Sign in', 'identity' => null, 'csrf' => $this->csrf->token(), 'error' => $error,
        ], $status);
    }

    public function login(Request $request): Response
    {
        $retry = $this->auth->login($request->string('email', 254), $request->string('password', 72), $request->ip);
        if ($retry > 0) {
            $response = $this->form(null, 'Too many sign-in attempts. Please wait before trying again.', 429);
            $response->headers['Retry-After'] = (string) $retry;
            return $response;
        }
        return Response::redirect('/dashboard');
    }

    public function logout(array $identity): Response
    {
        $this->auth->logout($identity);
        return Response::redirect('/login');
    }
}

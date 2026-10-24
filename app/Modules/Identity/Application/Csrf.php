<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Application;

use Tai\Modules\Identity\Domain\SessionStore;

final class Csrf
{
    public function __construct(private readonly SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            return $this->rotate();
        }
        return $token;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->put('_csrf', $token);
        return $token;
    }

    public function valid(mixed $token): bool
    {
        $expected = $this->session->get('_csrf');
        return is_string($expected) && is_string($token) && strlen($token) === 64
            && hash_equals($expected, $token);
    }
}

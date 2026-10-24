<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Application;

use Tai\Shared\Domain\UserFacingException;

final class PasswordHasher
{
    public function __construct(private readonly int $bcryptCost = 12)
    {
    }

    public function hash(string $password): string
    {
        if (strlen($password) < 14 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new UserFacingException('Use a password of 14 to 72 bytes without null characters.');
        }
        return password_hash($password, $this->algorithm(), $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        return strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    private function algorithm(): string
    {
        return in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private function options(): array
    {
        return $this->algorithm() === PASSWORD_BCRYPT
            ? ['cost' => $this->bcryptCost]
            : ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1];
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Application;

use Tai\Modules\Access\Application\AdministratorGuard;
use Tai\Modules\Identity\Domain\SessionStore;
use Tai\Modules\Identity\Infrastructure\AuditLog;
use Tai\Modules\Identity\Infrastructure\LoginRateLimiter;
use Tai\Modules\Identity\Infrastructure\IdentityRepository;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Infrastructure\Config;

final class AuthenticationService
{
    public function __construct(
        private readonly IdentityRepository $repository,
        private readonly Config $config,
        private readonly SessionStore $session,
        private readonly PasswordHasher $passwords,
        private readonly AuditLog $audit,
        private readonly LoginRateLimiter $limiter,
    ) {
    }

    public function login(string $email, string $password, string $ip): int
    {
        $email = strtolower(trim($email));
        $retry = $this->limiter->consume($email, $ip);
        if ($retry > 0) {
            return $retry;
        }
        $user = $this->repository->loginUser($email);
        $valid = $user !== null && $this->passwords->verify($password, $user['password_hash']);
        if ($user === null) {
            // Comparable password work for unknown accounts, without a default credential.
            $this->passwords->hash('noncredential-timing-work-' . bin2hex(random_bytes(8)));
        }
        if (!$valid) {
            throw new HttpException(422, 'Unable to sign in with those credentials.');
        }
        try {
            (new AdministratorGuard())->assertAllowed($user);
        } catch (HttpException) {
            throw new HttpException(422, 'Unable to sign in with those credentials.');
        }
        $newHash = $this->passwords->needsRehash($user['password_hash']) ? $this->passwords->hash($password) : $user['password_hash'];
        $this->repository->begin();
        try {
            $current = $this->repository->lockLoginUser((string) $user['organization_id'], (string) $user['id']);
            if (!$current || !hash_equals($user['password_hash'], $current['password_hash'])) {
                throw new HttpException(422, 'Unable to sign in with those credentials.');
            }
            (new AdministratorGuard())->assertAllowed($current);
            $this->session->regenerate();
            $this->session->put('_authenticated', true);
            $this->session->put('_issued', time());
            $this->session->put('_seen', time());
            (new Csrf($this->session))->rotate();
            $this->repository->createSession($current, $this->sessionHash(), gmdate('Y-m-d H:i:s', time() + $this->config->get('session.absolute_seconds')), $newHash);
            $this->audit->record((string) $user['organization_id'], (string) $user['id'], 'auth.login');
            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();
            $this->session->remove('_authenticated');
            throw $exception;
        }
        return 0;
    }

    public function identity(): ?array
    {
        if ($this->session->get('_authenticated') !== true) {
            return null;
        }
        $identity = $this->repository->identity($this->sessionHash(), $this->config->get('session.idle_seconds'));
        if (!$identity) {
            $this->session->remove('_authenticated');
            $this->session->regenerate();
            (new Csrf($this->session))->rotate();
            return null;
        }
        return $identity;
    }

    public function logout(array $identity): void
    {
        try {
            $this->repository->begin();
            $this->repository->revokeSession($this->sessionHash(), (string) $identity['user_id'], (string) $identity['organization_id']);
            $this->audit->record((string) $identity['organization_id'], (string) $identity['user_id'], 'auth.logout');
            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();
            throw $exception;
        } finally {
            $this->session->destroy();
        }
    }

    public function updateProfile(array $identity, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new UserFacingException('Use a display name of 1 to 160 characters.');
        }
        $this->mutate($identity, function () use ($identity, $name): void {
            $this->repository->updateDisplayName((string) $identity['user_id'], $name);
            $this->audit->record((string) $identity['organization_id'], (string) $identity['user_id'], 'profile.updated', ['fields' => ['display_name']]);
        });
    }

    public function updateTimezone(array $identity, string $timezone): void
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new UserFacingException('Choose a valid IANA timezone.');
        }
        $this->mutate($identity, function () use ($identity, $timezone): void {
            $this->repository->updateTimezone((string) $identity['organization_id'], $timezone);
            $this->audit->record((string) $identity['organization_id'], (string) $identity['user_id'], 'settings.updated', ['timezone' => $timezone]);
        });
    }

    public function changePassword(array $identity, string $currentPassword, string $newPassword): void
    {
        $previousHash = $this->repository->passwordHash((string) $identity['user_id']);
        if (!$this->passwords->verify($currentPassword, $previousHash)) {
            throw new UserFacingException('The current password could not be verified.');
        }
        if ($currentPassword === $newPassword) {
            throw new UserFacingException('Choose a different new password.');
        }
        $newHash = $this->passwords->hash($newPassword);
        $this->mutate($identity, function () use ($identity, $newHash, $previousHash): void {
            if (!$this->repository->replacePassword((string) $identity['user_id'], $previousHash, $newHash)) {
                throw new UserFacingException('The password changed during this request. Sign in again.');
            }
            $this->audit->record((string) $identity['organization_id'], (string) $identity['user_id'], 'password.changed');
        });
        $this->session->destroy();
    }

    private function mutate(array $identity, callable $operation): void
    {
        $this->repository->begin();
        try {
            // Recheck the live session and authority under lock, not only a route snapshot.
            $current = $this->repository->lockAuthorizedIdentity((string) $identity['organization_id'], (string) $identity['user_id'], $this->sessionHash(), $this->config->get('session.idle_seconds'));
            if (!$current) {
                throw new HttpException(403, 'Your access changed. Sign in again.');
            }
            (new AdministratorGuard())->assertAllowed($current);
            $operation();
            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();
            throw $exception;
        }
    }

    private function sessionHash(): string
    {
        return hash_hmac('sha256', $this->session->id(), hex2bin($this->config->get('app.key')), true);
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Application;

use Tai\Modules\Identity\Infrastructure\AuditLog;
use Tai\Modules\Identity\Infrastructure\IdentityRepository;
use Tai\Shared\Domain\UserFacingException;

final class AdministratorProvisioner
{
    public function __construct(private readonly IdentityRepository $repository, private readonly PasswordHasher $passwords, private readonly AuditLog $audit)
    {
    }

    public function create(string $email, string $name, string $password): void
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $name === '' || mb_strlen($name) > 160 || !mb_check_encoding($name, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new UserFacingException('Provide a valid email and a display name of 1 to 160 characters.');
        }
        $hash = $this->passwords->hash($password);
        $lock = $this->repository->acquireBootstrapLock();
        try {
            $this->repository->begin();
            if ($this->repository->hasInstallation()) {
                throw new UserFacingException('The personal installation is already initialized; no additional administrator or organization was created.');
            }
            [$organizationId, $userId] = $this->repository->createPersonalIdentity($email, $name, $hash);
            $this->audit->record($organizationId, $userId, 'admin.created');
            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();
            throw $exception;
        } finally {
            $this->repository->releaseBootstrapLock($lock);
        }
    }
}

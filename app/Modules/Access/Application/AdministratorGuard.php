<?php

declare(strict_types=1);

namespace Tai\Modules\Access\Application;

use Tai\Shared\Http\HttpException;

final class AdministratorGuard
{
    public function assertAllowed(array $identity): void
    {
        if ((int) ($identity['is_installation_admin'] ?? 0) !== 1
            || ($identity['role'] ?? null) !== 'owner'
            || ($identity['user_status'] ?? null) !== 'active'
            || ($identity['membership_status'] ?? null) !== 'active'
            || ($identity['organization_status'] ?? null) !== 'active'
            || !isset($identity['organization_id'], $identity['user_id'])) {
            throw new HttpException(403, 'Administrator access is required.');
        }
    }
}

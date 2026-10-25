<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

interface ScopeGuard
{
    public function lock(string $organizationId): void;
    public function dispatch(string $organizationId, string $type, array $payload, string $jobId): void;
    public function eligible(array $row): bool;
    public function claimed(\Tai\Modules\Queue\Domain\Claim $claim, int $leaseSeconds): void;
    public function assertCurrent(array $row): void;
    public function failed(string $organizationId, string $jobId, bool $terminal, string $code): void;
}

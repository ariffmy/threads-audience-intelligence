<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Domain;

use Tai\Modules\Queue\Domain\JobPayload;

final readonly class TokenContext
{
    public function __construct(public string $organizationId, public string $accountId, public string $kind, public int $generation)
    {
        JobPayload::identifier($organizationId);
        JobPayload::identifier($accountId);
        if (!preg_match('/^(short|long|cursor:[1-9][0-9]{0,17})$/D', $kind) || $generation < 1) {
            throw new \InvalidArgumentException('Invalid encryption context.');
        }
    }

    public function associatedData(string $keyId): string
    {
        return json_encode(['v' => 1, 'provider' => 'threads', 'environment' => 'fixture', 'app' => 'fixture-app',
            'organization' => $this->organizationId, 'account' => $this->accountId, 'kind' => $this->kind,
            'generation' => $this->generation, 'key' => $keyId], JSON_THROW_ON_ERROR);
    }
}

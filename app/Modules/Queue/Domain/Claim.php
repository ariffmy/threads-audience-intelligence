<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Domain;

final readonly class Claim
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $type,
        public array $payload,
        public string $token,
        public int $generation,
        public int $attempt,
        public int $maxAttempts,
        public int $privacyEpoch,
        public string $correlationId,
    ) {
    }
}

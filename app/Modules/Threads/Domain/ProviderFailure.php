<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Domain;

final class ProviderFailure extends \RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly bool $transient = false)
    {
        if (!in_array($safeCode, ['live_disabled', 'provider_timeout', 'response_oversize', 'invalid_response', 'provider_throttled', 'provider_unavailable', 'grant_invalid', 'identity_mismatch', 'cursor_loop', 'collection_cap'], true)) {
            throw new \InvalidArgumentException('Unsafe provider failure category.');
        }
        parent::__construct($safeCode);
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use Tai\Modules\Threads\Application\HttpTransport;
use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\ProviderRequest;

final readonly class DisabledTransport implements HttpTransport
{
    public function send(ProviderRequest $request): array
    {
        throw new ProviderFailure('live_disabled');
    }
}

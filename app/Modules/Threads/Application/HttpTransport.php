<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Application;

use Tai\Modules\Threads\Domain\ProviderRequest;

interface HttpTransport
{
    /** Must enforce request deadline, decoded byte cap, bounded headers, TLS and no redirects. */
    public function send(ProviderRequest $request): array;
}

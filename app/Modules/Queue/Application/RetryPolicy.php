<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

final readonly class RetryPolicy
{
    public function __construct(private int $baseSeconds = 60, private int $capSeconds = 14400)
    {
        if ($baseSeconds < 1 || $baseSeconds > $capSeconds || $capSeconds > 86400) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
    }

    public function delay(int $attempt): int
    {
        if ($attempt < 1 || $attempt > 10) {
            throw new \InvalidArgumentException('Invalid attempt.');
        }
        $base = min($this->capSeconds, $this->baseSeconds * (2 ** ($attempt - 1)));
        return min($this->capSeconds, $base + random_int(0, max(1, intdiv($base, 4))));
    }
}

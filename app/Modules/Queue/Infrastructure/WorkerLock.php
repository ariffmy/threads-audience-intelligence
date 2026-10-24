<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Infrastructure;

final readonly class WorkerLock
{
    public function __construct(private string $directory)
    {
    }

    /** @return resource|null */
    public function acquire(string $org): mixed
    {
        \Tai\Modules\Queue\Domain\JobPayload::identifier($org);
        $file = fopen($this->directory . '/worker-' . $org . '.lock', 'c');
        if ($file === false) {
            throw new \RuntimeException('Worker lock unavailable.');
        }
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            fclose($file);
            return null;
        }
        return $file;
    }

    /** @param resource $file */
    public function release(mixed $file): void
    {
        fclose($file);
    }
}

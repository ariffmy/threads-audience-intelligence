<?php

declare(strict_types=1);

namespace Tai\Modules\Privacy\Domain;

final readonly class RetentionPolicy
{
    public function __construct(public int $sourceDays = 90, public int $exportDays = 7, public int $logDays = 30, public int $auditDays = 90, public int $backupDays = 30)
    {
        foreach ([[$sourceDays, 90], [$exportDays, 7], [$logDays, 30], [$auditDays, 90], [$backupDays, 30]] as [$value, $max]) {
            if ($value < 1 || $value > $max) {
                throw new \InvalidArgumentException('Retention must be positive and no longer than provisional defaults.');
            }
        }
    }

    public function values(): array
    {
        return [$this->sourceDays, $this->exportDays, $this->logDays, $this->auditDays, $this->backupDays];
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

use Tai\Modules\Queue\Domain\Claim;

interface JobHandler
{
    /** Bounded computation outside the publication transaction; no local side effects. */
    public function prepare(Claim $claim, WorkerBudget $budget): array;

    /** Called only inside the fenced transaction. Implementations must remain bounded. */
    public function publish(Claim $claim, array $result): void;
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Domain;

final class JobFailure extends \RuntimeException
{
    public function __construct(public readonly bool $transient)
    {
        parent::__construct($transient ? 'transient_failure' : 'permanent_failure');
    }
}

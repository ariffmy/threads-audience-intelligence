<?php

declare(strict_types=1);

namespace Tai\Modules\Privacy\Application;

use Tai\Modules\Privacy\Domain\RetentionPolicy;
use Tai\Modules\Privacy\Infrastructure\DeletionLedger;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Privacy\Infrastructure\PrivacyRepository;

final readonly class PrivacyService
{
    public function __construct(private QueueService $queue, private PrivacyRepository $repository, private DeletionLedger $ledger, private RetentionPolicy $policy)
    {
    }

    public function configure(string $organizationId): void
    {
        JobPayload::identifier($organizationId);
        $this->queue->transaction(function () use ($organizationId): void {
            $this->repository->scope($organizationId);
            $this->repository->configure($organizationId, $this->policy);
        });
    }

    public function deleteSyntheticScope(string $organizationId, string $key): void
    {
        JobPayload::identifier($organizationId);
        // Validate and anchor the organization FK before recording irreversible
        // intent. A mistyped nonexistent ID must not poison future ledger replay.
        $this->queue->transaction(fn () => $this->repository->scope($organizationId));
        // Record conservative intent first: a crash before DB commit is safe to replay.
        $this->ledger->append($organizationId, $key);
        $this->applyIntent($organizationId, $key);
    }

    public function applyIntent(string $organizationId, string $key): void
    {
        JobPayload::identifier($organizationId);
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid deletion request key.');
        }
        $this->queue->transaction(function () use ($organizationId, $key): void {
            $scope = $this->repository->scope($organizationId);
            if ($this->repository->hasRequest($organizationId, $key)) {
                return;
            }
            $epoch = (int) $scope['privacy_epoch'] + 1;
            $this->repository->block($organizationId, $key, $epoch, $this->policy->backupDays);
            $this->queue->dispatch($organizationId, 'retention.sweep', [], 'delete:' . $key);
            $this->audit($organizationId, 'privacy.blocked');
        });
    }

    public function audit(string $organizationId, string $event): void
    {
        if (!in_array($event, ['privacy.blocked', 'privacy.purged', 'retention.swept'], true)) {
            throw new \InvalidArgumentException('Unsupported privacy audit event.');
        }
        $this->repository->audit($organizationId, $event, $this->policy->auditDays);
    }
}

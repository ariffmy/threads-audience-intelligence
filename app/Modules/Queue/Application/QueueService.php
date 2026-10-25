<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Application;

use PDO;
use Tai\Modules\Queue\Domain\Claim;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Queue\Domain\LostLease;
use Tai\Modules\Queue\Infrastructure\QueueRepository;

final readonly class QueueService
{
    public function __construct(private PDO $pdo, private QueueRepository $repository, private RetryPolicy $retry, private int $leaseSeconds = 90, private int $retentionDays = 30, private ?ScopeGuard $guard = null)
    {
        if ($leaseSeconds < 30 || $leaseSeconds > 300 || $retentionDays < 1 || $retentionDays > 30) {
            throw new \InvalidArgumentException('Invalid queue configuration.');
        }
    }

    /** Application services own transactions. Nested calls join the current unit of work. */
    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function dispatch(string $organizationId, string $type, array $payload, string $key, int $priority = 0, int $maxAttempts = 5, ?string $availableAt = null): string
    {
        JobPayload::identifier($organizationId);
        $json = JobPayload::validate($type, $payload);
        if (str_starts_with($type, 'threads.') && $this->guard === null) {
            throw new \LogicException('Threads jobs require account fencing.');
        }
        $date = $availableAt === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $availableAt, new \DateTimeZone('UTC'));
        if (!preg_match('/^[a-zA-Z0-9:._-]{1,128}$/D', $key) || $priority < -100 || $priority > ($type === 'retention.sweep' ? 100 : 99)
            || $maxAttempts < 1 || $maxAttempts > 10
            || ($availableAt !== null && (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $availableAt)
                || $date === false || $date->format('Y-m-d H:i:s') !== $availableAt))) {
            throw new \InvalidArgumentException('Invalid dispatch metadata.');
        }
        return $this->transaction(function () use ($organizationId, $type, $payload, $json, $key, $priority, $maxAttempts, $availableAt): string {
            $scope = $this->repository->scope($organizationId);
            $this->guard?->lock($organizationId);
            if (($scope['blocked_at'] !== null || !$scope['organization_active']) && $type !== 'retention.sweep') {
                throw new \DomainException('Scope is blocked.');
            }
            $id = $this->repository->dispatch($organizationId, $type, $json, $key, (int) $scope['privacy_epoch'], $type === 'retention.sweep' ? 100 : $priority, $maxAttempts, $availableAt, $this->retentionDays);
            $this->guard?->dispatch($organizationId, $type, $payload, $id);
            return $id;
        });
    }

    public function claim(string $organizationId): ?Claim
    {
        JobPayload::identifier($organizationId);
        return $this->transaction(function () use ($organizationId): ?Claim {
            $scope = $this->repository->scope($organizationId);
            $this->guard?->lock($organizationId);
            $row = $this->repository->due($organizationId, $scope['blocked_at'] !== null || !$scope['organization_active']);
            if ($row === false) {
                return null;
            }
            // Corrupt/old-release work is failed safely before it reaches a handler.
            try {
                $payload = json_decode($row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
                if ((int) $row['payload_version'] !== 1 || !is_array($payload)) {
                    throw new \InvalidArgumentException();
                }
                JobPayload::validate($row['job_type'], $payload);
            } catch (\Throwable) {
                $this->repository->reject($organizationId, (string) $row['id'], 'failed', 'invalid_payload');
                return null;
            }
            if ($this->guard !== null && !$this->guard->eligible($row)) {
                return null;
            }
            if ((int) $row['scope_privacy_epoch'] !== (int) $scope['privacy_epoch'] && $row['job_type'] !== 'retention.sweep') {
                $this->repository->reject($organizationId, (string) $row['id'], 'cancelled', null);
                return null;
            }
            $token = random_bytes(32);
            $generation = (int) $row['claim_generation'] + 1;
            $correlation = bin2hex(random_bytes(16));
            $this->repository->start($row, (int) $scope['privacy_epoch'], $token, $generation, $this->leaseSeconds, $correlation);
            $claim = new Claim((string) $row['id'], $organizationId, $row['job_type'], $payload, $token, $generation, (int) $row['attempts'] + 1, (int) $row['max_attempts'], (int) $scope['privacy_epoch'], $correlation);
            $this->guard?->claimed($claim, $this->leaseSeconds);
            return $claim;
        });
    }

    private function assertCurrent(Claim $claim, bool $integrationFence = true): void
    {
        $scope = $this->repository->scope($claim->organizationId);
        $this->guard?->lock($claim->organizationId);
        $row = $this->repository->current($claim->organizationId, $claim->id);
        if (!$row || $row['status'] !== 'running' || !(bool) $row['lease_valid']
            || $row['job_type'] !== $claim->type || (int) $row['scope_privacy_epoch'] !== $claim->privacyEpoch
            || (int) $row['claim_generation'] !== $claim->generation
            || !hash_equals((string) $row['lease_token_hash'], hash('sha256', $claim->token, true))
            || (int) $scope['privacy_epoch'] !== $claim->privacyEpoch
            || (($scope['blocked_at'] !== null || !$scope['organization_active']) && $claim->type !== 'retention.sweep')) {
            throw new LostLease('Work no longer owns an active lease or privacy scope.');
        }
        if ($integrationFence) {
            $this->guard?->assertCurrent($row);
        }
    }

    /** Short pre-call reservation transaction; network work must follow after it returns. */
    public function inspect(Claim $claim, callable $operation): mixed
    {
        return $this->transaction(function () use ($claim, $operation): mixed {
            $this->assertCurrent($claim);
            return $operation();
        });
    }

    public function complete(Claim $claim, callable $publish, int $durationMs = 0): void
    {
        $this->transaction(function () use ($claim, $publish, $durationMs): void {
            $started = hrtime(true);
            $this->assertCurrent($claim);
            $publish();
            // A bounded publication may still exceed its lease: roll back all effects.
            $this->assertCurrent($claim, false);
            $this->repository->transition($claim, 'succeeded', null, 0, $durationMs + (int) ((hrtime(true) - $started) / 1000000));
        });
    }

    public function fail(Claim $claim, bool $transient, string $code = 'handler_failed', int $durationMs = 0): void
    {
        if (!in_array($code, ['handler_failed', 'transient_failure', 'permanent_failure', 'budget_exhausted', 'provider_timeout', 'provider_throttled', 'provider_unavailable'], true)) {
            throw new \InvalidArgumentException('Unsafe error code.');
        }
        $this->transaction(function () use ($claim, $transient, $code, $durationMs): void {
            $this->assertCurrent($claim);
            $retry = $transient && $claim->attempt < $claim->maxAttempts;
            $this->guard?->failed($claim->organizationId, $claim->id, !$retry, $code);
            $this->repository->transition($claim, $retry ? 'retry_wait' : 'failed', $code, $retry ? $this->retry->delay($claim->attempt) : 0, $durationMs);
        });
    }

    public function recover(string $organizationId, int $limit = 100): int
    {
        JobPayload::identifier($organizationId);
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid recovery limit.');
        }
        return $this->transaction(function () use ($organizationId, $limit): int {
            $scope = $this->repository->scope($organizationId);
            $this->guard?->lock($organizationId);
            $rows = $this->repository->expiredRunning($organizationId, $limit);
            foreach ($rows as $row) {
                $cancelled = ($scope['blocked_at'] !== null || !$scope['organization_active'] || (int) $scope['privacy_epoch'] !== (int) $row['scope_privacy_epoch']) && $row['job_type'] !== 'retention.sweep';
                $status = $cancelled ? 'cancelled' : ((int) $row['attempts'] < (int) $row['max_attempts'] ? 'retry_wait' : 'failed');
                $this->guard?->failed($organizationId, (string) $row['id'], $status !== 'retry_wait', 'lease_expired');
                $claim = new Claim((string) $row['id'], $organizationId, $row['job_type'], [], '', (int) $row['claim_generation'], (int) $row['attempts'], (int) $row['max_attempts'], (int) $row['scope_privacy_epoch'], '');
                $this->repository->transition($claim, $status, 'lease_expired', $status === 'retry_wait' ? $this->retry->delay($claim->attempt) : 0, 0);
            }
            // Expired ready jobs must not remain pending forever.
            $this->repository->expirePending($organizationId, $limit);
            return count($rows);
        });
    }
}

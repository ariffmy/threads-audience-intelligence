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
    public function __construct(private PDO $pdo, private QueueRepository $repository, private RetryPolicy $retry, private int $leaseSeconds = 90, private int $retentionDays = 30)
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
        $date = $availableAt === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $availableAt, new \DateTimeZone('UTC'));
        if (!preg_match('/^[a-zA-Z0-9:._-]{1,128}$/D', $key) || $priority < -100 || $priority > ($type === 'retention.sweep' ? 100 : 99)
            || $maxAttempts < 1 || $maxAttempts > 10
            || ($availableAt !== null && (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $availableAt)
                || $date === false || $date->format('Y-m-d H:i:s') !== $availableAt))) {
            throw new \InvalidArgumentException('Invalid dispatch metadata.');
        }
        return $this->transaction(function () use ($organizationId, $type, $json, $key, $priority, $maxAttempts, $availableAt): string {
            $scope = $this->repository->scope($organizationId);
            if (($scope['blocked_at'] !== null || !$scope['organization_active']) && $type !== 'retention.sweep') {
                throw new \DomainException('Scope is blocked.');
            }
            return $this->repository->dispatch($organizationId, $type, $json, $key, (int) $scope['privacy_epoch'], $type === 'retention.sweep' ? 100 : $priority, $maxAttempts, $availableAt, $this->retentionDays);
        });
    }

    public function claim(string $organizationId): ?Claim
    {
        JobPayload::identifier($organizationId);
        return $this->transaction(function () use ($organizationId): ?Claim {
            $scope = $this->repository->scope($organizationId);
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
            if ((int) $row['scope_privacy_epoch'] !== (int) $scope['privacy_epoch'] && $row['job_type'] !== 'retention.sweep') {
                $this->repository->reject($organizationId, (string) $row['id'], 'cancelled', null);
                return null;
            }
            $token = random_bytes(32);
            $generation = (int) $row['claim_generation'] + 1;
            $correlation = bin2hex(random_bytes(16));
            $this->repository->start($row, (int) $scope['privacy_epoch'], $token, $generation, $this->leaseSeconds, $correlation);
            return new Claim((string) $row['id'], $organizationId, $row['job_type'], $payload, $token, $generation, (int) $row['attempts'] + 1, (int) $row['max_attempts'], (int) $scope['privacy_epoch'], $correlation);
        });
    }

    private function assertCurrent(Claim $claim): void
    {
        $scope = $this->repository->scope($claim->organizationId);
        $row = $this->repository->current($claim->organizationId, $claim->id);
        if (!$row || $row['status'] !== 'running' || !(bool) $row['lease_valid']
            || $row['job_type'] !== $claim->type || (int) $row['scope_privacy_epoch'] !== $claim->privacyEpoch
            || (int) $row['claim_generation'] !== $claim->generation
            || !hash_equals((string) $row['lease_token_hash'], hash('sha256', $claim->token, true))
            || (int) $scope['privacy_epoch'] !== $claim->privacyEpoch
            || (($scope['blocked_at'] !== null || !$scope['organization_active']) && $claim->type !== 'retention.sweep')) {
            throw new LostLease('Work no longer owns an active lease or privacy scope.');
        }
    }

    public function complete(Claim $claim, callable $publish, int $durationMs = 0): void
    {
        $this->transaction(function () use ($claim, $publish, $durationMs): void {
            $started = hrtime(true);
            $this->assertCurrent($claim);
            $publish();
            // A bounded publication may still exceed its lease: roll back all effects.
            $this->assertCurrent($claim);
            $this->repository->transition($claim, 'succeeded', null, 0, $durationMs + (int) ((hrtime(true) - $started) / 1000000));
        });
    }

    public function fail(Claim $claim, bool $transient, string $code = 'handler_failed', int $durationMs = 0): void
    {
        if (!in_array($code, ['handler_failed', 'transient_failure', 'permanent_failure', 'budget_exhausted'], true)) {
            throw new \InvalidArgumentException('Unsafe error code.');
        }
        $this->transaction(function () use ($claim, $transient, $code, $durationMs): void {
            $this->assertCurrent($claim);
            $retry = $transient && $claim->attempt < $claim->maxAttempts;
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
            $rows = $this->repository->expiredRunning($organizationId, $limit);
            foreach ($rows as $row) {
                $cancelled = ($scope['blocked_at'] !== null || !$scope['organization_active'] || (int) $scope['privacy_epoch'] !== (int) $row['scope_privacy_epoch']) && $row['job_type'] !== 'retention.sweep';
                $status = $cancelled ? 'cancelled' : ((int) $row['attempts'] < (int) $row['max_attempts'] ? 'retry_wait' : 'failed');
                $claim = new Claim((string) $row['id'], $organizationId, $row['job_type'], [], '', (int) $row['claim_generation'], (int) $row['attempts'], (int) $row['max_attempts'], (int) $row['scope_privacy_epoch'], '');
                $this->repository->transition($claim, $status, 'lease_expired', $status === 'retry_wait' ? $this->retry->delay($claim->attempt) : 0, 0);
            }
            // Expired ready jobs must not remain pending forever.
            $this->repository->expirePending($organizationId, $limit);
            return count($rows);
        });
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Application;

use Tai\Modules\Privacy\Infrastructure\DeletionLedger;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Threads\Infrastructure\ThreadsRepository;
use Tai\Modules\Queue\Domain\JobPayload;

final readonly class ThreadsPrivacy
{
    public function __construct(private ThreadsRepository $repository, private QueueService $queue, public DeletionLedger $ledger, private \Tai\Modules\Threads\Infrastructure\PrivacyRecords $records)
    {
    }

    public function delete(array $identity, string $account, ?string $source = null, bool $organization = false): void
    {
        $org = $this->repository->authorize($identity);
        $intent = $this->queue->transaction(function () use ($org, $account, $source, $organization): array {
            $this->repository->sql->scope($org);
            $this->records->lockAccount([$org, $account])->fetch();
            $a = $this->repository->account($org, $account);
            $hash = str_repeat('0', 64);
            if ($source !== null) {
                $row = $this->repository->source($org, $account, $source);
                $hash = $this->ledger->identifierHash($org, $a['scope_key'], $row['remote_media_id']);
            }
            return ['organization_id' => $org, 'request_key' => bin2hex(random_bytes(16)),
                'target_kind' => $organization ? 'threads_organization' : ($source === null ? 'threads_account' : 'threads_source'),
                'scope_key' => $a['scope_key'], 'source_hash' => $hash];
        });
        $this->ledger->appendTarget($intent); // Durable independently before primary blocking.
        $this->apply($intent);
    }

    /** Local synthetic revocation signal only; no unverified provider callback protocol is accepted. */
    public function revoke(array $identity, string $account): void
    {
        $this->delete($identity, $account);
        $this->queue->transaction(function () use ($identity, $account): void {
            $org = $this->repository->authorize($identity);
            $this->repository->sql->scope($org);
            $this->records->lockAccount([$org, $account])->fetch();
            $this->records->markRevoked([$org, $account]);
        });
    }

    public function apply(array $intent): void
    {
        JobPayload::identifier($intent['organization_id'] ?? '');
        if (!in_array($intent['target_kind'] ?? '', ['threads_account', 'threads_source', 'threads_organization'], true)
            || !is_string($intent['request_key'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $intent['request_key'])
            || !is_string($intent['scope_key'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $intent['scope_key'])
            || !is_string($intent['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $intent['source_hash'])) {
            throw new \InvalidArgumentException('Unsupported deletion intent.');
        }
        $this->queue->transaction(function () use ($intent): void {
            $org = $intent['organization_id'];
            $hasOrganization = (bool) $this->records->organizationExists([$org])->fetchColumn();
            if ($hasOrganization) {
                $this->repository->sql->scope($org);
            }
            $a = $this->records->lockScopeAccount([$org, $intent['scope_key']])->fetch();
            $existing = $this->records->request([$org, $intent['request_key']])->fetch();
            if ($existing) {
                if ($existing['target_kind'] !== $intent['target_kind'] || $existing['scope_key'] !== $intent['scope_key'] || $existing['source_hash'] !== $intent['source_hash']) {
                    throw new \RuntimeException('Deletion key conflicts with an existing intent.');
                }
                // Reapply even if marked complete: a restored content/file subset must still be purged.
            } else {
                $this->records->insertIntent(
                    [$org, $intent['request_key'], $intent['target_kind'], $intent['scope_key'], $intent['source_hash']]);
            }
            if ($a) {
                $id = (string) $a['id'];
                $this->records->bumpFence([(int) ($intent['target_kind'] !== 'threads_source'), $org, $id]);
                $this->repository->cancelWork($org, $id, 'privacy_blocked');
                if ($intent['target_kind'] !== 'threads_source') {
                    $this->records->disconnect([$org, $id]);
                    $this->records->deleteCredentials([$org, $id]);
                    $this->records->deleteGrants([$org, $id]);
                    $this->records->deleteStates([$org, $id]);
                } else {
                    $this->blockSuppressedSources($org, $a);
                }
            }
            $this->records->reopenIntent([$org, $intent['request_key']]);
            if ($hasOrganization) {
                $this->queue->dispatch($org, 'retention.sweep', [], 'threads:purge:' . $intent['request_key'] . ':' . ($a ? (int) $a['privacy_epoch'] + 1 : 0));
            } else {
                // An older isolated restore can predate this tenant. Keep its suppression without inventing a tenant/job.
                $this->records->completeAccountIntents([$org, $intent['scope_key']]);
            }
        });
    }

    private function blockSuppressedSources(string $org, array $a): void
    {
        // Bounded deletion manifests use media HMACs; SQL also stores a precomputed suppression key.
        $this->records->blockSuppressedSources([$a['scope_key'], $org, $a['id']]);
    }

    public function suppressed(string $org, array $a, string $media): bool
    {
        $hash = $this->ledger->identifierHash($org, $a['scope_key'], $media);
        return (bool) $this->records->isSuppressed([$org, $a['scope_key'], $hash])->fetchColumn();
    }

    /** Outside publication: durably record a bounded set of expiry intents. */
    public function prepareSweep(string $org): array
    {
        $a = $this->records->account([$org])->fetch();
        if (!$a) {
            return [];
        }
        $intents = [];
        $rows = $this->records->expiredSources([$org, $a['id'], -$this->repository->sourceDays])->fetchAll();
        foreach ($rows as $row) {
            $intent = ['organization_id' => $org, 'request_key' => substr(hash('sha256', 'retention:' . $a['scope_key'] . ':' . $row['suppression_hash']), 0, 32),
                'target_kind' => 'threads_source', 'scope_key' => $a['scope_key'], 'source_hash' => $row['suppression_hash']];
            $this->ledger->appendTarget($intent);
            $intents[] = $intent;
        }
        return $intents;
    }

    /** Queue owns organization/account/job locks; every delete is bounded and dependency ordered. */
    public function sweep(string $org, array $intents): bool
    {
        foreach ($intents as $intent) {
            $this->apply($intent);
        }
        $a = $this->records->account([$org])->fetch();
        if (!$a) {
            return false;
        }
        $id = (string) $a['id']; $blocked = $a['blocked_at'] !== null;
        $this->blockSuppressedSources($org, $a);
        $this->records->purgeExpiredHandle([$org, $id, -$this->repository->sourceDays]);
        $this->records->purgeExpiredGrants([$org, $id]);
        // Expired credentials can never keep collection active, independently of purge cadence.
        $this->records->purgeExpiredCredential([$org, $id]);
        $this->records->purgeStates([$org, $id]);
        $requestCount = $this->records->purgeRequests([$org, $id, (int) $blocked, -$this->repository->logDays])->rowCount();
        // Runs/checkpoints referencing an expired/blocked root must disappear before the root.
        $runs = $this->records->expiredRuns([$org, $id, (int) $blocked, -$this->repository->logDays])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($runs as $run) {
            $this->records->deleteCheckpoint([$org, $id, $run]);
            $this->records->deleteRun([$org, $id, $run]);
        }
        $removed = $this->records->purgeReplies([$org, $id, (int) $blocked])->rowCount();
        $roots = $this->records->unreferencedRoots([$org, $id, (int) $blocked])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($roots as $root) {
            $this->records->deleteSource([$org, $id, $root]);
        }
        // Provider job history has no content dependency; preserve running ownership and the current retention job.
        $jobs = $this->records->expiredJobs([$org, $id, (int) $blocked])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($jobs as $job) {
            $this->records->deleteAttempts([$org, $job]);
            $this->records->deleteJob([$org, $job]);
        }
        if ($blocked) {
            if (!$this->records->hasSources([$org, $id])->fetchColumn()
                && count($runs) < 100 && $requestCount < 100 && count($jobs) < 100) {
                $this->records->clearIdentity([$org, $id]);
                $this->records->completeAccountIntents([$org, $a['scope_key']]);
            }
        } else {
            $this->records->completeSourceIntents([$org, $a['scope_key'], $id]);
        }
        return max(count($intents), count($runs), $requestCount, $removed, count($roots), count($jobs)) >= 10;
    }
}

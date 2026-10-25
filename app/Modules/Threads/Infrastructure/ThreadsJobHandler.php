<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use Tai\Modules\Queue\Application\JobHandler;
use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\Claim;
use Tai\Modules\Queue\Domain\LostLease;
use Tai\Modules\Threads\Application\AccountService;
use Tai\Modules\Threads\Application\RequestReservations;
use Tai\Modules\Threads\Application\ThreadsClient;
use Tai\Modules\Threads\Application\ThreadsPrivacy;
use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\TokenContext;

final readonly class ThreadsJobHandler implements JobHandler
{
    public function __construct(private ThreadsRepository $repository, private QueueService $queue, private ThreadsClient $client,
        private RequestReservations $reservations, private AccountService $accounts, private ThreadsPrivacy $privacy, private \Closure $vault)
    {
    }

    public function prepare(Claim $claim, WorkerBudget $budget): array
    {
        $this->accounts->assertFixtureEnabled();
        $timeout = $budget->requestTimeoutMs();
        $snapshot = $this->queue->inspect($claim, function () use ($claim): array {
            $org = $claim->organizationId; $id = $claim->payload['account'];
            $a = $this->repository->account($org, $id);
            $credential = $this->repository->credential($org, $id);
            $step = $claim->payload['step'] ?? null;
            if (!$credential['valid'] || (int) $credential['generation'] !== (int) $a['credential_generation']
                || ($step === 'long' && $credential['kind'] !== 'short')
                || ($step === 'refresh' && ($credential['kind'] !== 'long' || (int) $credential['age_seconds'] < 86400 || !$this->repository->active($a)))) {
                return ['error' => 'grant_invalid'];
            }
            $run = isset($claim->payload['run']) ? $this->repository->run($org, $id, $claim->payload['run']) : null;
            $root = $run !== null && $run['root_id'] !== null ? $this->repository->source($org, $id, (string) $run['root_id']) : null;
            $token = ($this->vault)()->open($credential['envelope'], new TokenContext($org, $id, $credential['kind'], (int) $a['credential_generation']));
            $cursor = $run !== null && $run['cursor_envelope'] !== null
                ? ($this->vault)()->open($run['cursor_envelope'], new TokenContext($org, $id, 'cursor:' . $run['id'], (int) $run['version'])) : null;
            $request = $this->reservations->reserve($a, $step ?? $run['mode'], substr(hash('sha256', $claim->token), 0, 32));
            return ['account' => $a, 'credential' => $credential, 'run' => $run, 'root' => $root, 'token' => $token, 'cursor' => $cursor, 'request' => $request];
        });
        if (isset($snapshot['error'])) {
            return $snapshot;
        }
        if ($this->queue->inTransaction()) {
            throw new \LogicException('Provider call cannot run inside a transaction.');
        }
        try {
            $timeout = min($timeout, $budget->remainingRequestTimeoutMs());
            $run = $snapshot['run'];
            $data = $run === null
                ? $this->client->lifecycle($claim->payload['step'], $snapshot['token'], $timeout)
                : $this->client->page($run['mode'], $snapshot['token'], $snapshot['cursor'], $run['window_start'], $run['window_end'], $snapshot['root']['remote_media_id'] ?? null, $timeout);
            if (isset($data['identity']) && $data['identity'] !== $snapshot['account']['remote_user_id']) {
                throw new ProviderFailure('identity_mismatch');
            }
            if ($run !== null) {
                foreach ($data['items'] as $item) {
                    if (($run['mode'] === 'posts' && $item['owner'] !== $snapshot['account']['remote_user_id'])
                        || ($run['mode'] !== 'posts' && ($item['id'] === $snapshot['root']['remote_media_id']
                            || ($item['root_post'] !== null && $item['root_post'] !== $snapshot['root']['remote_media_id'])
                            || ($run['mode'] === 'replies' && $item['replied_to'] !== null && $item['replied_to'] !== $snapshot['root']['remote_media_id'])))) {
                        throw new ProviderFailure('identity_mismatch');
                    }
                }
                $hashes = json_decode($run['cursor_hashes'], true, 16, JSON_THROW_ON_ERROR);
                if ($data['cursor'] !== null && in_array(hash('sha256', $data['cursor']), $hashes, true)) {
                    throw new ProviderFailure('cursor_loop');
                }
            }
            return ['data' => $data, 'request' => $snapshot['request']];
        } catch (ProviderFailure $error) {
            if ($error->transient) {
                $this->queue->inspect($claim, fn () => $this->reservations->finish($snapshot['account'], $snapshot['request'], $error->safeCode));
                throw $error;
            }
            return ['error' => $error->safeCode, 'request' => $snapshot['request']];
        } finally {
            unset($snapshot['token']);
        }
    }

    public function publish(Claim $claim, array $result): void
    {
        $org = $claim->organizationId; $id = $claim->payload['account'];
        $a = $this->repository->account($org, $id);
        if (isset($result['request'])) {
            if ($a['request_key'] !== $result['request'] || !$a['request_busy']) {
                throw new LostLease('Provider reservation expired.');
            }
            $this->reservations->finish($a, $result['request'], $result['error'] ?? null);
        }
        if (isset($result['error'])) {
            $this->repository->sql->execute('UPDATE threads_accounts SET safe_error_code=? WHERE organization_id=? AND id=?', [$result['error'], $org, $id]);
            if (isset($claim->payload['run'])) {
                $this->repository->sql->execute("UPDATE threads_sync_runs SET status='partial',safe_error_code=? WHERE organization_id=? AND threads_account_id=? AND id=?", [$result['error'], $org, $id, $claim->payload['run']]);
            }
            if (in_array($result['error'], ['grant_invalid', 'identity_mismatch'], true) || !isset($claim->payload['run'])) {
                $this->repository->sql->execute("UPDATE threads_accounts SET status='reauth_required',credential_generation=credential_generation+1,permission_expires_at=NULL,token_expires_at=NULL WHERE organization_id=? AND id=?", [$org, $id]);
                $this->repository->sql->execute('DELETE FROM threads_credentials WHERE organization_id=? AND threads_account_id=?', [$org, $id]);
                $this->repository->sql->execute('DELETE FROM threads_account_grants WHERE organization_id=? AND threads_account_id=?', [$org, $id]);
                // Current job finishes under the original lease; other jobs fail their immutable generation fence.
                $this->repository->sql->execute("UPDATE threads_sync_runs SET status='blocked',safe_error_code=? WHERE organization_id=? AND threads_account_id=? AND status IN ('pending','running')", [$result['error'], $org, $id]);
            }
            return;
        }
        if (isset($claim->payload['step'])) {
            $this->lifecycle($claim, $a, $result['data']);
            return;
        }
        $this->page($claim, $a, $result['data']);
    }

    private function lifecycle(Claim $claim, array $a, array $data): void
    {
        $org = $claim->organizationId; $id = $claim->payload['account']; $step = $claim->payload['step'];
        if ($step === 'long' || $step === 'refresh') {
            $a['credential_generation'] = (int) $a['credential_generation'] + 1;
            $this->repository->sql->execute("UPDATE threads_accounts SET credential_generation=?,status='pending',permission_expires_at=NULL WHERE organization_id=? AND id=?", [$a['credential_generation'], $org, $id]);
            $envelope = ($this->vault)()->seal($data['token'], new TokenContext($org, $id, 'long', $a['credential_generation']));
            $this->repository->storeCredential($a, 'long', $envelope, gmdate('Y-m-d H:i:s', time() + $data['expires_in']));
            $next = 'debug';
            $this->repository->sql->execute("UPDATE threads_sync_runs SET status='cancelled',safe_error_code='scope_changed' WHERE organization_id=? AND threads_account_id=? AND status IN ('pending','running')", [$org, $id]);
        } elseif ($step === 'debug') {
            $this->repository->sql->execute('DELETE FROM threads_account_grants WHERE organization_id=? AND threads_account_id=?', [$org, $id]);
            foreach ($data['scopes'] as $permission) {
                $this->repository->sql->execute('INSERT INTO threads_account_grants (organization_id,threads_account_id,permission,revision,expires_at) VALUES (?,?,?,?,?)', [$org, $id, $permission, (int) $a['grant_revision'], $data['permission_expiry']]);
            }
            $this->repository->sql->execute('UPDATE threads_accounts SET token_expires_at=LEAST(token_expires_at,?),permission_expires_at=? WHERE organization_id=? AND id=?', [$data['token_expiry'], $data['permission_expiry'], $org, $id]);
            $this->repository->sql->execute('UPDATE threads_credentials SET verified_at=UTC_TIMESTAMP(6),expires_at=LEAST(expires_at,?) WHERE organization_id=? AND threads_account_id=?', [$data['token_expiry'], $org, $id]);
            $next = 'profile';
        } else {
            $credential = $this->repository->credential($org, $id);
            $count = (int) $this->repository->sql->execute('SELECT COUNT(*) FROM threads_account_grants WHERE organization_id=? AND threads_account_id=? AND revision=? AND expires_at>UTC_TIMESTAMP(6)', [$org, $id, (int) $a['grant_revision']])->fetchColumn();
            if (!$a['token_valid'] || !$a['permission_valid'] || !$credential['valid'] || $credential['verified_at'] === null || $count !== 2) {
                throw new LostLease('Identity activation requires a valid credential and grants.');
            }
            $this->repository->sql->execute("UPDATE threads_accounts SET username=?,profile_expires_at=LEAST(TIMESTAMPADD(DAY,?,created_at),TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))),status='active',safe_error_code=NULL WHERE organization_id=? AND id=?", [$data['username'], $this->repository->sourceDays, $this->repository->sourceDays, $org, $id]);
            return;
        }
        $this->queue->dispatch($org, 'threads.validate', ['account' => $id, 'step' => $next], 'threads:' . $next . ':' . $id . ':' . $a['credential_generation'], 80);
    }

    private function page(Claim $claim, array $a, array $data): void
    {
        $org = $claim->organizationId; $id = $claim->payload['account'];
        $run = $this->repository->run($org, $id, $claim->payload['run']);
        $root = $run['root_id'] === null ? null : $this->repository->source($org, $id, (string) $run['root_id']);
        $retained = 0; $unknown = 0;
        foreach ($data['items'] as $item) {
            if ($item['timestamp'] === null) {
                $unknown++;
                continue; // Unknown-time bodies are outside the bounded dated sample.
            }
            if ($item['timestamp'] < $run['window_start'] || $item['timestamp'] >= $run['window_end'] || $this->privacy->suppressed($org, $a, $item['id'])) {
                continue;
            }
            $existing = $this->repository->sql->execute('SELECT id,kind,root_id,content_hash,blocked_at,expires_at,first_collected_at FROM content_items WHERE organization_id=? AND threads_account_id=? AND remote_media_id=?', [$org, $id, $item['id']])->fetch();
            $kind = $run['mode'] === 'posts' ? 'owned_post' : 'reply';
            if ($existing && ($existing['kind'] !== $kind || ($existing['root_id'] === null ? null : (string) $existing['root_id']) !== ($root === null ? null : (string) $root['id']))) {
                throw new ProviderFailure('identity_mismatch');
            }
            if ($existing && ($existing['blocked_at'] !== null || $existing['expires_at'] <= gmdate('Y-m-d H:i:s') || $existing['first_collected_at'] <= gmdate('Y-m-d H:i:s', time() - $this->repository->sourceDays * 86400))) {
                continue;
            }
            $hash = hash('sha256', json_encode([$kind, $item, $root['id'] ?? null], JSON_THROW_ON_ERROR), true);
            $provenance = json_encode(['environment' => 'fixture', 'endpoint' => $run['mode'], 'api_version' => 'v1.0', 'projection_version' => 1,
                'owner_verified' => true, 'grant_revision' => (int) $a['grant_revision'], 'run' => (string) $run['id'], 'window' => [$run['window_start'], $run['window_end']],
                'missing' => array_keys(array_filter($item, static fn ($value) => $value === null)), 'visibility' => 'accessible_sample'], JSON_THROW_ON_ERROR);
            $this->repository->sql->execute('INSERT INTO content_items (organization_id,threads_account_id,remote_media_id,kind,root_id,root_remote_id,parent_remote_id,username,body,permalink,posted_at,content_hash,suppression_hash,provenance_json,expires_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,LEAST(TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)),COALESCE(?,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))))
                ON DUPLICATE KEY UPDATE source_version=source_version+IF(content_hash<>VALUES(content_hash),1,0),content_hash=VALUES(content_hash),
                root_remote_id=VALUES(root_remote_id),parent_remote_id=VALUES(parent_remote_id),username=VALUES(username),body=VALUES(body),permalink=VALUES(permalink),posted_at=VALUES(posted_at),
                provenance_json=VALUES(provenance_json),last_verified_at=UTC_TIMESTAMP(6),expires_at=LEAST(expires_at,VALUES(expires_at))',
                [$org, $id, $item['id'], $kind, $root['id'] ?? null, $item['root_post'], $item['replied_to'], $item['username'], $item['text'], $item['permalink'], $item['timestamp'], $hash,
                    $this->privacy->ledger->identifierHash($org, $a['scope_key'], $item['id']), $provenance, $this->repository->sourceDays, $root['expires_at'] ?? null, $this->repository->sourceDays]);
            $retained++;
        }
        $version = (int) $run['version'] + 1;
        $hashes = json_decode($run['cursor_hashes'], true, 16, JSON_THROW_ON_ERROR);
        $cursor = $data['cursor'];
        if ($cursor !== null) {
            $hashes[] = hash('sha256', $cursor);
        }
        $cap = (int) $run['pages'] + 1 >= 10 || (int) $run['records_seen'] + count($data['items']) >= 1000;
        $status = $cursor === null ? 'exhausted' : ($cap ? 'partial' : 'running');
        $envelope = $cursor === null || $cap ? null : ($this->vault)()->seal($cursor, new TokenContext($org, $id, 'cursor:' . $run['id'], $version));
        $this->repository->sql->execute('UPDATE threads_sync_checkpoints SET version=?,cursor_envelope=?,cursor_hashes=? WHERE organization_id=? AND threads_account_id=? AND run_id=? AND version=?', [$version, $envelope, json_encode($hashes, JSON_THROW_ON_ERROR), $org, $id, $run['id'], (int) $run['version']]);
        $this->repository->sql->execute('UPDATE threads_sync_runs SET status=?,pages=pages+1,records_seen=records_seen+?,records_retained=records_retained+?,unknown_time=unknown_time+?,safe_error_code=? WHERE organization_id=? AND threads_account_id=? AND id=?',
            [$status, count($data['items']), $retained, $unknown, $cap && $cursor !== null ? 'collection_cap' : null, $org, $id, $run['id']]);
        $this->repository->sql->execute('UPDATE jobs SET checkpoint_json=? WHERE organization_id=? AND id=?', [json_encode(['run' => (string) $run['id'], 'version' => $version], JSON_THROW_ON_ERROR), $org, $claim->id]);
        if ($status === 'running') {
            $this->queue->dispatch($org, $claim->type, ['account' => $id, 'run' => (string) $run['id'], 'version' => $version], 'threads:page:' . $run['id'] . ':' . $version);
        } elseif ($status === 'exhausted') {
            $this->repository->sql->execute('UPDATE threads_accounts SET last_sync_at=UTC_TIMESTAMP(6),safe_error_code=NULL WHERE organization_id=? AND id=?', [$org, $id]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Application;

use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Threads\Domain\OAuthCallback;
use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\TokenContext;
use Tai\Modules\Threads\Infrastructure\ThreadsRepository;
use Tai\Modules\Threads\Infrastructure\TokenVault;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Infrastructure\Config;

final readonly class AccountService
{
    public function __construct(private ThreadsRepository $repository, private QueueService $queue, private ThreadsClient $client,
        private RequestReservations $reservations, private Config $config, private \Closure $vault, private \Tai\Modules\Threads\Infrastructure\AccountRecords $records)
    {
    }

    public function assertFixtureEnabled(): void
    {
        if ($this->config->get('app.env') === 'production' || $this->config->get('threads.fixture_enabled') !== true) {
            throw new UserFacingException('Threads fixture integration is disabled. Live integration is unavailable.');
        }
    }

    public function begin(array $identity, string $sessionId): string
    {
        $this->assertFixtureEnabled();
        ($this->vault)(); // Fail before creating a flow if secret storage has not been provisioned.
        return $this->queue->transaction(function () use ($identity, $sessionId): string {
            $org = $this->repository->authorize($identity);
            $scope = $this->repository->sql->scope($org);
            if (!$scope['organization_active'] || $scope['blocked_at'] !== null
                || $this->records->hasOrganizationSuppression([$org])->fetchColumn()) {
                throw new UserFacingException('Threads scope is blocked.');
            }
            $a = $this->records->lockOrganizationAccount([$org])->fetch();
            if (!$a) {
                $this->records->insertAccount([$org, bin2hex(random_bytes(16))]);
                $a = $this->repository->account($org, (string) $this->records->lastInsertId()->fetchColumn());
            }
            $a = $this->repository->account($org, (string) $a['id']);
            if ($a['request_busy']) {
                throw new UserFacingException('A fixture request is still reserved. Retry after its finite reservation expires.');
            }
            if ($a['blocked_at'] !== null || in_array($a['status'], ['disconnected', 'revoked', 'deleting'], true)) {
                throw new UserFacingException('This fixture account has been permanently disconnected.');
            }
            $this->repository->cancelWork($org, (string) $a['id'], 'scope_changed');
            $this->records->beginConnection([$org, $a['id']]);
            $this->records->deleteCredentials([$org, $a['id']]);
            $this->records->deleteGrants([$org, $a['id']]);
            $this->records->deleteStates([$org, $a['id']]);
            $state = bin2hex(random_bytes(32));
            $this->records->insertState(
                [hash('sha256', $state, true), $org, $a['id'], (string) $identity['user_id'], (string) $identity['auth_session_id'], hash('sha256', $sessionId, true),
                    $this->client->redirectUri(), (int) $a['credential_generation'] + 1, (int) $a['privacy_epoch']]);
            return $state;
        });
    }

    public function callback(array $identity, string $sessionId, #[\SensitiveParameter] string $query, WorkerBudget $budget): void
    {
        $this->assertFixtureEnabled();
        $parameters = OAuthCallback::parse($query);
        $state = $this->queue->transaction(function () use ($identity, $sessionId, $parameters): array {
            $org = $this->repository->authorize($identity);
            $this->repository->sql->scope($org);
            $this->records->lockAccounts([$org])->fetch();
            $state = $this->records->lockState(
                [hash('sha256', $parameters['state'], true), $org])->fetch();
            if (!$state || !$state['valid'] || $state['consumed_at'] !== null || (string) $state['user_id'] !== (string) $identity['user_id']
                || (string) $state['auth_session_id'] !== (string) $identity['auth_session_id']
                || !hash_equals($state['session_hash'], hash('sha256', $sessionId, true)) || $state['redirect_uri'] !== $this->client->redirectUri()) {
                throw new UserFacingException('The fixture authorization state is invalid or expired. Restart the connection.');
            }
            $a = $this->repository->account($org, (string) $state['threads_account_id']);
            $this->assertState($state, $a);
            $this->records->consumeState([$state['state_hash']]);
            if (isset($parameters['code'])) {
                $state['request_key'] = $this->reservations->reserve($a, 'code');
            }
            return $state;
        });
        if (isset($parameters['error'])) {
            throw new UserFacingException('Fixture authorization was declined.');
        }
        try {
            $response = $this->client->exchangeCode($parameters['code'], $budget->requestTimeoutMs());
            $this->queue->transaction(function () use ($identity, $state, $response): void {
                $org = $this->repository->authorize($identity);
                $this->repository->sql->scope($org);
                $this->records->lockAccounts([$org])->fetch();
                $a = $this->repository->account($org, (string) $state['threads_account_id']);
                $this->assertState($state, $a);
                if ($a['remote_user_id'] !== null && $a['remote_user_id'] !== $response['identity']) {
                    throw new ProviderFailure('identity_mismatch');
                }
                $this->records->saveIdentity([$response['identity'], $org, $a['id']]);
                $envelope = ($this->vault)()->seal($response['token'], new TokenContext($org, (string) $a['id'], 'short', (int) $a['credential_generation']));
                $this->repository->storeCredential($a, 'short', $envelope, gmdate('Y-m-d H:i:s', time() + 3600));
                $this->reservations->finish($a, $state['request_key']);
                $this->queue->dispatch($org, 'threads.token', ['account' => (string) $a['id'], 'step' => 'long'], 'threads:activate:' . $a['id'] . ':' . $a['credential_generation'], 80);
            });
        } catch (\Throwable $error) {
            $this->queue->transaction(function () use ($state, $error): void {
                $org = (string) $state['organization_id'];
                $this->repository->sql->scope($org);
                $this->records->lockAccounts([$org])->fetch();
                $a = $this->repository->account($org, (string) $state['threads_account_id']);
                if ((int) $a['credential_generation'] === (int) $state['expected_generation'] && (int) $a['privacy_epoch'] === (int) $state['expected_epoch'] && $a['blocked_at'] === null) {
                    $code = $error instanceof ProviderFailure ? $error->safeCode : 'invalid_response';
                    $this->reservations->finish($a, $state['request_key'], $code);
                    $this->records->requireReauthorization([$code, $org, $a['id']]);
                }
            });
            throw new UserFacingException('Fixture authorization could not be completed. Restart the connection.');
        }
    }

    private function assertState(array $state, array $a): void
    {
        if ($a['blocked_at'] !== null || $a['status'] !== 'pending' || (int) $state['expected_generation'] !== (int) $a['credential_generation'] || (int) $state['expected_epoch'] !== (int) $a['privacy_epoch']) {
            throw new UserFacingException('The connection changed. Restart fixture authorization.');
        }
    }

    public function refresh(array $identity, string $account): string
    {
        $this->assertFixtureEnabled();
        return $this->queue->transaction(function () use ($identity, $account): string {
            $org = $this->repository->authorize($identity);
            $this->repository->sql->scope($org);
            $this->records->lockAccount([$org, $account])->fetch();
            $a = $this->repository->account($org, $account);
            $credential = $this->repository->credential($org, $account);
            if (!$this->repository->active($a) || $credential['kind'] !== 'long' || !$credential['valid'] || (int) $credential['age_seconds'] < 86400) {
                throw new UserFacingException('Fixture refresh requires a valid long token at least 24 hours old and an unexpired grant.');
            }
            return $this->queue->dispatch($org, 'threads.token', ['account' => $account, 'step' => 'refresh'], 'threads:refresh:' . $account . ':' . $a['credential_generation'], 80);
        });
    }

    public function rotate(array $identity, string $account): void
    {
        $this->assertFixtureEnabled();
        $this->queue->transaction(function () use ($identity, $account): void {
            $org = $this->repository->authorize($identity);
            $this->repository->sql->scope($org);
            $this->records->lockAccount([$org, $account])->fetch();
            $a = $this->repository->account($org, $account);
            $credential = $this->repository->credential($org, $account);
            if (!$this->repository->active($a)) {
                throw new UserFacingException('Fixture credential is unavailable.');
            }
            $generation = (int) $a['credential_generation'];
            $envelope = ($this->vault)()->rotate($credential['envelope'], new TokenContext($org, $account, $credential['kind'], $generation), new TokenContext($org, $account, $credential['kind'], $generation + 1));
            $this->repository->cancelWork($org, $account, 'scope_changed');
            $this->records->invalidateCredential([$org, $account]);
            $this->records->replaceEnvelope([$envelope, $org, $account]);
        });
    }
}

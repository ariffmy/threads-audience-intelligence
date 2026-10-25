<?php

declare(strict_types=1);

use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\BudgetExceeded;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Threads\Application\ThreadsClient;
use Tai\Modules\Threads\Domain\OAuthCallback;
use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\ProviderRequest;
use Tai\Modules\Threads\Domain\TokenContext;
use Tai\Modules\Threads\Infrastructure\DisabledTransport;
use Tai\Modules\Threads\Infrastructure\FixtureTransport;
use Tai\Modules\Threads\Infrastructure\TokenVault;
use Tai\Shared\Domain\UserFacingException;
use TaiTests\Suite;
use function TaiTests\check;
use function TaiTests\raises;

return static function (Suite $suite): void {
    $context = new TokenContext('1', '1', 'long', 1);
    $vault = new TokenVault('old', ['old' => random_bytes(32)]);
    $suite->test('Threads vault: random nonce, authenticated round trip and no plaintext envelope', static function () use ($context, $vault): void {
        $one = $vault->seal('fixture-long-token', $context);
        $two = $vault->seal('fixture-long-token', $context);
        check($one !== $two && !str_contains($one, 'fixture-long-token'));
        check($vault->open($one, $context) === 'fixture-long-token');
        check(!str_contains(print_r($vault, true), 'keys'));
    });
    $suite->test('Threads vault: ciphertext, nonce and key tampering fail closed', static function () use ($context, $vault): void {
        foreach (['cipher', 'nonce', 'key'] as $field) {
            $data = json_decode($vault->seal('fixture-long-token', $context), true);
            $data[$field] = $field === 'key' ? 'missing' : base64_encode(random_bytes($field === 'nonce' ? 24 : 40));
            raises(fn () => $vault->open(json_encode($data), $context), RuntimeException::class);
        }
        raises(fn () => $vault->open('{malformed', $context), RuntimeException::class);
    });
    $suite->test('Threads vault: tenant, account, kind and generation substitutions fail', static function () use ($context, $vault): void {
        $envelope = $vault->seal('fixture-long-token', $context);
        foreach ([new TokenContext('2', '1', 'long', 1), new TokenContext('1', '2', 'long', 1), new TokenContext('1', '1', 'short', 1), new TokenContext('1', '1', 'long', 2)] as $wrong) {
            raises(fn () => $vault->open($envelope, $wrong), RuntimeException::class);
        }
    });
    $suite->test('Threads vault: rotation retains old-key reads and fences new generation', static function () use ($context): void {
        $oldKey = random_bytes(32); $newKey = random_bytes(32);
        $old = new TokenVault('old', ['old' => $oldKey]); $new = new TokenVault('new', ['old' => $oldKey, 'new' => $newKey]);
        $original = $old->seal('fixture-long-token', $context);
        $next = new TokenContext('1', '1', 'long', 2);
        $rotated = $new->rotate($original, $context, $next);
        check($new->open($original, $context) === 'fixture-long-token' && $new->open($rotated, $next) === 'fixture-long-token');
        raises(fn () => $old->open($rotated, $next), RuntimeException::class);
        raises(fn () => $new->open($rotated, $context), RuntimeException::class);
    });
    $suite->test('Threads OAuth: fixed authorization host, minimal scopes and exact redirect', static function (): void {
        $client = new ThreadsClient(new DisabledTransport(), 'https://localhost/threads/oauth/callback');
        $url = $client->authorizationUrl(str_repeat('a', 64));
        check(str_starts_with($url, 'https://threads.com/oauth/authorize?'));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        check($query['redirect_uri'] === 'https://localhost/threads/oauth/callback' && $query['scope'] === 'threads_basic,threads_read_replies');
        check(!str_contains($url, 'secret') && !str_contains($url, 'pkce'));
        raises(fn () => new ThreadsClient(new DisabledTransport(), 'https://localhost/threads/oauth/callback?next=evil'), InvalidArgumentException::class);
    });
    $suite->test('Threads OAuth: missing, duplicated, array, real-code and tampered callback parameters', static function (): void {
        $state = str_repeat('b', 64);
        check(OAuthCallback::parse('state=' . $state . '&code=fixture-code')['code'] === 'fixture-code');
        foreach (['', 'code=fixture-code', 'state=' . $state, 'state=' . $state . '&state=' . $state . '&code=fixture-code',
            'state=' . $state . '&code[]=fixture-code', 'state=' . $state . '&code=real-code', 'state=' . $state . '&code=fixture-code&error=access_denied',
            'state=' . $state . '&code=fixture-code&redirect_uri=https://evil.example', 'state=' . $state . '&code=fixture-%0acode'] as $query) {
            raises(fn () => OAuthCallback::parse($query), UserFacingException::class);
        }
    });
    $suite->test('Threads transport: live requests unconditionally disabled and fake rejects real credentials', static function (): void {
        raises(fn () => (new DisabledTransport())->send(new ProviderRequest('profile', [], 1000)), ProviderFailure::class);
        raises(fn () => (new FixtureTransport())->send(new ProviderRequest('profile', ['access_token' => 'real-token'], 1000)), ProviderFailure::class);
        raises(fn () => new ProviderRequest('https://evil.example', [], 1000), InvalidArgumentException::class);
        raises(fn () => new ProviderRequest('replies', [], 1000, '../me'), ProviderFailure::class);
        $options = (new ProviderRequest('profile', [], 1000))->curlSafetyOptions();
        check($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2 && !$options[CURLOPT_FOLLOWLOCATION]);
    });
    $suite->test('Threads client: fake token lifecycle and separate grant expiry metadata', static function (): void {
        $fake = new FixtureTransport(); $client = new ThreadsClient($fake, 'https://localhost/threads/oauth/callback');
        check($client->exchangeCode('fixture-code', 1000)['identity'] === '900001');
        check($client->lifecycle('long', 'fixture-short-token', 1000)['expires_in'] === 5184000);
        check($client->lifecycle('refresh', 'fixture-long-token', 1000)['token'] === 'fixture-long-token');
        $debug = $client->lifecycle('debug', 'fixture-long-token', 1000);
        check($debug['permission_expiry'] > $debug['token_expiry'] && $fake->calls === 4);
    });
    foreach ([
        'malformed JSON' => ['status' => 200, 'body' => '{not-json'],
        'bad schema' => ['status' => 200, 'body' => '{"data":{}}'],
        'oversized body' => ['status' => 200, 'body' => str_repeat('x', 1048577)],
        'redirect' => ['status' => 302, 'body' => 'sensitive-provider-body'],
        'unsafe provider error' => ['status' => 200, 'body' => '{"error":{"message":"fixture-secret-leak"}}'],
        'throttle' => ['status' => 429, 'body' => 'fixture-secret-leak'],
        'unavailable' => ['status' => 503, 'body' => 'fixture-secret-leak'],
        'invalid grant' => ['status' => 401, 'body' => 'fixture-secret-leak'],
    ] as $label => $response) {
        $suite->test('Threads client rejects ' . $label . ' without raw provider output', static function () use ($response): void {
            $client = new ThreadsClient(new FixtureTransport(static fn () => $response), 'https://localhost/threads/oauth/callback');
            try { $client->page('posts', 'fixture-token', null, '2026-10-01 00:00:00', '2026-10-26 00:00:00', null, 1000); }
            catch (ProviderFailure $error) { check(!str_contains($error->getMessage(), 'leak')); return; }
            throw new RuntimeException('Unsafe response accepted.');
        });
    }
    $suite->test('Threads client: deadline expiry is a safe retryable failure', static function (): void {
        $client = new ThreadsClient(new FixtureTransport(static function (): array { usleep(120000); return ['status' => 200, 'body' => '{"id":"1"}']; }), 'https://localhost/threads/oauth/callback');
        raises(fn () => $client->lifecycle('profile', 'fixture-token', 100), ProviderFailure::class);
    });
    $suite->test('Threads client: missing optional fields, huge string IDs and ignored paging URLs', static function (): void {
        $fake = new FixtureTransport(static function (ProviderRequest $request): array {
            check($request->url === 'https://graph.threads.com/v1.0/99/conversation');
            check(!isset($request->parameters['since'], $request->parameters['until'], $request->parameters['limit']));
            return ['status' => 200, 'body' => '{"data":[{"id":9999999999999999999999}],"paging":{"next":"https://evil.example/token","cursors":{"after":"opaque"}}}'];
        });
        $data = (new ThreadsClient($fake, 'https://localhost/threads/oauth/callback'))->page('conversation', 'fixture-token', null, '2026-10-01 00:00:00', '2026-10-26 00:00:00', '99', 1000);
        check($data['items'][0]['id'] === '9999999999999999999999' && $data['items'][0]['username'] === null && $data['items'][0]['text'] === null);
        check($data['cursor'] === 'opaque' && $fake->calls === 1);
    });
    $suite->test('Threads worker: one request with four-second persistence reserve', static function (): void {
        $budget = new WorkerBudget(25, 20, 96); check($budget->requestTimeoutMs() <= 20000);
        raises(fn () => $budget->requestTimeoutMs(), BudgetExceeded::class);
        raises(fn () => (new WorkerBudget(4, 20, 96))->requestTimeoutMs(), BudgetExceeded::class);
        $short = new WorkerBudget(6, 20, 96); $initial = $short->requestTimeoutMs(); usleep(20000);
        check($short->remainingRequestTimeoutMs() < $initial); // Database/preflight overhead reduces the call allowance.
    });
    $suite->test('Threads client rejects excessive records and malformed cursor containers', static function (): void {
        foreach ([['data' => array_fill(0, 101, ['id' => '1'])], ['data' => [], 'paging' => ['next' => 'ignored', 'cursors' => 'invalid']]] as $data) {
            $client = new ThreadsClient(new FixtureTransport(static fn () => ['status' => 200, 'body' => json_encode($data)]), 'https://localhost/threads/oauth/callback');
            raises(fn () => $client->page('posts', 'fixture-token', null, '2026-10-01 00:00:00', '2026-10-26 00:00:00', null, 1000), ProviderFailure::class);
        }
    });
    $suite->test('Threads payloads reject URLs, credentials and arbitrary remote IDs', static function (): void {
        check(JobPayload::validate('threads.posts', ['account' => '1', 'run' => '2', 'version' => 1]) !== '');
        foreach ([['account' => '1', 'run' => '2', 'version' => 1, 'token' => 'fixture-token'], ['account' => 'https://evil.example', 'run' => '2', 'version' => 1], ['account' => '1', 'run' => '2', 'version' => 12]] as $payload) {
            raises(fn () => JobPayload::validate('threads.posts', $payload), InvalidArgumentException::class);
        }
    });
};

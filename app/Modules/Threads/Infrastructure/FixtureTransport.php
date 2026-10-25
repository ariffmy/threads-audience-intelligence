<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use Tai\Modules\Threads\Application\HttpTransport;
use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\ProviderRequest;

/** Deterministic synthetic provider; contains no network or filesystem reads. */
final class FixtureTransport implements HttpTransport
{
    public int $calls = 0;
    private readonly int $now;

    public function __construct(private readonly ?\Closure $respond = null, ?int $now = null)
    {
        $this->now = $now ?? time();
    }

    public function send(ProviderRequest $request): array
    {
        $this->calls++;
        foreach (['access_token', 'input_token', 'client_secret', 'code'] as $key) {
            if (isset($request->parameters[$key]) && !str_starts_with($request->parameters[$key], 'fixture-')) {
                throw new ProviderFailure('live_disabled');
            }
        }
        if ($this->respond !== null) {
            return ($this->respond)($request);
        }
        $data = match ($request->operation) {
            'code' => ['access_token' => 'fixture-short-token', 'token_type' => 'bearer', 'user_id' => '900001'],
            'long', 'refresh' => ['access_token' => 'fixture-long-token', 'token_type' => 'bearer', 'expires_in' => 5184000],
            'debug' => ['data' => ['app_id' => 'fixture-app', 'user_id' => '900001', 'is_valid' => true,
                'scopes' => ['threads_basic', 'threads_read_replies'], 'expires_at' => $this->now + 5184000, 'data_access_expires_at' => $this->now + 7776000]],
            'profile' => ['id' => '900001', 'username' => 'fixture_owner'],
            'posts' => ['data' => [['id' => '910001', 'owner' => ['id' => '900001'], 'text' => 'Synthetic owned discussion',
                'timestamp' => '2026-10-25T00:00:00+0000', 'username' => 'fixture_owner']]],
            'replies', 'conversation' => ['data' => [
                ['id' => '920001', 'text' => 'Synthetic question: when is the next update?', 'timestamp' => '2026-10-25T01:00:00+0000',
                    'username' => 'fixture_reader', 'root_post' => ['id' => '910001'], 'replied_to' => ['id' => '910001']],
                ['id' => '920002', 'text' => null, 'timestamp' => '2026-10-25T02:00:00+0000', 'root_post' => ['id' => '910001']],
            ]],
        };
        return ['status' => 200, 'body' => json_encode($data, JSON_THROW_ON_ERROR)];
    }
}

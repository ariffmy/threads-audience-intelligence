<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Application;

use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\ProviderRequest;

final readonly class ThreadsClient
{
    public const SCOPES = ['threads_basic', 'threads_read_replies'];
    public const POST_FIELDS = 'id,text,timestamp,username,owner,permalink';
    public const REPLY_FIELDS = 'id,text,timestamp,username,permalink,root_post,replied_to';

    public function __construct(private HttpTransport $transport, private string $redirectUri)
    {
        $url = parse_url($redirectUri);
        if ($url === false || !isset($url['host'], $url['scheme']) || isset($url['query']) || isset($url['fragment']) || isset($url['user'])
            || ($url['path'] ?? '') !== '/threads/oauth/callback'
            || ($url['scheme'] !== 'https' && !($url['scheme'] === 'http' && in_array($url['host'], ['localhost', '127.0.0.1'], true)))) {
            throw new \InvalidArgumentException('Invalid registered callback URI.');
        }
    }

    public function authorizationUrl(string $state): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) {
            throw new \InvalidArgumentException('Invalid OAuth state.');
        }
        return 'https://threads.com/oauth/authorize?' . http_build_query(['client_id' => 'fixture-app',
            'redirect_uri' => $this->redirectUri, 'scope' => implode(',', self::SCOPES), 'response_type' => 'code', 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    public function exchangeCode(#[\SensitiveParameter] string $code, int $timeoutMs): array
    {
        if (!preg_match('/^fixture-[a-zA-Z0-9_-]{1,200}$/D', $code)) {
            throw new ProviderFailure('live_disabled');
        }
        $data = $this->request('code', ['client_id' => 'fixture-app', 'client_secret' => 'fixture-secret', 'code' => $code,
            'grant_type' => 'authorization_code', 'redirect_uri' => $this->redirectUri], $timeoutMs);
        return ['token' => $this->token($data), 'identity' => ProviderRequest::remoteId($data['user_id'] ?? null)];
    }

    public function lifecycle(string $step, #[\SensitiveParameter] string $token, int $timeoutMs): array
    {
        $parameters = match ($step) {
            'long' => ['grant_type' => 'th_exchange_token', 'access_token' => $token, 'client_secret' => 'fixture-secret'],
            'refresh' => ['grant_type' => 'th_refresh_token', 'access_token' => $token],
            'debug' => ['input_token' => $token, 'access_token' => $token],
            'profile' => ['access_token' => $token, 'fields' => 'id,username'],
            default => throw new \InvalidArgumentException('Invalid lifecycle step.'),
        };
        $data = $this->request($step, $parameters, $timeoutMs);
        if ($step === 'long' || $step === 'refresh') {
            if (!is_int($data['expires_in'] ?? null) || $data['expires_in'] < 1 || $data['expires_in'] > 7776000) {
                throw new ProviderFailure('invalid_response');
            }
            return ['token' => $this->token($data), 'expires_in' => $data['expires_in']];
        }
        if ($step === 'profile') {
            return ['identity' => ProviderRequest::remoteId($data['id'] ?? null), 'username' => self::optionalText($data, 'username', 255)];
        }
        $debug = $data['data'] ?? null;
        if (!is_array($debug) || ($debug['is_valid'] ?? null) !== true || ($debug['app_id'] ?? null) !== 'fixture-app') {
            throw new ProviderFailure('grant_invalid');
        }
        if (!is_array($debug['scopes'] ?? null) || !array_is_list($debug['scopes']) || count($debug['scopes']) > 32
            || array_filter($debug['scopes'], static fn ($scope) => !is_string($scope)) !== []
            || array_diff(self::SCOPES, $debug['scopes']) !== []) {
            throw new ProviderFailure('grant_invalid');
        }
        foreach (['expires_at', 'data_access_expires_at'] as $field) {
            if (!is_int($debug[$field] ?? null) || $debug[$field] <= time() || $debug[$field] > time() + 31536000) {
                throw new ProviderFailure('grant_invalid');
            }
        }
        return ['identity' => ProviderRequest::remoteId($debug['user_id'] ?? null), 'scopes' => self::SCOPES,
            'token_expiry' => gmdate('Y-m-d H:i:s', $debug['expires_at']), 'permission_expiry' => gmdate('Y-m-d H:i:s', $debug['data_access_expires_at'])];
    }

    public function page(string $mode, #[\SensitiveParameter] string $token, ?string $cursor, string $start, string $end, ?string $root, int $timeoutMs): array
    {
        if (!in_array($mode, ['posts', 'replies', 'conversation'], true)) {
            throw new \InvalidArgumentException('Unsupported collection mode.');
        }
        $parameters = ['access_token' => $token, 'fields' => $mode === 'posts' ? self::POST_FIELDS : self::REPLY_FIELDS];
        if ($cursor !== null) {
            self::cursor($cursor);
            $parameters['after'] = $cursor;
        }
        if ($mode === 'posts') {
            $parameters += ['since' => (string) strtotime($start . ' UTC'), 'until' => (string) strtotime($end . ' UTC'), 'limit' => '25'];
        }
        $data = $this->request($mode, $parameters, $timeoutMs, $root);
        if (!is_array($data['data'] ?? null) || !array_is_list($data['data']) || count($data['data']) > 100) {
            throw new ProviderFailure(isset($data['data']) && is_array($data['data']) && count($data['data']) > 100 ? 'collection_cap' : 'invalid_response');
        }
        $items = [];
        foreach ($data['data'] as $row) {
            if (!is_array($row)) {
                throw new ProviderFailure('invalid_response');
            }
            $timestamp = self::optionalText($row, 'timestamp', 64);
            if ($timestamp !== null) {
                if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:?\d\d)$/D', $timestamp)) {
                    throw new ProviderFailure('invalid_response');
                }
                try {
                    $date = new \DateTimeImmutable($timestamp);
                    if (\DateTimeImmutable::getLastErrors() !== false) {
                        throw new \RuntimeException();
                    }
                    $timestamp = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    throw new ProviderFailure('invalid_response');
                }
            }
            $permalink = self::optionalText($row, 'permalink', 2048);
            if ($permalink !== null) {
                $link = parse_url($permalink);
                if ($link === false || ($link['scheme'] ?? '') !== 'https' || !in_array($link['host'] ?? '', ['www.threads.com', 'threads.com', 'www.threads.net', 'threads.net'], true)
                    || isset($link['user']) || isset($link['port']) || preg_match('/[\x00-\x20]/', $permalink)) {
                    throw new ProviderFailure('invalid_response');
                }
            }
            $item = ['id' => ProviderRequest::remoteId($row['id'] ?? null), 'text' => self::optionalText($row, 'text', 16384),
                'timestamp' => $timestamp, 'username' => self::optionalText($row, 'username', 255), 'permalink' => $permalink];
            foreach (['owner', 'root_post', 'replied_to'] as $relation) {
                if (isset($row[$relation])) {
                    if (!is_array($row[$relation])) {
                        throw new ProviderFailure('invalid_response');
                    }
                    $item[$relation] = ProviderRequest::remoteId($row[$relation]['id'] ?? null);
                } else {
                    $item[$relation] = null;
                }
            }
            $items[] = $item;
        }
        $paging = $data['paging'] ?? [];
        if (!is_array($paging)) {
            throw new ProviderFailure('invalid_response');
        }
        $after = null;
        if (array_key_exists('next', $paging)) {
            // next is only an indication of more pages. Its URL is never fetched or retained.
            if (!is_string($paging['next']) || strlen($paging['next']) > 8192 || $paging['next'] === '') {
                throw new ProviderFailure('invalid_response');
            }
            if (!is_array($paging['cursors'] ?? null)) {
                throw new ProviderFailure('invalid_response');
            }
            $after = $paging['cursors']['after'] ?? null;
            self::cursor($after);
        }
        return ['items' => $items, 'cursor' => $after];
    }

    private function request(string $operation, #[\SensitiveParameter] array $parameters, int $timeoutMs, ?string $root = null): array
    {
        $request = new ProviderRequest($operation, $parameters, $timeoutMs, $root);
        $started = hrtime(true);
        $response = $this->transport->send($request);
        if ((hrtime(true) - $started) / 1000000 > $timeoutMs) {
            throw new ProviderFailure('provider_timeout', true);
        }
        if (!is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)) {
            throw new ProviderFailure('invalid_response');
        }
        if (strlen($response['body']) > $request->maxResponseBytes) {
            throw new ProviderFailure('response_oversize');
        }
        if ($response['status'] === 429) {
            throw new ProviderFailure('provider_throttled', true);
        }
        if ($response['status'] >= 500) {
            throw new ProviderFailure('provider_unavailable', true);
        }
        if (in_array($response['status'], [401, 403], true)) {
            throw new ProviderFailure('grant_invalid');
        }
        if ($response['status'] !== 200) {
            throw new ProviderFailure('invalid_response');
        }
        try {
            // Bound structural allocation as well as bytes before decoding hostile JSON.
            $quoted = false; $escaped = false; $structures = 0;
            for ($i = 0, $bytes = strlen($response['body']); $i < $bytes; $i++) {
                $character = $response['body'][$i];
                if ($quoted) {
                    if ($escaped) { $escaped = false; }
                    elseif ($character === '\\') { $escaped = true; }
                    elseif ($character === '"') { $quoted = false; }
                } elseif ($character === '"') {
                    $quoted = true;
                } elseif (str_contains('{}[],:', $character) && ++$structures > 10000) {
                    throw new \RuntimeException();
                }
            }
            $typed = json_decode($response['body'], false, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$typed instanceof \stdClass || (in_array($operation, ['posts', 'replies', 'conversation'], true) && !is_array($typed->data ?? null))) {
                throw new \RuntimeException();
            }
            unset($typed);
            $data = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            throw new ProviderFailure('invalid_response');
        }
        if (!is_array($data) || array_is_list($data) || isset($data['error'])) {
            throw new ProviderFailure('invalid_response');
        }
        return $data;
    }

    private function token(#[\SensitiveParameter] array $data): string
    {
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || strlen($token) > 8192 || !preg_match('/^fixture-[a-zA-Z0-9_-]+$/D', $token)
            || !is_string($data['token_type'] ?? null) || strtolower($data['token_type']) !== 'bearer') {
            throw new ProviderFailure('invalid_response');
        }
        return $token;
    }

    private static function cursor(mixed $cursor): void
    {
        if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 2048 || preg_match('/[\x00-\x20]/', $cursor)) {
            throw new ProviderFailure('invalid_response');
        }
    }

    private static function optionalText(array $row, string $key, int $limit): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && (!is_string($value) || strlen($value) > $limit || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
            throw new ProviderFailure('invalid_response');
        }
        return $value;
    }
}

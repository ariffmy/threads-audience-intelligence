<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Domain;

/** A fixed contract, never a URL supplied by the caller or a paging.next value. */
final readonly class ProviderRequest
{
    public string $url;
    public string $method;
    public int $connectTimeoutMs;
    public int $maxResponseBytes;

    public function __construct(
        public string $operation,
        #[\SensitiveParameter] public array $parameters,
        public int $timeoutMs,
        ?string $rootMediaId = null,
    ) {
        if ($timeoutMs < 100 || $timeoutMs > 20000) {
            throw new \InvalidArgumentException('Invalid provider deadline.');
        }
        if (in_array($operation, ['replies', 'conversation'], true)) {
            self::remoteId($rootMediaId);
        } elseif ($rootMediaId !== null) {
            throw new \InvalidArgumentException('Unexpected media reference.');
        }
        $path = match ($operation) {
            'code' => '/oauth/access_token', 'long' => '/access_token', 'refresh' => '/refresh_access_token',
            'debug' => '/debug_token', 'profile' => '/v1.0/me', 'posts' => '/v1.0/me/threads',
            'replies', 'conversation' => '/v1.0/' . $rootMediaId . '/' . $operation,
            default => throw new \InvalidArgumentException('Unsupported provider operation.'),
        };
        $this->url = 'https://graph.threads.com' . $path;
        $this->method = $operation === 'code' ? 'POST' : 'GET';
        $this->connectTimeoutMs = min(3000, $timeoutMs);
        $this->maxResponseBytes = 1048576;
    }

    public static function remoteId(mixed $id): string
    {
        if (is_int($id) && $id > 0) {
            $id = (string) $id;
        }
        if (!is_string($id) || preg_match('/^[0-9]{1,191}$/D', $id) !== 1) {
            throw new ProviderFailure('invalid_response');
        }
        return $id;
    }

    /** Security settings for a future reviewed cURL adapter. No executor ships in Phase 3B. */
    public function curlSafetyOptions(): array
    {
        return [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Encoding: identity'], CURLOPT_VERBOSE => false];
    }

    public function __debugInfo(): array
    {
        return ['operation' => $this->operation, 'timeout_ms' => $this->timeoutMs];
    }
}

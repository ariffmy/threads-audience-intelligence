<?php

declare(strict_types=1);

namespace Tai\Shared\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $input,
        public readonly string $ip,
        public readonly string $host,
        public readonly bool $secure,
        public readonly ?string $origin = null,
        public readonly bool $expectsJson = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            throw new HttpException(413, 'The request is too large.');
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path) || strlen($path) > 512 || preg_match('/[\x00-\x20\\\\%]/', $path)) {
            throw new HttpException(400, 'Invalid request path.');
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
            throw new HttpException(415, 'Use a standard form submission.');
        }
        return new self(
            $method, $path, $_POST,
            filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'unknown',
            strtolower($_SERVER['HTTP_HOST'] ?? ''),
            ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1',
            $_SERVER['HTTP_ORIGIN'] ?? null,
            str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_ends_with($path, '.json'),
        );
    }

    public function string(string $key, int $maxBytes = 255): string
    {
        $value = $this->input[$key] ?? '';
        if (!is_string($value) || strlen($value) > $maxBytes || str_contains($value, "\0")) {
            throw new HttpException(422, 'A submitted field is invalid or too long.');
        }
        return $value;
    }
}

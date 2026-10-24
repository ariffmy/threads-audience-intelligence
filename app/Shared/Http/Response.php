<?php

declare(strict_types=1);

namespace Tai\Shared\Http;

final class Response
{
    public function __construct(public readonly string $body = '', public readonly int $status = 200, public array $headers = [])
    {
    }

    public static function redirect(string $path): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\r\n]/', $path)) {
            throw new \LogicException('Only local redirects are allowed.');
        }
        return new self('<!doctype html><html lang="en"><meta charset="utf-8"><title>Continue</title><body><p>Continue to <a href="'
            . View::escape($path) . '">the requested page</a>.</p></body></html>', 303, ['Location' => $path]);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        $this->headers['Content-Length'] = (string) strlen($this->body);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!$head) {
            echo $this->body;
        }
    }
}

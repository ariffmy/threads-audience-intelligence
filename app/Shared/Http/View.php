<?php

declare(strict_types=1);

namespace Tai\Shared\Http;

final class View
{
    public function __construct(private readonly string $directory)
    {
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function render(string $template, array $data = [], int $status = 200): Response
    {
        if (!preg_match('/^[a-z-]+$/D', $template)) {
            throw new \LogicException('Invalid template.');
        }
        $e = self::escape(...);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $this->directory . '/' . $template . '.php';
            $content = (string) ob_get_clean();
            ob_start();
            require $this->directory . '/layout.php';
            return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}

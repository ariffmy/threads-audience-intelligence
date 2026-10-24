<?php

declare(strict_types=1);

namespace Tai\Shared\Http;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, string $handler, bool $protected = true): void
    {
        $this->routes[$path][$method] = ['handler' => $handler, 'protected' => $protected];
    }

    public function match(Request $request): array
    {
        if (!isset($this->routes[$request->path])) {
            throw new HttpException(404, 'This page could not be found.');
        }
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        return $this->routes[$request->path][$method]
            ?? throw new HttpException(405, 'This request method is not allowed.');
    }

    public function allowedMethods(string $path): string
    {
        $methods = array_keys($this->routes[$path] ?? []);
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }
        return implode(', ', $methods);
    }
}

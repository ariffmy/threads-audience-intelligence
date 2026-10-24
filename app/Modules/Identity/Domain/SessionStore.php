<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Domain;

interface SessionStore
{
    public function id(): string;
    public function get(string $key): mixed;
    public function put(string $key, mixed $value): void;
    public function remove(string $key): void;
    public function regenerate(): void;
    public function destroy(): void;
    public function close(): void;
}

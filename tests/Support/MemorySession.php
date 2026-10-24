<?php

declare(strict_types=1);

namespace TaiTests;

use Tai\Modules\Identity\Domain\SessionStore;

final class MemorySession implements SessionStore
{
    private array $values = [];
    private string $identifier;
    public bool $destroyed = false;

    public function __construct()
    {
        $this->identifier = bin2hex(random_bytes(24));
    }

    public function id(): string { return $this->identifier; }
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function put(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void { $this->identifier = bin2hex(random_bytes(24)); }
    public function destroy(): void { $this->values = []; $this->destroyed = true; $this->regenerate(); }
    public function close(): void { }
}

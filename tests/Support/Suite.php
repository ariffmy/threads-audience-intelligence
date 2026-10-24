<?php

declare(strict_types=1);

namespace TaiTests;

final class Suite
{
    public int $passed = 0;
    public int $failed = 0;
    public int $skipped = 0;

    public function test(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            echo 'PASS ' . $name . "\n";
        } catch (\Throwable $exception) {
            $this->failed++;
            echo 'FAIL ' . $name . ' · ' . $exception::class . ': ' . $exception->getMessage() . "\n";
        }
    }

    public function skip(string $name): void
    {
        $this->skipped++;
        echo 'SKIP ' . $name . "\n";
    }
}

function check(bool $condition, string $message = 'Assertion failed.'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function raises(callable $operation, string $expected, ?int $status = null): void
{
    try {
        $operation();
    } catch (\Throwable $exception) {
        check($exception instanceof $expected, 'Unexpected exception type: ' . $exception::class);
        if ($status !== null) {
            check(($exception->status ?? null) === $status, 'Unexpected HTTP status.');
        }
        return;
    }
    throw new \RuntimeException('Expected exception was not raised.');
}

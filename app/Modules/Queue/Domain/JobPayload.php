<?php

declare(strict_types=1);

namespace Tai\Modules\Queue\Domain;

final class JobPayload
{
    public static function validate(string $type, array $payload): string
    {
        $keys = array_keys($payload);
        sort($keys);
        $valid = match ($type) {
            'demo.page' => $keys === ['page', 'pages', 'run']
                && is_int($payload['page']) && is_int($payload['pages'])
                && $payload['page'] >= 1 && $payload['page'] <= $payload['pages'] && $payload['pages'] <= 20
                && is_string($payload['run']) && preg_match('/^[a-f0-9]{32}$/D', $payload['run']) === 1,
            'demo.failure' => $keys === ['mode'] && in_array($payload['mode'], ['transient', 'permanent'], true),
            'retention.sweep' => $payload === [],
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Unsupported job type or payload.');
        }
        ksort($payload);
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public static function identifier(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid internal identifier.');
        }
    }
}

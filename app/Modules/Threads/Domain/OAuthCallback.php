<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Domain;

use Tai\Shared\Domain\UserFacingException;

final class OAuthCallback
{
    /** PHP's normal query parsing loses duplicate parameter evidence. Parse this one route strictly. */
    public static function parse(string $query): array
    {
        if ($query === '' || strlen($query) > 4096) {
            throw new UserFacingException('The fixture authorization callback is invalid. Restart the connection.');
        }
        $result = [];
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = rawurldecode($parts[0]); $value = rawurldecode($parts[1] ?? '');
            if (!in_array($key, ['state', 'code', 'error'], true) || isset($result[$key]) || preg_match('/[\x00-\x20]/', $value)) {
                throw new UserFacingException('The fixture authorization callback is invalid. Restart the connection.');
            }
            $result[$key] = $value;
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $result['state'] ?? '') || (isset($result['code']) === isset($result['error']))
            || (isset($result['code']) && !preg_match('/^fixture-[a-zA-Z0-9_-]{1,200}$/D', $result['code']))
            || (isset($result['error']) && $result['error'] !== 'access_denied')) {
            throw new UserFacingException('The fixture authorization callback is invalid. Restart the connection.');
        }
        return $result;
    }
}

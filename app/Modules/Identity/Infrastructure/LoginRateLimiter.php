<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Infrastructure;

use PDO;
use Tai\Shared\Infrastructure\Config;

final class LoginRateLimiter
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function consume(string $email, string $ip, ?int $now = null): int
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->consumeOnce($email, $ip, $now);
            } catch (\PDOException $exception) {
                if (!in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true) || $attempt === 2) {
                    throw $exception;
                }
                usleep(random_int(10000, 40000));
            }
        }
        throw new \LogicException('Unreachable rate limit state.');
    }

    private function consumeOnce(string $email, string $ip, ?int $now): int
    {
        $now ??= time();
        $window = $this->config->get('security.window_seconds');
        $start = intdiv($now, $window) * $window;
        $buckets = [
            ['email', $email, $this->config->get('security.email_attempts')],
            ['ip', $ip, $this->config->get('security.ip_attempts')],
        ];
        $blocked = false;
        $this->pdo->beginTransaction();
        try {
            foreach ($buckets as [$kind, $subject, $limit]) {
                $hash = hash_hmac('sha256', $kind . ':' . $subject, hex2bin($this->config->get('app.key')), true);
                $insert = $this->pdo->prepare('INSERT INTO login_throttles (bucket_hash,bucket_kind,window_started_at,attempt_count,expires_at) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE attempt_count=LEAST(attempt_count+1,?)');
                $insert->execute([$hash, $kind, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $start + $window + 86400), $limit + 1]);
                $query = $this->pdo->prepare('SELECT attempt_count FROM login_throttles WHERE bucket_hash=? AND bucket_kind=? AND window_started_at=? FOR UPDATE');
                $query->execute([$hash, $kind, gmdate('Y-m-d H:i:s', $start)]);
                $blocked = ((int) $query->fetchColumn() > $limit) || $blocked;
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $blocked ? $start + $window - $now : 0;
    }
}

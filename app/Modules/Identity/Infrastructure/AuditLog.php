<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Infrastructure;

use PDO;

final class AuditLog
{
    public function __construct(private readonly PDO $pdo, private readonly int $retentionDays)
    {
    }

    public function record(string $organizationId, ?string $actorId, string $event, array $metadata = []): void
    {
        $allowed = ['admin.created', 'auth.login', 'auth.logout', 'profile.updated', 'password.changed', 'settings.updated'];
        if (!in_array($event, $allowed, true) || array_diff(array_keys($metadata), ['fields', 'timezone']) !== []) {
            throw new \LogicException('Invalid audit event.');
        }
        $statement = $this->pdo->prepare('INSERT INTO audit_events (organization_id,actor_user_id,event_type,safe_metadata_json,correlation_id,expires_at) VALUES (?,?,?,?,?,?)');
        $statement->execute([
            $organizationId, $actorId, $event,
            json_encode($metadata, JSON_THROW_ON_ERROR), bin2hex(random_bytes(16)),
            gmdate('Y-m-d H:i:s', time() + $this->retentionDays * 86400),
        ]);
    }
}

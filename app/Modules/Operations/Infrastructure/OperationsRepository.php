<?php

declare(strict_types=1);

namespace Tai\Modules\Operations\Infrastructure;

use PDO;

final class OperationsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recentEvents(string $organizationId): array
    {
        $query = $this->pdo->prepare('SELECT event_type,occurred_at FROM audit_events WHERE organization_id=? AND expires_at>UTC_TIMESTAMP(6) ORDER BY occurred_at DESC,id DESC LIMIT 5');
        $query->execute([$organizationId]);
        return $query->fetchAll();
    }
}

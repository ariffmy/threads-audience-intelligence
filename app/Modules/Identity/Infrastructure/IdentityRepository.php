<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Infrastructure;

use PDO;

final class IdentityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function acquireBootstrapLock(): string
    {
        $name = 'tai:bootstrap:' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$name]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new \Tai\Shared\Domain\UserFacingException('Another administrator bootstrap is running.');
        }
        return $name;
    }

    public function releaseBootstrapLock(string $name): void
    {
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$name]);
    }

    public function hasInstallation(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0
            || (int) $this->pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn() !== 0;
    }

    public function createPersonalIdentity(string $email, string $name, string $hash): array
    {
        $this->pdo->exec("INSERT INTO organizations (name,slug) VALUES ('Personal workspace','personal')");
        $organizationId = $this->pdo->lastInsertId();
        $user = $this->pdo->prepare('INSERT INTO users (email,display_name,password_hash,is_installation_admin) VALUES (?,?,?,1)');
        $user->execute([$email, $name, $hash]);
        $userId = $this->pdo->lastInsertId();
        $membership = $this->pdo->prepare("INSERT INTO memberships (organization_id,user_id,role) VALUES (?,?,'owner')");
        $membership->execute([$organizationId, $userId]);
        return [$organizationId, $userId];
    }

    public function loginUser(string $email): ?array
    {
        $query = $this->pdo->prepare('SELECT u.*,u.id AS user_id,u.status AS user_status,m.organization_id,m.role,m.status AS membership_status,o.status AS organization_status FROM users u JOIN memberships m ON m.user_id=u.id JOIN organizations o ON o.id=m.organization_id WHERE u.email=? LIMIT 2');
        $query->execute([$email]);
        $matches = $query->fetchAll();
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function lockLoginUser(string $organizationId, string $userId): ?array
    {
        $query = $this->pdo->prepare('SELECT u.password_hash,u.session_version,u.is_installation_admin,u.status AS user_status,u.id AS user_id,m.organization_id,m.role,m.status AS membership_status,o.status AS organization_status FROM organizations o JOIN memberships m ON m.organization_id=o.id JOIN users u ON u.id=m.user_id WHERE o.id=? AND u.id=? FOR UPDATE');
        $query->execute([$organizationId, $userId]);
        return $query->fetch() ?: null;
    }

    public function createSession(array $user, string $sessionHash, string $expiresAt, string $passwordHash): void
    {
        $create = $this->pdo->prepare('INSERT INTO auth_sessions (user_id,organization_id,session_id_hash,session_version,created_at,last_seen_at,absolute_expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?)');
        $create->execute([$user['user_id'], $user['organization_id'], $sessionHash, $user['session_version'], $expiresAt]);
        $update = $this->pdo->prepare('UPDATE users SET password_hash=?,last_login_at=UTC_TIMESTAMP(6) WHERE id=?');
        $update->execute([$passwordHash, $user['user_id']]);
    }

    public function identity(string $sessionHash, int $idleSeconds): ?array
    {
        $query = $this->pdo->prepare('SELECT u.id AS user_id,u.email,u.display_name,u.is_installation_admin,u.status AS user_status,u.session_version,m.organization_id,m.role,m.status AS membership_status,o.status AS organization_status,o.name AS organization_name,o.display_timezone,s.id AS auth_session_id FROM auth_sessions s JOIN users u ON u.id=s.user_id JOIN memberships m ON m.organization_id=s.organization_id AND m.user_id=s.user_id JOIN organizations o ON o.id=m.organization_id WHERE s.session_id_hash=? AND s.revoked_at IS NULL AND s.session_version=u.session_version AND s.absolute_expires_at>UTC_TIMESTAMP(6) AND TIMESTAMPDIFF(SECOND,s.last_seen_at,UTC_TIMESTAMP(6))<?');
        $query->execute([$sessionHash, $idleSeconds]);
        $identity = $query->fetch();
        if (!$identity) {
            return null;
        }
        $touch = $this->pdo->prepare('UPDATE auth_sessions SET last_seen_at=UTC_TIMESTAMP(6) WHERE id=? AND revoked_at IS NULL AND session_version=?');
        $touch->execute([$identity['auth_session_id'], $identity['session_version']]);
        return $identity;
    }

    public function lockAuthorizedIdentity(string $organizationId, string $userId, string $sessionHash, int $idleSeconds): ?array
    {
        $query = $this->pdo->prepare('SELECT u.is_installation_admin,u.status AS user_status,m.role,m.status AS membership_status,o.status AS organization_status,m.organization_id,u.id AS user_id FROM organizations o JOIN memberships m ON m.organization_id=o.id JOIN users u ON u.id=m.user_id JOIN auth_sessions s ON s.user_id=u.id AND s.organization_id=o.id WHERE o.id=? AND u.id=? AND s.session_id_hash=? AND s.revoked_at IS NULL AND s.session_version=u.session_version AND s.absolute_expires_at>UTC_TIMESTAMP(6) AND TIMESTAMPDIFF(SECOND,s.last_seen_at,UTC_TIMESTAMP(6))<? FOR UPDATE');
        $query->execute([$organizationId, $userId, $sessionHash, $idleSeconds]);
        return $query->fetch() ?: null;
    }

    public function revokeSession(string $sessionHash, string $userId, string $organizationId): void
    {
        $query = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE session_id_hash=? AND user_id=? AND organization_id=? AND revoked_at IS NULL');
        $query->execute([$sessionHash, $userId, $organizationId]);
    }

    public function updateDisplayName(string $userId, string $name): void
    {
        $query = $this->pdo->prepare('UPDATE users SET display_name=? WHERE id=?');
        $query->execute([$name, $userId]);
    }

    public function updateTimezone(string $organizationId, string $timezone): void
    {
        $query = $this->pdo->prepare('UPDATE organizations SET display_timezone=? WHERE id=?');
        $query->execute([$timezone, $organizationId]);
    }

    public function passwordHash(string $userId): string
    {
        $query = $this->pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $query->execute([$userId]);
        return (string) $query->fetchColumn();
    }

    public function replacePassword(string $userId, string $previousHash, string $newHash): bool
    {
        $query = $this->pdo->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=? AND password_hash=?');
        $query->execute([$newHash, $userId, $previousHash]);
        if ($query->rowCount() !== 1) {
            return false;
        }
        $revoke = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE user_id=? AND revoked_at IS NULL');
        $revoke->execute([$userId]);
        return true;
    }
}

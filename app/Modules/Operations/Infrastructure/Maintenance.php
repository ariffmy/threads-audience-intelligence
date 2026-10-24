<?php

declare(strict_types=1);

namespace Tai\Modules\Operations\Infrastructure;

use PDO;
use Tai\Shared\Infrastructure\Config;

final class Maintenance
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function prune(string $organizationId): array
    {
        \Tai\Modules\Queue\Domain\JobPayload::identifier($organizationId);
        $counts = [];
        foreach (['login_throttles'] as $table) {
            // Identifiers are a fixed internal allowlist, never request input.
            $counts[$table] = $this->pdo->exec('DELETE FROM ' . $table . ' WHERE expires_at<UTC_TIMESTAMP(6) ORDER BY id LIMIT 500');
        }
        $audit = $this->pdo->prepare('DELETE FROM audit_events WHERE organization_id=? AND (expires_at<UTC_TIMESTAMP(6) OR occurred_at<TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY id LIMIT 500');
        $audit->execute([$organizationId, -min($this->config->get('security.audit_days'), $this->config->get('privacy.audit_days'))]);
        $counts['audit_events'] = $audit->rowCount();
        $query = $this->pdo->prepare('DELETE FROM auth_sessions WHERE absolute_expires_at<UTC_TIMESTAMP(6) OR revoked_at<? ORDER BY id LIMIT 500');
        $query->execute([gmdate('Y-m-d H:i:s', time() - $this->config->get('session.absolute_seconds'))]);
        $counts['auth_sessions'] = $query->rowCount();
        foreach (['logs' => min($this->config->get('security.log_days'), $this->config->get('privacy.log_days')) * 86400, 'sessions' => $this->config->get('session.absolute_seconds')] as $directory => $seconds) {
            $removed = 0;
            $visited = 0;
            $iterator = new \FilesystemIterator($this->config->storage($directory), \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $file) {
                if (++$visited > 500) {
                    break;
                }
                $pattern = $directory === 'logs' ? '/^application-[0-9]{4}-[0-9]{2}-[0-9]{2}\.log$/D' : '/^sess_[A-Za-z0-9,-]+$/D';
                if (!$file->isFile() || $file->isLink() || !preg_match($pattern, $file->getFilename()) || $file->getMTime() >= time() - $seconds) {
                    continue;
                }
                $handle = fopen($file->getPathname(), 'r+');
                if ($handle === false) {
                    continue;
                }
                $remove = flock($handle, LOCK_EX | LOCK_NB) && (fstat($handle)['mtime'] ?? time()) < time() - $seconds;
                fclose($handle);
                if ($remove && is_file($file->getPathname()) && unlink($file->getPathname())) {
                    $removed++;
                }
            }
            $counts[$directory] = $removed;
        }
        return $counts;
    }
}

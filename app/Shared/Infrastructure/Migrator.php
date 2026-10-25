<?php

declare(strict_types=1);

namespace Tai\Shared\Infrastructure;

use PDO;
use Tai\Shared\Domain\UserFacingException;

final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    public function status(): array
    {
        $exists = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'")->fetchColumn();
        $applied = $exists ? $this->pdo->query('SELECT version, checksum, status FROM schema_migrations')->fetchAll(PDO::FETCH_UNIQUE) : [];
        $rows = [];
        foreach ($this->files() as $file) {
            $version = basename($file, '.sql');
            $checksum = hash_file('sha256', $file);
            $stored = $applied[$version] ?? null;
            $rows[] = [
                'version' => $version,
                'checksum' => $checksum,
                'status' => $stored === null ? 'pending' : (!hash_equals($stored['checksum'], $checksum) ? 'checksum_mismatch' : $stored['status']),
            ];
            unset($applied[$version]);
        }
        foreach ($applied as $version => $stored) {
            $rows[] = ['version' => $version, 'checksum' => $stored['checksum'], 'status' => 'missing_file'];
        }
        return $rows;
    }

    public function migrate(): int
    {
        $lock = 'tai:migrate:' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new UserFacingException('Another migration is running.');
        }
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(16) NOT NULL CHECK (status IN ('started','applied','failed')),
                started_at DATETIME(6) NOT NULL, applied_at DATETIME(6) NULL,
                application_release VARCHAR(64) NOT NULL, duration_ms INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $rows = $this->status();
            foreach ($rows as $row) {
                if (!in_array($row['status'], ['pending', 'applied'], true)) {
                    throw new UserFacingException('Migration history needs review: ' . $row['version'] . ' (' . $row['status'] . ').');
                }
            }
            $count = 0;
            foreach ($rows as $row) {
                if ($row['status'] === 'applied') {
                    continue;
                }
                $start = hrtime(true);
                $insert = $this->pdo->prepare("INSERT INTO schema_migrations (version,checksum,status,started_at,application_release) VALUES (?,?,'started',UTC_TIMESTAMP(6),'phase-3b')");
                $insert->execute([$row['version'], $row['checksum']]);
                try {
                    // Each file is exactly one reviewed DDL statement. No multi-statement parser.
                    $this->pdo->exec(file_get_contents($this->directory . '/' . $row['version'] . '.sql'));
                    $done = $this->pdo->prepare("UPDATE schema_migrations SET status='applied',applied_at=UTC_TIMESTAMP(6),duration_ms=? WHERE version=? AND status='started'");
                    $done->execute([(int) ((hrtime(true) - $start) / 1000000), $row['version']]);
                    $count++;
                } catch (\Throwable $exception) {
                    $failed = $this->pdo->prepare("UPDATE schema_migrations SET status='failed' WHERE version=? AND status='started'");
                    $failed->execute([$row['version']]);
                    throw new UserFacingException('Migration failed; inspect schema/history before recovery: ' . $row['version'], 0, $exception);
                }
            }
            return $count;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    private function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (!preg_match('/^[0-9]{4}_[a-z0-9_]+\.sql$/D', basename($file))) {
                throw new UserFacingException('Invalid migration filename.');
            }
        }
        return $files;
    }
}

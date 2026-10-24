<?php

declare(strict_types=1);

namespace Tai\Modules\Privacy\Infrastructure;

use Tai\Modules\Queue\Application\QueueService;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Modules\Queue\Infrastructure\QueueRepository;
use Tai\Shared\Infrastructure\Config;

/** Synthetic artifacts only. Future downloads must add account authorization. */
final readonly class PrivateFileStore
{
    public function __construct(private QueueService $queue, private QueueRepository $repository, private Config $config)
    {
    }

    private function path(string $organizationId, string $key): string
    {
        JobPayload::identifier($organizationId);
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid private file key.');
        }
        $base = $this->config->storage('exports');
        $directory = $base . '/' . $organizationId;
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory))
            || !Config::within(realpath($directory) ?: '', $base)) {
            throw new \RuntimeException('Unsafe private file directory.');
        }
        $path = $directory . '/' . $key;
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe private file path.');
        }
        return $path;
    }

    public function create(string $organizationId, string $syntheticContent): string
    {
        if ($this->queue->inTransaction()) {
            throw new \LogicException('File registration requires its own committed transaction.');
        }
        if ($this->config->get('app.env') === 'production' || strlen($syntheticContent) > 65536) {
            throw new \InvalidArgumentException('Only bounded local/test synthetic artifacts are supported.');
        }
        $key = bin2hex(random_bytes(16));
        $path = $this->path($organizationId, $key);
        // Register first, in its own transaction, so a process killed during the
        // write leaves an expiring inventory row rather than an untracked file.
        $epoch = $this->queue->transaction(function () use ($organizationId, $key): int {
            $scope = $this->repository->scope($organizationId);
            if ($scope['blocked_at'] !== null || !$scope['organization_active']) {
                throw new \DomainException('Scope is blocked.');
            }
            $this->repository->execute('INSERT INTO private_files (organization_id,storage_key,privacy_epoch,expires_at) VALUES (?,?,?,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))', [$organizationId, $key, $scope['privacy_epoch'], $this->config->get('privacy.export_days')]);
            return (int) $scope['privacy_epoch'];
        });
        try {
            $this->queue->transaction(function () use ($organizationId, $syntheticContent, $key, $path, $epoch): void {
                $scope = $this->repository->scope($organizationId);
                $row = $this->repository->execute('SELECT id FROM private_files WHERE organization_id=? AND storage_key=?', [$organizationId, $key])->fetch();
                if ($scope['blocked_at'] !== null || !$scope['organization_active'] || (int) $scope['privacy_epoch'] !== $epoch || !$row) {
                    throw new \DomainException('Scope is blocked.');
                }
                $file = fopen($path, 'xb');
                if ($file === false) {
                    throw new \RuntimeException('Private file creation failed.');
                }
                try {
                    if (fwrite($file, $syntheticContent) !== strlen($syntheticContent) || !fflush($file)) {
                        throw new \RuntimeException('Private file write failed.');
                    }
                } finally {
                    fclose($file);
                }
                $this->repository->execute('UPDATE private_files SET is_ready=1 WHERE organization_id=? AND storage_key=?', [$organizationId, $key]);
            });
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
        return $key;
    }

    public function read(string $organizationId, string $key): string
    {
        $this->path($organizationId, $key);
        return $this->queue->transaction(function () use ($organizationId, $key): string {
            $scope = $this->repository->scope($organizationId);
            $row = $this->repository->execute('SELECT id FROM private_files WHERE organization_id=? AND storage_key=? AND privacy_epoch=? AND is_ready=1 AND expires_at>UTC_TIMESTAMP(6) AND created_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))', [$organizationId, $key, $scope['privacy_epoch'], -$this->config->get('privacy.export_days')])->fetch();
            if ($scope['blocked_at'] !== null || !$scope['organization_active'] || !$row) {
                throw new \DomainException('Private file is unavailable.');
            }
            $content = file_get_contents($this->path($organizationId, $key), false, null, 0, 65537);
            if ($content === false || strlen($content) > 65536) {
                throw new \RuntimeException('Private file unavailable.');
            }
            return $content;
        });
    }

    public function purge(string $organizationId): int
    {
        JobPayload::identifier($organizationId);
        return $this->queue->transaction(function () use ($organizationId): int {
            $scope = $this->repository->scope($organizationId);
            $blocked = $scope['blocked_at'] !== null;
            $rows = $this->repository->execute('SELECT id,storage_key FROM private_files WHERE organization_id=? AND (?=1 OR expires_at<=UTC_TIMESTAMP(6) OR created_at<TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6))) ORDER BY id LIMIT 100', [$organizationId, (int) $blocked, -$this->config->get('privacy.export_days')])->fetchAll();
            foreach ($rows as $row) {
                $path = $this->path($organizationId, $row['storage_key']);
                try {
                    if (file_exists($path) && !unlink($path)) {
                        throw new \RuntimeException('Private file purge failed.');
                    }
                } catch (\Throwable) {
                    throw new \Tai\Modules\Queue\Domain\JobFailure(true);
                }
                $this->repository->execute('DELETE FROM private_files WHERE organization_id=? AND id=?', [$organizationId, $row['id']]);
            }
            return count($rows);
        });
    }
}

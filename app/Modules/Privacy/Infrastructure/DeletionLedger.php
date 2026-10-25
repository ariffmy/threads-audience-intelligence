<?php

declare(strict_types=1);

namespace Tai\Modules\Privacy\Infrastructure;

use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Shared\Infrastructure\Config;

/** Independent, content-free signed deletion intents. Protect and back up separately. */
final readonly class DeletionLedger
{
    public function __construct(private Config $config, private bool $threads = false)
    {
    }

    private function path(): string
    {
        $directory = $this->config->get('privacy.ledger_path');
        $key = $this->config->get('privacy.ledger_key');
        if (!is_string($directory) || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $directory)
            || !is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \RuntimeException('Configure a private independent deletion ledger and signing key.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Deletion ledger unavailable.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || Config::within($resolved, $this->config->root . '/public')
            || Config::within($resolved, $this->config->storage())) {
            throw new \RuntimeException('Deletion ledger must be independent of application storage and public files.');
        }
        $path = $resolved . ($this->threads ? '/threads-deletions.jsonl' : '/deletions.jsonl');
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe deletion ledger path.');
        }
        return $path;
    }

    public function append(string $organizationId, string $key): void
    {
        JobPayload::identifier($organizationId);
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid deletion request key.');
        }
        $body = json_encode(['organization_id' => $organizationId, 'request_key' => $key, 'target_kind' => 'synthetic_scope', 'at' => gmdate('c')], JSON_THROW_ON_ERROR);
        $this->writeBody($body);
    }

    public function identifierHash(string $organizationId, string $scopeKey, string $mediaId): string
    {
        $this->path(); // Validate key and independent storage before producing suppression references.
        return hash_hmac('sha256', 'threads:fixture:fixture-app:' . $organizationId . ':' . $scopeKey . ':' . $mediaId, hex2bin($this->config->get('privacy.ledger_key')));
    }

    public function appendTarget(array $intent): void
    {
        if (!$this->threads) {
            throw new \LogicException('Use the separate Threads ledger.');
        }
        self::validateTarget($intent);
        $keys = array_keys($intent); sort($keys);
        if ($keys !== ['organization_id', 'request_key', 'scope_key', 'source_hash', 'target_kind']) {
            throw new \InvalidArgumentException('Unsupported deletion intent fields.');
        }
        $this->writeBody(json_encode($intent + ['v' => 2, 'at' => gmdate('c')], JSON_THROW_ON_ERROR));
    }

    private static function validateTarget(array $body): void
    {
        JobPayload::identifier($body['organization_id'] ?? '');
        if (!in_array($body['target_kind'] ?? '', ['threads_account', 'threads_source', 'threads_organization'], true)
            || !preg_match('/^[a-f0-9]{32}$/D', $body['request_key'] ?? '') || !preg_match('/^[a-f0-9]{32}$/D', $body['scope_key'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $body['source_hash'] ?? '')) {
            throw new \RuntimeException('Unsupported Threads deletion intent.');
        }
    }

    private function writeBody(string $body): void
    {
        $path = $this->path();
        $line = json_encode(['body' => $body, 'mac' => hash_hmac('sha256', $body, hex2bin($this->config->get('privacy.ledger_key')))], JSON_THROW_ON_ERROR) . "\n";
        $file = fopen($path, 'ab');
        if ($file === false) {
            throw new \RuntimeException('Cannot open deletion ledger.');
        }
        try {
            if (!flock($file, LOCK_EX | LOCK_NB) || fwrite($file, $line) !== strlen($line) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('Cannot durably record deletion intent.');
            }
        } finally {
            fclose($file);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }
    }

    /** Bounded streaming replay, always start at zero on an isolated restore. */
    public function replay(callable $apply, int $offset = 0): array
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Invalid ledger offset.');
        }
        $file = fopen($this->path(), 'rb');
        if ($file === false) {
            throw new \RuntimeException('Deletion ledger unavailable.');
        }
        try {
            if (!flock($file, LOCK_SH | LOCK_NB) || fseek($file, max(0, $offset - 1)) !== 0
                || ($offset > 0 && fread($file, 1) !== "\n")) {
                throw new \RuntimeException('Ledger offset or lock is invalid.');
            }
            for ($count = 0; $count < 100; $count++) {
                $line = fgets($file, 2048);
                if ($line === false) {
                    break;
                }
                if (!str_ends_with($line, "\n")) {
                    throw new \RuntimeException('Incomplete ledger record.');
                }
                $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                if (!is_string($record['body'] ?? null) || !is_string($record['mac'] ?? null)
                    || !hash_equals(hash_hmac('sha256', $record['body'], hex2bin($this->config->get('privacy.ledger_key'))), $record['mac'])) {
                    throw new \RuntimeException('Deletion ledger integrity check failed.');
                }
                $body = json_decode($record['body'], true, 8, JSON_THROW_ON_ERROR);
                JobPayload::identifier($body['organization_id']);
                if ($this->threads) {
                    self::validateTarget($body);
                    $keys = array_keys($body); sort($keys);
                    if (($body['v'] ?? null) !== 2 || $keys !== ['at', 'organization_id', 'request_key', 'scope_key', 'source_hash', 'target_kind', 'v']) {
                        throw new \RuntimeException('Unsupported Threads deletion intent.');
                    }
                    $apply($body);
                    continue;
                }
                if (($body['target_kind'] ?? null) !== 'synthetic_scope' || !preg_match('/^[a-f0-9]{32}$/D', $body['request_key'] ?? '')) {
                    throw new \RuntimeException('Unsupported deletion intent.');
                }
                $apply($body['organization_id'], $body['request_key']);
            }
            return ['offset' => ftell($file), 'records' => $count, 'done' => feof($file)];
        } finally {
            fclose($file);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Infrastructure;

use Tai\Modules\Threads\Domain\TokenContext;
use Tai\Shared\Infrastructure\Config;

final readonly class TokenVault
{
    /** @param array<string,string> $keys Raw 32-byte keys, injected only from private storage/tests. */
    public function __construct(private string $current, #[\SensitiveParameter] private array $keys)
    {
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('Threads credentials require the sodium extension.');
        }
        if (!isset($keys[$current]) || count($keys) > 8) {
            throw new \RuntimeException('Token keyring unavailable.');
        }
        foreach ($keys as $id => $key) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $id) || !is_string($key) || strlen($key) !== 32) {
                throw new \RuntimeException('Token keyring unavailable.');
            }
        }
    }

    public static function fromFile(Config $config): self
    {
        $path = $config->get('threads.keyring_path');
        $resolved = is_string($path) && preg_match('~^(?:[A-Za-z]:/|/)~', str_replace('\\', '/', $path)) ? realpath($path) : false;
        if ($resolved === false || Config::within($resolved, $config->root . '/public') || !is_file($resolved) || is_link($path)) {
            throw new \RuntimeException('Configure a private Threads encryption keyring.');
        }
        if (filesize($resolved) > 8192) {
            throw new \RuntimeException('Token keyring unavailable.');
        }
        $ring = json_decode(file_get_contents($resolved), true, 8, JSON_THROW_ON_ERROR);
        $keys = [];
        foreach (($ring['keys'] ?? []) as $id => $hex) {
            if (!is_string($hex) || !preg_match('/^[a-f0-9]{64}$/D', $hex)) {
                throw new \RuntimeException('Token keyring unavailable.');
            }
            $keys[$id] = hex2bin($hex);
        }
        return new self($ring['current'] ?? '', $keys);
    }

    public function seal(#[\SensitiveParameter] string $plaintext, TokenContext $context): string
    {
        if ($plaintext === '' || strlen($plaintext) > 8192) {
            throw new \RuntimeException('Credential size is unsupported.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context->associatedData($this->current), $nonce, $this->keys[$this->current]);
        return json_encode(['v' => 1, 'key' => $this->current, 'nonce' => base64_encode($nonce), 'cipher' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
    }

    public function open(#[\SensitiveParameter] string $envelope, TokenContext $context): string
    {
        try {
            if (strlen($envelope) > 16384) {
                throw new \RuntimeException();
            }
            $data = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
            $keys = array_keys($data); sort($keys);
            if ($keys !== ['cipher', 'key', 'nonce', 'v'] || $data['v'] !== 1 || !is_string($data['key']) || !isset($this->keys[$data['key']])) {
                throw new \RuntimeException();
            }
            $nonce = base64_decode($data['nonce'], true); $cipher = base64_decode($data['cipher'], true);
            if ($nonce === false || strlen($nonce) !== 24 || $cipher === false || strlen($cipher) > 8208) {
                throw new \RuntimeException();
            }
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $context->associatedData($data['key']), $nonce, $this->keys[$data['key']]);
            if ($plain === false || $plain === '') {
                throw new \RuntimeException();
            }
            return $plain;
        } catch (\Throwable) {
            throw new \RuntimeException('Credential integrity verification failed.');
        }
    }

    public function rotate(#[\SensitiveParameter] string $envelope, TokenContext $old, TokenContext $new): string
    {
        return $this->seal($this->open($envelope, $old), $new);
    }

    public function __debugInfo(): array
    {
        return ['current' => $this->current];
    }
}

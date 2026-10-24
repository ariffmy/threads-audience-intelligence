<?php

declare(strict_types=1);

namespace Tai\Shared\Infrastructure;

use Tai\Shared\Domain\UserFacingException;

final class Config
{
    private function __construct(private readonly array $values, public readonly string $root)
    {
    }

    public static function load(string $root): self
    {
        $root = realpath($root) ?: throw new UserFacingException('Invalid application root.');
        $values = require $root . '/config/defaults.php';
        $configured = getenv('TAI_CONFIG_FILE');
        $file = $configured !== false && $configured !== '' ? $configured : $root . '/config/local.php';
        if (is_file($file)) {
            $resolved = realpath($file);
            if ($resolved === false || self::within($resolved, $root . '/public')) {
                throw new UserFacingException('Configuration must be outside the public directory.');
            }
            $overrides = require $resolved;
            if (!is_array($overrides)) {
                throw new UserFacingException('Configuration must return an array.');
            }
            $values = array_replace_recursive($values, $overrides);
        } elseif ($configured !== false && $configured !== '') {
            throw new UserFacingException('The configured private configuration file is unavailable.');
        }
        $map = [
            'TAI_ENV' => ['app', 'env'], 'TAI_APP_URL' => ['app', 'url'],
            'TAI_APP_KEY' => ['app', 'key'], 'TAI_STORAGE_PATH' => ['app', 'storage'],
            'TAI_DB_HOST' => ['database', 'host'], 'TAI_DB_PORT' => ['database', 'port'],
            'TAI_DB_NAME' => ['database', 'name'], 'TAI_DB_USER' => ['database', 'username'],
            'TAI_DB_PASSWORD' => ['database', 'password'],
            'TAI_MIGRATION_DB_USER' => ['database', 'migration_username'],
            'TAI_MIGRATION_DB_PASSWORD' => ['database', 'migration_password'],
        ];
        foreach ($map as $name => [$group, $key]) {
            $value = getenv($name);
            if ($value !== false) {
                $values[$group][$key] = $name === 'TAI_DB_PORT' ? (int) $value : $value;
            }
        }
        $config = new self($values, $root);
        $config->validate();
        return $config;
    }

    public function get(string $key): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new \LogicException('Unknown configuration key.');
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function secureCookies(): bool
    {
        return parse_url($this->get('app.url'), PHP_URL_SCHEME) === 'https';
    }

    public function storage(string $directory = ''): string
    {
        if ($directory !== '' && !preg_match('/^[a-z]+$/D', $directory)) {
            throw new \InvalidArgumentException('Invalid storage directory.');
        }
        $base = $this->get('app.storage');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('Cannot create private storage.');
        }
        $resolved = realpath($base);
        if ($resolved === false || self::within($resolved, $this->root . '/public')) {
            throw new UserFacingException('Storage must be outside the public directory.');
        }
        $path = $resolved . ($directory === '' ? '' : '/' . $directory);
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create private storage.');
        }
        if (self::within(realpath($path) ?: $path, $this->root . '/public') || !is_writable($path)) {
            throw new UserFacingException('Private storage is unavailable.');
        }
        return $path;
    }

    public static function within(string $path, string $parent): bool
    {
        $path = str_replace('\\', '/', $path);
        $parent = rtrim(str_replace('\\', '/', realpath($parent) ?: $parent), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private function validate(): void
    {
        if (PHP_VERSION_ID < 80500 || !in_array($this->get('app.env'), ['local', 'test', 'production'], true)) {
            throw new UserFacingException('PHP 8.5 and a valid application environment are required.');
        }
        if (!is_string($this->get('app.key')) || !preg_match('/^[a-f0-9]{64}$/D', $this->get('app.key'))) {
            throw new UserFacingException('Set a private 64-character application key using key:generate.');
        }
        $url = parse_url($this->get('app.url'));
        if ($url === false || !isset($url['scheme'], $url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || (isset($url['path']) && $url['path'] !== '' && $url['path'] !== '/')
            || !in_array($url['scheme'], ['http', 'https'], true)) {
            throw new UserFacingException('Set an origin-only canonical application URL.');
        }
        if ($url['scheme'] !== 'https'
            && ($this->get('app.env') === 'production' || !in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new UserFacingException('HTTP is allowed only for loopback local/test environments.');
        }
        foreach (['host', 'name', 'username'] as $key) {
            if (!is_string($this->get('database.' . $key))
                || preg_match('/[;\x00-\x20]/', $this->get('database.' . $key))) {
                throw new UserFacingException('Invalid database configuration.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $this->get('database.name'))
            || $this->get('database.port') < 1 || $this->get('database.port') > 65535) {
            throw new UserFacingException('Invalid database name or port.');
        }
        foreach (['idle_seconds', 'absolute_seconds'] as $key) {
            if (!is_int($this->get('session.' . $key)) || $this->get('session.' . $key) < 60) {
                throw new UserFacingException('Invalid session time limits.');
            }
        }
        foreach (['email_attempts', 'ip_attempts', 'window_seconds', 'audit_days', 'log_days'] as $key) {
            if (!is_int($this->get('security.' . $key)) || $this->get('security.' . $key) < 1) {
                throw new UserFacingException('Invalid security configuration.');
            }
        }
        if (!is_int($this->get('security.bcrypt_cost')) || $this->get('security.bcrypt_cost') < 12
            || $this->get('security.bcrypt_cost') > 14) {
            throw new UserFacingException('Bcrypt cost must be between 12 and 14.');
        }
    }
}

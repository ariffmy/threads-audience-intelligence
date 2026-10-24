<?php

declare(strict_types=1);

namespace Tai\Modules\Identity\Infrastructure;

use Tai\Modules\Identity\Domain\SessionStore;
use Tai\Shared\Infrastructure\Config;

final class NativeSession implements SessionStore
{
    public function __construct(Config $config)
    {
        if (session_status() !== PHP_SESSION_NONE) {
            throw new \LogicException('Session already initialized.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.upload_progress.enabled', '0');
        ini_set('session.gc_maxlifetime', (string) $config->get('session.absolute_seconds'));
        ini_set('session.gc_probability', '0'); // Explicit bounded maintenance command.
        ini_set('session.save_handler', 'files');
        session_save_path($config->storage('sessions'));
        session_name($config->secureCookies() ? '__Host-tai_session' : 'tai_local_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $config->secureCookies(),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('Private session storage unavailable.');
        }
        $now = time();
        if (isset($_SESSION['_seen'], $_SESSION['_issued'])
            && ($now - (int) $_SESSION['_seen'] >= $config->get('session.idle_seconds')
                || $now - (int) $_SESSION['_issued'] >= $config->get('session.absolute_seconds'))) {
            $_SESSION = [];
            $this->regenerate();
        }
        $_SESSION['_issued'] ??= $now;
        $_SESSION['_seen'] = $now;
    }

    public function id(): string
    {
        return session_id();
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Session rotation failed.');
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => '/', 'secure' => $params['secure'],
                'httponly' => true, 'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}

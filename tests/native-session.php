<?php

declare(strict_types=1);

use Tai\Modules\Identity\Infrastructure\NativeSession;
use Tai\Shared\Infrastructure\Config;

require dirname(__DIR__) . '/app/Bootstrap/autoload.php';
$config = Config::load(dirname(__DIR__));
session_id('attacker-fixed-session-id');
$session = new NativeSession($config);
$strict = $session->id() !== 'attacker-fixed-session-id';
$params = session_get_cookie_params();
$first = $session->id();
$session->regenerate();
$rotation = $first !== $session->id();
$session->put('_authenticated', true);
$session->put('_seen', time() - 1900);
$old = $session->id();
$session->close();
session_id($old);
$session = new NativeSession($config);
$idle = $session->get('_authenticated') === null && $session->id() !== $old;
$session->put('_authenticated', true);
$session->put('_issued', time() - 44000);
$old = $session->id();
$session->close();
session_id($old);
$session = new NativeSession($config);
$absolute = $session->get('_authenticated') === null && $session->id() !== $old;
$old = $session->id();
$session->destroy();
echo json_encode([
    'strict' => $strict, 'rotation' => $rotation, 'idle' => $idle, 'absolute' => $absolute,
    'destroyed' => !is_file($config->storage('sessions') . '/sess_' . $old),
    'httponly' => $params['httponly'], 'secure' => $params['secure'],
    'samesite' => $params['samesite'], 'path' => $params['path'], 'domain' => $params['domain'],
], JSON_THROW_ON_ERROR);

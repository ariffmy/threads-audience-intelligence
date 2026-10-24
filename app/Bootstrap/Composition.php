<?php

declare(strict_types=1);

namespace Tai\Bootstrap;

use Tai\Modules\Identity\Application\AuthenticationService;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Modules\Identity\Application\PasswordHasher;
use Tai\Modules\Identity\Domain\SessionStore;
use Tai\Modules\Identity\Http\AuthenticationController;
use Tai\Modules\Identity\Infrastructure\AuditLog;
use Tai\Modules\Identity\Infrastructure\LoginRateLimiter;
use Tai\Modules\Identity\Infrastructure\IdentityRepository;
use Tai\Modules\Operations\Infrastructure\SystemStatus;
use Tai\Modules\Operations\Infrastructure\OperationsRepository;
use Tai\Modules\Operations\Http\AdminController;
use Tai\Shared\Http\View;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Database;
use Tai\Shared\Infrastructure\Logger;
use Tai\Shared\Infrastructure\Migrator;

final class Composition
{
    public static function web(Config $config, SessionStore $session, Logger $logger): Application
    {
        $pdo = (new Database($config))->connection();
        $view = new View($config->root . '/resources/views');
        $csrf = new Csrf($session);
        $auth = new AuthenticationService(
            new IdentityRepository($pdo), $config, $session, new PasswordHasher($config->get('security.bcrypt_cost')),
            new AuditLog($pdo, $config->get('security.audit_days')), new LoginRateLimiter($pdo, $config),
        );
        $operations = new OperationsRepository($pdo);
        $status = new SystemStatus($pdo, $config, new Migrator($pdo, $config->root . '/database/migrations'), $operations);
        return new Application(
            $config, $session, $auth, $csrf,
            new AuthenticationController($auth, $csrf, $view),
            new AdminController($auth, $csrf, $session, $view, $config, $status, $operations),
            $view, $logger,
        );
    }
}

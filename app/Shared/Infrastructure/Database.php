<?php

declare(strict_types=1);

namespace Tai\Shared\Infrastructure;

use PDO;
use Tai\Shared\Domain\UserFacingException;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly Config $config, private readonly bool $migration = false)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $name = $this->config->get('database.name');
        $username = $this->config->get('database.username');
        $password = $this->config->get('database.password');
        if ($this->migration && $this->config->get('database.migration_username') !== '') {
            $username = $this->config->get('database.migration_username');
            $password = $this->config->get('database.migration_password');
        }
        if ($name === '' || $username === '') {
            throw new UserFacingException('Configure a database and database user before running this command.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->get('database.host'), $this->config->get('database.port'), $name);
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $this->connection = $pdo;
        return $pdo;
    }
}

<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /** @var PDO */
    private $pdo;

    public function __construct(Config $config)
    {
        $host = (string)$config->get('db.host', '');
        $port = (int)$config->get('db.port', 3306);
        $name = (string)$config->get('db.name', '');
        $user = (string)$config->get('db.user', '');
        $pass = (string)$config->get('db.pass', '');

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('Chybí konfigurace databáze. Zkontrolujte soubor .env.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('EV Stats database connection error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

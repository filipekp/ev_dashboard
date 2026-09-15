<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Application
{
    /** @var Config */ private $config;
    /** @var Database */ private $database;
    /** @var Session */ private $session;
    /** @var AuthService */ private $auth;
    /** @var string */ private $root;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, string $root)
    {
        $this->root = $root;
        $this->config = new Config($config);
        $this->configureErrors();
        $this->session = new Session($this->config);
        $this->session->start();
        $this->database = new Database($this->config);
        $this->auth = new AuthService($this->database->pdo(), $this->config);
    }

    private function configureErrors(): void
    {
        $env = (string)$this->config->get('app.env', 'production');
        $debug = (bool)$this->config->get('app.debug', false);
        error_reporting(E_ALL);
        if ($debug || $env !== 'production') {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        } else {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
        }
    }

    public function config(): Config { return $this->config; }
    public function pdo(): PDO { return $this->database->pdo(); }
    public function session(): Session { return $this->session; }
    public function auth(): AuthService { return $this->auth; }
    public function root(): string { return $this->root; }

    public function importer(): CsvImporter
    {
        return new CsvImporter($this->pdo());
    }

    public function migrations(): MigrationManager
    {
        return new MigrationManager($this->pdo(), $this->root . '/sql');
    }

    public function updater(): GitHubUpdater
    {
        return new GitHubUpdater($this->config, $this->root, $this->migrations());
    }
}

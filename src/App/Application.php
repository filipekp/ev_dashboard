<?php

declare(strict_types=1);

namespace App;

use App\Csv\CsvExporter;
use App\Csv\CsvPluginLoader;
use App\Csv\CsvPluginRegistry;
use App\Import\Plugin\CsvVehicleImportPlugin;
use App\Import\Plugin\KiaConnectXlsxPlugin;
use App\Import\TripFileImporter;
use App\Repository\IntegrationImportRunRepository;
use App\Repository\TripRepository;
use App\Repository\VehicleOperationRepository;
use App\Repository\VehicleRepository;
use App\Service\AnalyticsService;
use App\Service\DashboardService;
use App\Service\ImportService;
use App\Service\VehicleOperationService;
use PDO;

/**
 * Kořenový aplikační kontejner.
 *
 * Centralizuje vytváření služeb a repositories bez globálních singletonů.
 * Instance se vytvářejí lazy, aby se neinicializovaly části aplikace, které
 * konkrétní HTTP request nepotřebuje.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class Application
{
    /** @var Config */
    private $config;

    /** @var Database */
    private $database;

    /** @var Session */
    private $session;

    /** @var AuthService */
    private $auth;

    /** @var string */
    private $root;

    /** @var CsvPluginRegistry|null */
    private $csvPlugins;

    /** @var TripRepository|null */
    private $trips;

    /** @var IntegrationImportRunRepository|null */
    private $integrationImportRuns;

    /** @var VehicleRepository|null */
    private $vehicles;

    /** @var VehicleOperationRepository|null */
    private $vehicleOperations;

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

    public function config(): Config
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        return $this->database->pdo();
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function auth(): AuthService
    {
        return $this->auth;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function csvPlugins(): CsvPluginRegistry
    {
        if ($this->csvPlugins === null) {
            $loader = new CsvPluginLoader(__DIR__ . '/Csv/Plugin');
            $this->csvPlugins = new CsvPluginRegistry($loader->load());
        }

        return $this->csvPlugins;
    }

    public function trips(): TripRepository
    {
        if ($this->trips === null) {
            $this->trips = new TripRepository($this->pdo());
        }

        return $this->trips;
    }

    public function vehicles(): VehicleRepository
    {
        if ($this->vehicles === null) {
            $this->vehicles = new VehicleRepository($this->pdo());
        }

        return $this->vehicles;
    }

    public function integrationImportRuns(): IntegrationImportRunRepository
    {
        if ($this->integrationImportRuns === null) {
            $this->integrationImportRuns = new IntegrationImportRunRepository($this->pdo());
        }

        return $this->integrationImportRuns;
    }

    public function vehicleOperations(): VehicleOperationRepository
    {
        if ($this->vehicleOperations === null) {
            $this->vehicleOperations = new VehicleOperationRepository($this->pdo());
        }

        return $this->vehicleOperations;
    }

    public function importer(): TripFileImporter
    {
        return new TripFileImporter(
            $this->pdo(),
            $this->trips(),
            [
                new KiaConnectXlsxPlugin(),
                new CsvVehicleImportPlugin($this->csvPlugins()),
            ]
        );
    }

    public function exporter(): CsvExporter
    {
        return new CsvExporter($this->csvPlugins(), $this->trips());
    }

    public function importService(): ImportService
    {
        return new ImportService(
            $this->pdo(),
            $this->auth(),
            $this->importer(),
            $this->vehicles(),
            $this->trips(),
            $this->integrationImportRuns()
        );
    }

    public function dashboard(): DashboardService
    {
        return new DashboardService($this->pdo(), $this->vehicleOperations());
    }

    public function analytics(): AnalyticsService
    {
        return new AnalyticsService($this->pdo());
    }

    public function vehicleOperationService(): VehicleOperationService
    {
        return new VehicleOperationService(
            $this->vehicleOperations(),
            $this->root . '/storage'
        );
    }

    public function template(): Template
    {
        return new Template($this->root . '/templates');
    }

    public function migrations(): MigrationManager
    {
        return new MigrationManager($this->pdo(), $this->root . '/sql');
    }

    public function version(): AppVersion
    {
        return new AppVersion($this->root);
    }

    public function updater(): GitHubUpdater
    {
        return new GitHubUpdater($this->config, $this->root, $this->migrations());
    }

    private function configureErrors(): void
    {
        $environment = (string)$this->config->get('app.env', 'production');
        $debug = (bool)$this->config->get('app.debug', false);
        error_reporting(E_ALL);

        if ($debug || $environment !== 'production') {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            return;
        }

        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
    }
}

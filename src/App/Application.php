<?php

declare(strict_types=1);

namespace App;

use App\Csv\CsvExporter;
use App\Csv\CsvPluginLoader;
use App\Csv\CsvPluginRegistry;
use App\Import\Plugin\CsvVehicleImportPlugin;
use App\Import\Plugin\KiaConnectXlsxPlugin;
use App\Import\TripFileImporter;
use App\Document\Ai\GeminiDocumentExtractor;
use App\Document\Ai\OpenAiDocumentExtractor;
use App\Document\Parser\CezFuturegoInvoiceParser;
use App\Document\Parser\DocumentParserRegistry;
use App\Document\Parser\EonDriveInvoiceParser;
use App\Document\Parser\JsonDocumentParser;
use App\Document\Parser\PowerpassElliInvoiceParser;
use App\Repository\AdminImportMonitoringRepository;
use App\Repository\DocumentRepository;
use App\Repository\IntegrationImportRunRepository;
use App\Repository\TripRepository;
use App\Repository\UnknownImportRepository;
use App\Repository\VehicleMediaRepository;
use App\Repository\VehicleOperationRepository;
use App\Repository\VehicleRepository;
use App\Repository\VehicleDataRepository;
use App\Service\AnalyticsService;
use App\Service\DashboardService;
use App\Service\DocumentImportService;
use App\Service\ImportService;
use App\Service\UnknownImportService;
use App\Service\RegistrationService;
use App\Service\VehicleMediaService;
use App\Service\VehicleOperationService;
use App\Service\VehicleTimelineService;
use App\Service\VehicleSyncService;
use App\Service\UserAccessService;
use App\Service\VehicleInsightService;
use App\Service\VehicleConnectorService;
use App\Integration\Vehicle\VehicleConnectorRegistry;
use App\Integration\Vehicle\Skoda\SkodaConnector;
use App\Integration\Vehicle\Skoda\SkodaPublicApiClient;
use App\Security\CredentialCipher;
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

    /** @var AdminImportMonitoringRepository|null */
    private $adminImportMonitoring;

    /** @var VehicleRepository|null */
    private $vehicles;

    /** @var UnknownImportRepository|null */
    private $unknownImports;

    /** @var VehicleOperationRepository|null */
    private $vehicleOperations;

    /** @var DocumentRepository|null */
    private $documents;

    /** @var UserAccessService|null */
    private $userAccess;

    /** @var RegistrationService|null */
    private $registration;

    /** @var VehicleMediaRepository|null */
    private $vehicleMedia;

    /** @var VehicleDataRepository|null */
    private $vehicleData;

    /** @var VehicleConnectorRegistry|null */
    private $vehicleConnectorRegistry;

    /** @var CredentialCipher|null */
    private $credentialCipher;

    /** @var RateLimiter|null */
    private $rateLimiter;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, string $root)
    {
        $this->root = $root;
        $this->config = new Config($config);
        $this->configureErrors();
        $this->session = new Session($this->config);
        $this->session->start();
        SecurityHeaders::send($this->config, $this->session);
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


    public function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = new RateLimiter($this->root . '/storage');
        }

        return $this->rateLimiter;
    }

    public function userAccess(): UserAccessService
    {
        if ($this->userAccess === null) {
            $this->userAccess = new UserAccessService($this->pdo());
        }

        return $this->userAccess;
    }

    public function registration(): RegistrationService
    {
        if ($this->registration === null) {
            $this->registration = new RegistrationService($this->pdo(), $this->config);
        }

        return $this->registration;
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

    public function unknownImports(): UnknownImportRepository
    {
        if ($this->unknownImports === null) {
            $this->unknownImports = new UnknownImportRepository($this->pdo());
        }

        return $this->unknownImports;
    }

    public function unknownImportService(): UnknownImportService
    {
        return new UnknownImportService(
            $this->pdo(),
            $this->auth(),
            $this->config(),
            $this->root(),
            $this->unknownImports(),
            $this->vehicles(),
            $this->trips(),
            $this->integrationImportRuns()
        );
    }

    public function integrationImportRuns(): IntegrationImportRunRepository
    {
        if ($this->integrationImportRuns === null) {
            $this->integrationImportRuns = new IntegrationImportRunRepository($this->pdo());
        }

        return $this->integrationImportRuns;
    }

    public function adminImportMonitoring(): AdminImportMonitoringRepository
    {
        if ($this->adminImportMonitoring === null) {
            $this->adminImportMonitoring = new AdminImportMonitoringRepository($this->pdo());
        }

        return $this->adminImportMonitoring;
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


    public function documents(): DocumentRepository
    {
        if ($this->documents === null) {
            $this->documents = new DocumentRepository($this->pdo());
        }
        return $this->documents;
    }

    public function vehicleMedia(): VehicleMediaRepository
    {
        if ($this->vehicleMedia === null) {
            $this->vehicleMedia = new VehicleMediaRepository($this->pdo());
        }
        return $this->vehicleMedia;
    }

    public function vehicleMediaService(): VehicleMediaService
    {
        return new VehicleMediaService($this->vehicleMedia(), $this->root . '/storage');
    }

    public function documentImportService(): DocumentImportService
    {
        $provider = strtolower((string)$this->config->get('ai.provider', 'none'));
        $ai = null;
        if ($provider === 'openai') {
            $ai = new OpenAiDocumentExtractor(
                (string)$this->config->get('ai.openai_api_key', ''),
                (string)$this->config->get('ai.openai_model', 'gpt-5.6-luna')
            );
        } elseif ($provider === 'gemini') {
            $ai = new GeminiDocumentExtractor(
                (string)$this->config->get('ai.gemini_api_key', ''),
                (string)$this->config->get('ai.gemini_model', 'gemini-3.8-flash')
            );
        }

        return new DocumentImportService(
            $this->pdo(),
            $this->documents(),
            $this->vehicleOperations(),
            new DocumentParserRegistry([
                new PowerpassElliInvoiceParser(),
                new CezFuturegoInvoiceParser(),
                new EonDriveInvoiceParser(),
                new JsonDocumentParser(),
            ]),
            $ai,
            $this->root . '/storage'
        );
    }

    public function timeline(): VehicleTimelineService
    {
        return new VehicleTimelineService($this->pdo());
    }

    public function insights(): VehicleInsightService
    {
        return new VehicleInsightService($this->pdo());
    }

    public function vehicleData(): VehicleDataRepository
    {
        if ($this->vehicleData === null) {
            $this->vehicleData = new VehicleDataRepository($this->pdo());
        }
        return $this->vehicleData;
    }

    public function vehicleConnectors(): VehicleConnectorRegistry
    {
        if ($this->vehicleConnectorRegistry === null) {
            $skodaClient = new SkodaPublicApiClient(
                (string)$this->config->get('vehicle_connectors.skoda.api_url', 'https://public.api.connect.skoda-auto.cz'),
                (int)$this->config->get('vehicle_connectors.skoda.timeout_seconds', 30)
            );
            $this->vehicleConnectorRegistry = new VehicleConnectorRegistry([
                new SkodaConnector(
                    $skodaClient,
                    (int)$this->config->get('vehicle_connectors.skoda.sync_interval_seconds', 600),
                    (int)$this->config->get('vehicle_connectors.skoda.rate_limit_reserve', 3)
                ),
            ]);
        }

        return $this->vehicleConnectorRegistry;
    }

    public function credentialCipher(): CredentialCipher
    {
        if ($this->credentialCipher === null) {
            $this->credentialCipher = new CredentialCipher(
                (string)$this->config->get('vehicle_connectors.credentials_key', '')
            );
        }

        return $this->credentialCipher;
    }

    public function vehicleSync(): VehicleSyncService
    {
        return new VehicleSyncService(
            $this->pdo(),
            $this->vehicleData(),
            $this->vehicleConnectors(),
            $this->credentialCipher()
        );
    }

    public function vehicleConnectorService(): VehicleConnectorService
    {
        return new VehicleConnectorService(
            $this->auth(),
            $this->vehicles(),
            $this->vehicleData(),
            $this->vehicleConnectors(),
            $this->credentialCipher(),
            $this->vehicleSync()
        );
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

<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\Pagination;
use RuntimeException;
use Throwable;

/**
 * Přehled přímých OEM konektorů a jejich synchronizace.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class IntegrationsController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $user = $this->app->auth()->requireLogin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->app->session()->verifyCsrf();
            try {
                $action = (string)($_POST['action'] ?? '');
                if ($action !== 'sync_connection') {
                    throw new RuntimeException('Neznámá operace.');
                }
                $connectionId = (int)($_POST['connection_id'] ?? 0);
                $connection = $this->app->vehicleData()->findConnection($connectionId);
                $vehicleId = (int)$connection['vehicle_id'];
                if (!$this->app->auth()->canAccessVehicle($user, $vehicleId)) {
                    throw new RuntimeException('Tuto Connected Car vazbu nemůžete synchronizovat.');
                }
                $result = $this->app->vehicleSync()->syncConnection($connectionId, (int)$user['id'], 'manual');
                $this->app->session()->flash(
                    'Synchronizace dokončena: ' . $result['snapshots'] . ' nový snapshot, ' . $result['events'] . ' nové události.',
                    'ok'
                );
            } catch (Throwable $e) {
                $this->app->session()->flash($e->getMessage(), 'error');
            }
            Http::redirect('integrations.php');
        }

        $vehicles = $this->app->auth()->allowedVehicles($user);
        $vehiclePage = Pagination::slice($vehicles, 'page');
        $runPagination = Pagination::meta(
            $this->app->vehicleData()->syncRunCountForUser((int)$user['id']),
            Pagination::currentPage('runs_page'),
            Pagination::DEFAULT_PER_PAGE,
            'runs_page'
        );
        $items = [];
        foreach ($vehiclePage['items'] as $vehicle) {
            $connection = $this->app->vehicleData()->findConnectionForVehicle((int)$vehicle['id']);
            $items[] = [
                'vehicle' => $vehicle,
                'connectors' => $this->app->vehicleConnectors()->descriptorsForVehicle($vehicle),
                'connection' => $connection,
                'telemetry' => $connection !== null
                    ? $this->app->vehicleData()->latestTelemetry((int)$connection['id'])
                    : null,
            ];
        }

        $this->app->template()->render('integrations', [
            'app' => $this->app,
            'user' => $user,
            'vehicles' => $vehicles,
            'vehicle' => $this->app->auth()->selectVehicle($user),
            'items' => $items,
            'pagination' => $vehiclePage['pagination'],
            'recentRuns' => $this->app->vehicleData()->recentRunsForUser(
                (int)$user['id'],
                Pagination::DEFAULT_PER_PAGE,
                ($runPagination['page'] - 1) * Pagination::DEFAULT_PER_PAGE
            ),
            'runPagination' => $runPagination,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }
}

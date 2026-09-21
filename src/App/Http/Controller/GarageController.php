<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Pagination;

/**
 * Controller souhrnného analytického Garage dashboardu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class GarageController
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
        $vehicles = $this->app->auth()->allowedVehicles($user);
        $data = $this->app->analytics()->build($vehicles, $_GET);

        $garagePage = Pagination::slice($data['comparison'] ?? [], 'page');
        $data['comparison'] = $garagePage['items'];
        $visibleVehicleIds = array_map(static function (array $vehicle): int {
            return (int)$vehicle['id'];
        }, $data['comparison']);

        $this->app->template()->render('garage', array_merge($data, [
            'app' => $this->app,
            'user' => $user,
            'vehicles' => $vehicles,
            'pagination' => $garagePage['pagination'],
            'vehiclePhotos' => $this->app->vehicleMedia()->primaries($visibleVehicleIds),
            'flash' => $this->app->session()->pullFlash(),
            'activeVehicle' => $this->app->auth()->selectVehicle($user),
        ]));
    }
}

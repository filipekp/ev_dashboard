<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;

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

        $vehicleIds = array_map(static function (array $vehicle): int {
            return (int)$vehicle['id'];
        }, $vehicles);

        $this->app->template()->render('garage', array_merge($data, [
            'app' => $this->app,
            'user' => $user,
            'vehicles' => $vehicles,
            'vehiclePhotos' => $this->app->vehicleMedia()->primaries($vehicleIds),
            'flash' => $this->app->session()->pullFlash(),
            'activeVehicle' => $this->app->auth()->selectVehicle($user),
        ]));
    }
}

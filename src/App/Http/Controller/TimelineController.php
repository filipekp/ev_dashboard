<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;

final class TimelineController
{
    /** @var Application */ private $app;
    public function __construct(Application $app) { $this->app = $app; }

    public function handle(): void
    {
        $user = $this->app->auth()->requireLogin();
        $vehicle = $this->app->auth()->selectVehicle($user);
        if (!$vehicle) {
            Http::redirect('index.php');
        }
        $scope = $this->app->userAccess()->vehicleDetailScope($user, (int)$vehicle['id']);
        $vehiclePhoto = $this->app->vehicleMedia()->primaryForVehicle((int)$vehicle['id']);
        if ($vehiclePhoto && !$this->app->userAccess()->canReadDetailAt($user, (int)$vehicle['id'], (string)($vehiclePhoto['created_at'] ?? ''))) {
            $vehiclePhoto = null;
        }
        $this->app->template()->render('timeline', [
            'app' => $this->app,
            'user' => $user,
            'vehicle' => $vehicle,
            'vehicles' => $this->app->auth()->allowedVehicles($user),
            'events' => $this->app->timeline()->build((int)$vehicle['id'], 80, $scope),
            'insights' => $this->app->insights()->build($vehicle, $scope),
            'vehiclePhoto' => $vehiclePhoto,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }
}

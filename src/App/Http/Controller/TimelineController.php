<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\Pagination;

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
        $eventCount = $this->app->timeline()->count((int)$vehicle['id'], $scope);
        $pagination = Pagination::meta($eventCount, Pagination::currentPage('page'), Pagination::DEFAULT_PER_PAGE, 'page');
        $vehiclePhoto = $this->app->vehicleMedia()->primaryForVehicle((int)$vehicle['id']);
        if ($vehiclePhoto && !$this->app->userAccess()->canReadDetailAt($user, (int)$vehicle['id'], (string)($vehiclePhoto['created_at'] ?? ''))) {
            $vehiclePhoto = null;
        }
        $this->app->template()->render('timeline', [
            'app' => $this->app,
            'user' => $user,
            'vehicle' => $vehicle,
            'vehicles' => $this->app->auth()->allowedVehicles($user),
            'events' => $this->app->timeline()->build(
                (int)$vehicle['id'],
                Pagination::DEFAULT_PER_PAGE,
                $scope,
                ($pagination['page'] - 1) * Pagination::DEFAULT_PER_PAGE
            ),
            'pagination' => $pagination,
            'insights' => $this->app->insights()->build($vehicle, $scope),
            'vehiclePhoto' => $vehiclePhoto,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }
}

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Http;

try {
    $me = $app->auth()->requireVehicleManager();
    $app->session()->verifyCsrf((string)($_GET['csrf'] ?? ''));
    $vehicleId = max(0, (int)($_GET['vehicle_id'] ?? 0));
    Http::redirect($app->kiaPleos()->startLogin($me, $vehicleId));
} catch (Throwable $e) {
    $app->session()->flash($e->getMessage(), 'error');
    Http::redirect('vehicles.php');
}

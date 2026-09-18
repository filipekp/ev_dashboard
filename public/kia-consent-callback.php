<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Http;

try {
    $me = $app->auth()->requireVehicleManager();
    $vehicleId = $app->kiaPleos()->completeConsent($me, $_GET);
    $app->session()->flash('Kia Connect bylo připojeno a první synchronizace proběhla úspěšně.', 'ok');
    Http::redirect('vehicles.php?edit=' . $vehicleId);
} catch (Throwable $e) {
    $app->session()->flash($e->getMessage(), 'error');
    Http::redirect('vehicles.php');
}

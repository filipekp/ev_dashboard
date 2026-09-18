<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Http;

try {
    $me = $app->auth()->requireVehicleManager();
    $code = trim((string)($_GET['code'] ?? ''));
    $state = trim((string)($_GET['state'] ?? ''));
    if ($code === '' || $state === '') {
        throw new RuntimeException('Kia přihlášení nebylo dokončeno. Chybí autorizační kód nebo state.');
    }

    Http::redirect($app->kiaPleos()->completeLogin($me, $code, $state));
} catch (Throwable $e) {
    $app->session()->flash($e->getMessage(), 'error');
    Http::redirect('vehicles.php');
}

<?php

declare(strict_types=1);

use App\Http;
use App\Integration\Vehicle\OAuthVehicleConnectorInterface;

require dirname(__DIR__) . '/src/bootstrap.php';

$me = $app->auth()->requireVehicleManager();
$state = trim((string)($_GET['state'] ?? ''));
$vehicleId = 0;

try {
    if ($state === '' || !isset($_SESSION['vehicle_connector_oauth'][$state]) || !is_array($_SESSION['vehicle_connector_oauth'][$state])) {
        throw new RuntimeException('OAuth relace vypršela nebo není platná. Spusťte připojení vozidla znovu.');
    }

    $oauth = $_SESSION['vehicle_connector_oauth'][$state];
    unset($_SESSION['vehicle_connector_oauth'][$state]);
    $vehicleId = (int)($oauth['vehicle_id'] ?? 0);
    $provider = trim((string)($oauth['provider'] ?? ''));

    if ((int)($oauth['user_id'] ?? 0) !== (int)$me['id'] || (int)($oauth['created_at'] ?? 0) < time() - 900) {
        throw new RuntimeException('OAuth relace již není platná. Spusťte připojení znovu.');
    }
    if ($vehicleId <= 0 || !$app->auth()->canAccessVehicle($me, $vehicleId)) {
        throw new RuntimeException('Toto vozidlo nemůžete spravovat.');
    }

    $providerError = trim((string)($_GET['error'] ?? ''));
    if ($providerError !== '') {
        throw new RuntimeException('Autorizace u výrobce byla zrušena nebo odmítnuta.');
    }

    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Výrobce nevrátil autorizační kód.');
    }

    $connector = $app->vehicleConnectors()->get($provider);
    if (!$connector instanceof OAuthVehicleConnectorInterface) {
        throw new RuntimeException('OEM konektor nepodporuje OAuth callback.');
    }

    $credentials = $connector->exchangeAuthorizationCode($code);
    $result = $app->vehicleConnectorService()->connect($me, $vehicleId, $provider, $credentials);
    $app->session()->flash(
        'OEM účet byl bezpečně autorizován. Uloženo ' . $result['snapshots'] . ' nových telemetry snapshotů.',
        'ok'
    );
} catch (Throwable $e) {
    $app->session()->flash($e->getMessage(), 'error');
}

Http::redirect('vehicles.php?edit=' . max(0, $vehicleId));

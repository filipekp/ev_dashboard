<?php

declare(strict_types=1);

use App\Http;
use App\Integration\Vehicle\OAuthVehicleConnectorInterface;

require dirname(__DIR__) . '/src/bootstrap.php';

$me = $app->auth()->requireVehicleManager();
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$provider = trim((string)($_GET['provider'] ?? ''));

try {
    if ($vehicleId <= 0 || !$app->auth()->canAccessVehicle($me, $vehicleId)) {
        throw new RuntimeException('Toto vozidlo nemůžete spravovat.');
    }

    $vehicle = $app->vehicles()->find($vehicleId);
    if ($vehicle === null) {
        throw new RuntimeException('Vozidlo nebylo nalezeno.');
    }

    $connector = $app->vehicleConnectors()->get($provider);
    if (!$connector instanceof OAuthVehicleConnectorInterface) {
        throw new RuntimeException('Vybraný OEM konektor nepoužívá OAuth autorizaci.');
    }
    if (!$connector->supportsVehicle($vehicle)) {
        throw new RuntimeException('Vybraný konektor není určen pro výrobce tohoto vozidla.');
    }
    if (!$connector->oauthConfigured()) {
        throw new RuntimeException('OAuth konektor zatím není nakonfigurován v .env.');
    }

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(24));
    $now = time();

    if (!isset($_SESSION['vehicle_connector_oauth']) || !is_array($_SESSION['vehicle_connector_oauth'])) {
        $_SESSION['vehicle_connector_oauth'] = [];
    }
    foreach ($_SESSION['vehicle_connector_oauth'] as $key => $entry) {
        if (!is_array($entry) || (int)($entry['created_at'] ?? 0) < $now - 900) {
            unset($_SESSION['vehicle_connector_oauth'][$key]);
        }
    }
    $_SESSION['vehicle_connector_oauth'][$state] = [
        'user_id' => (int)$me['id'],
        'vehicle_id' => $vehicleId,
        'provider' => $provider,
        'nonce' => $nonce,
        'created_at' => $now,
    ];

    $url = $connector->authorizationUrl($state, $nonce);
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        unset($_SESSION['vehicle_connector_oauth'][$state]);
        throw new RuntimeException('OAuth autorizační URL není bezpečně nakonfigurována.');
    }
    if (preg_match('/[\r\n]/', $url) === 1) {
        unset($_SESSION['vehicle_connector_oauth'][$state]);
        throw new RuntimeException('OAuth autorizační URL je neplatná.');
    }

    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
} catch (Throwable $e) {
    $app->session()->flash($e->getMessage(), 'error');
    Http::redirect('vehicles.php?edit=' . max(0, $vehicleId));
}

<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\Security\CredentialCipher;
use RuntimeException;
use Throwable;

/** Správa vozidel v rámci hierarchicky omezeného vozového parku. */
final class VehiclesController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $pdo = $this->app->pdo();
        $me = $this->app->auth()->requireVehicleManager();

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->app->session()->verifyCsrf();
                $action = (string)($_POST['action'] ?? '');
                $this->handlePost($me);
                if (strpos($action, 'connector_') === 0) {
                    Http::redirect('vehicles.php?edit=' . max(0, (int)($_POST['vehicle_id'] ?? 0)));
                }
                Http::redirect('vehicles.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('vehicles.php');
        }

        $vehicles = $this->app->auth()->allowedVehicles($me);
        foreach ($vehicles as &$vehicle) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM trips WHERE vehicle_id=?');
            $q->execute([(int)$vehicle['id']]);
            $vehicle['trip_count'] = (int)$q->fetchColumn();
            $connection = $this->app->vehicleData()->findConnectionForVehicle((int)$vehicle['id']);
            $vehicle['connector'] = $connection !== null ? $this->safeConnectorSummary($connection) : null;
            $vehicle['connector_options'] = $this->app->vehicleConnectors()->descriptorsForVehicle($vehicle);
        }
        unset($vehicle);

        if ($this->app->auth()->isAdmin($me)) {
            $users = $pdo->query("SELECT id,name,email,role FROM users WHERE active=1 AND role='manager' ORDER BY name")->fetchAll();
        } else {
            $q = $pdo->prepare("SELECT id,name,email,role FROM users WHERE active=1 AND role='user' AND parent_user_id=? ORDER BY name");
            $q->execute([(int)$me['id']]);
            $users = $q->fetchAll();
        }

        $assigned = [];
        if ($vehicles) {
            $vehicleIds = array_map(static function (array $vehicle): int {
                return (int)$vehicle['id'];
            }, $vehicles);
            $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
            $q = $pdo->prepare('SELECT user_id,vehicle_id FROM user_vehicles WHERE vehicle_id IN (' . $placeholders . ')');
            $q->execute($vehicleIds);
            foreach ($q->fetchAll() as $row) {
                $assigned[(int)$row['vehicle_id']][] = (int)$row['user_id'];
            }
        }

        $this->app->template()->render('vehicles', [
            'app' => $this->app,
            'me' => $me,
            'vehicles' => $vehicles,
            'users' => $users,
            'assigned' => $assigned,
            'connectorSecurityReady' => CredentialCipher::isConfigured(
                (string)$this->app->config()->get('vehicle_connectors.credentials_key', '')
            ),
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }

    /** @param array<string,mixed> $me */
    private function handlePost(array $me): void
    {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $vehicleId = $this->saveVehicle($action, $me);
            if (!$this->app->auth()->isAdmin($me)) {
                $this->app->userAccess()->assignCreatedVehicle(
                    $me,
                    $vehicleId,
                    (string)($_POST['assignment_effective_date'] ?? '')
                );
                $_SESSION['vehicle_id'] = $vehicleId;
            }
            $this->syncVehicleAssignments($me, $vehicleId, $_POST['user_ids'] ?? []);
            return;
        }
        if ($action === 'update') {
            $vehicleId = $this->saveVehicle($action, $me);
            $this->syncVehicleAssignments($me, $vehicleId, $_POST['user_ids'] ?? []);
            return;
        }
        if ($action === 'delete') {
            if (!$this->app->auth()->isAdmin($me)) {
                throw new RuntimeException('Vozidlo může smazat pouze administrátor.');
            }
            $this->app->vehicles()->delete((int)($_POST['id'] ?? 0));
            $this->app->session()->flash('Vozidlo a jeho související data byla smazána.');
            return;
        }
        if ($action === 'connector_connect') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $provider = trim((string)($_POST['provider'] ?? ''));
            $connector = $this->app->vehicleConnectors()->get($provider);
            $credentials = $this->connectorCredentials($connector->credentialSchema());
            $result = $this->app->vehicleConnectorService()->connect($me, $vehicleId, $provider, $credentials);
            $this->app->session()->flash(
                'Konektor byl připojen a ověřen. Uloženo ' . $result['snapshots'] . ' nové telemetry snapshoty.',
                'ok'
            );
            return;
        }
        if ($action === 'connector_sync' || $action === 'connector_test') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $result = $this->app->vehicleConnectorService()->sync($me, $vehicleId);
            $this->app->session()->flash(
                ($action === 'connector_test' ? 'Spojení je funkční. ' : 'Synchronizace dokončena. ')
                . $result['snapshots'] . ' nový snapshot, ' . $result['events'] . ' nové události.',
                'ok'
            );
            return;
        }
        if ($action === 'connector_disconnect') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $this->app->vehicleConnectorService()->disconnect($me, $vehicleId);
            $this->app->session()->flash('OEM konektor byl od vozidla odpojen. Historická telemetrie zůstala zachována.', 'ok');
            return;
        }
        throw new RuntimeException('Neznámá operace.');
    }

    /** @param array<string,mixed> $me */
    private function saveVehicle(string $action, array $me): int
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'update' && ($id <= 0 || !$this->app->auth()->canAccessVehicle($me, $id))) {
            throw new RuntimeException('Toto vozidlo nemůžete spravovat.');
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $vin = strtoupper(trim((string)($_POST['vin'] ?? '')));
        $manufacturer = strtoupper(trim((string)($_POST['manufacturer'] ?? '')));
        $powertrain = strtoupper(trim((string)($_POST['powertrain_type'] ?? 'BEV')));
        $allowedPowertrains = ['BEV', 'PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'];
        if ($name === '' || $vin === '' || $manufacturer === '') {
            throw new RuntimeException('Vyplňte název, výrobce a VIN vozidla.');
        }
        if (!in_array($powertrain, $allowedPowertrains, true)) {
            throw new RuntimeException('Neplatný typ pohonu.');
        }

        $battery = $this->decimal($_POST['battery_kwh'] ?? null);
        $nominal = $this->decimal($_POST['battery_nominal_kwh'] ?? null);
        $tank = $this->decimal($_POST['fuel_tank_l'] ?? null);
        $odometer = $this->decimal($_POST['odometer_km'] ?? null);
        $soh = $this->decimal($_POST['soh_manual_pct'] ?? null);
        $home = trim((string)($_POST['home_label'] ?? ''));
        $registrationPlate = strtoupper(trim((string)($_POST['registration_plate'] ?? '')));
        $firstRegistration = trim((string)($_POST['first_registration_date'] ?? ''));
        $acquisitionDate = trim((string)($_POST['acquisition_date'] ?? ''));
        $acquisitionPrice = $this->decimal($_POST['acquisition_price'] ?? null);
        $currentValue = $this->decimal($_POST['current_value'] ?? null);
        $defaultElectricityPrice = $this->decimal($_POST['default_electricity_price_per_kwh'] ?? null);
        $defaultEnergyCurrency = strtoupper(trim((string)($_POST['default_energy_currency'] ?? 'CZK'))) ?: 'CZK';

        if ($defaultElectricityPrice !== null && $defaultElectricityPrice < 0) {
            throw new RuntimeException('Výchozí cena elektřiny nesmí být záporná.');
        }
        if (!preg_match('/^[A-Z]{3,8}$/', $defaultEnergyCurrency)) {
            throw new RuntimeException('Neplatná měna výchozí ceny energie.');
        }

        if (in_array($powertrain, ['BEV', 'PHEV'], true) && ($battery === null || $battery <= 0)) {
            throw new RuntimeException('Pro BEV/PHEV vyplňte využitelnou kapacitu baterie.');
        }
        if ($soh !== null && ($soh < 50 || $soh > 110)) {
            throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
        }

        $values = [$name, $vin, $manufacturer, $powertrain, $battery ?: 0, $nominal ?: ($battery ?: null), $tank,
            $registrationPlate ?: null, $firstRegistration ?: null, $acquisitionDate ?: null, $acquisitionPrice,
            $currentValue, $odometer, $soh, $soh, $home ?: null, $defaultElectricityPrice, $defaultEnergyCurrency];

        if ($action === 'create') {
            $query = $this->app->pdo()->prepare(
                'INSERT INTO vehicles (name,vin,manufacturer,powertrain_type,battery_kwh,battery_nominal_kwh,fuel_tank_l,'
                . 'registration_plate,first_registration_date,acquisition_date,acquisition_price,current_value,odometer_km,'
                . 'soh_manual_pct,soh_manual_at,home_label,default_electricity_price_per_kwh,default_energy_currency) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(? IS NULL,NULL,NOW()),?,?,?)'
            );
            $query->execute($values);
            $vehicleId = (int)$this->app->pdo()->lastInsertId();
            $this->app->session()->flash('Vozidlo bylo přidáno.');
            return $vehicleId;
        }

        $values[] = $id;
        $query = $this->app->pdo()->prepare(
            'UPDATE vehicles SET name=?,vin=?,manufacturer=?,powertrain_type=?,battery_kwh=?,battery_nominal_kwh=?,fuel_tank_l=?,'
            . 'registration_plate=?,first_registration_date=?,acquisition_date=?,acquisition_price=?,current_value=?,odometer_km=?,'
            . 'soh_manual_pct=?,soh_manual_at=IF(? IS NULL,NULL,NOW()),home_label=?,default_electricity_price_per_kwh=?,'
            . 'default_energy_currency=? WHERE id=?'
        );
        $query->execute($values);
        $this->app->session()->flash('Vozidlo bylo upraveno.');
        return $id;
    }

    /** @param array<string,mixed> $me @param mixed $ids */
    private function syncVehicleAssignments(array $me, int $vehicleId, $ids): void
    {
        $requested = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
        $effectiveDate = (string)($_POST['assignment_effective_date'] ?? '');
        $pdo = $this->app->pdo();

        if ($this->app->auth()->isAdmin($me)) {
            $targets = $pdo->query("SELECT id FROM users WHERE role='manager'")->fetchAll();
        } else {
            $q = $pdo->prepare("SELECT id FROM users WHERE role='user' AND parent_user_id=?");
            $q->execute([(int)$me['id']]);
            $targets = $q->fetchAll();
        }

        $pdo->beginTransaction();
        foreach ($targets as $target) {
            $userId = (int)$target['id'];
            $q = $pdo->prepare('SELECT vehicle_id FROM user_vehicles WHERE user_id=?');
            $q->execute([$userId]);
            $vehicleIds = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
            $has = in_array($vehicleId, $vehicleIds, true);
            $wants = in_array($userId, $requested, true);
            if ($wants && !$has) {
                $vehicleIds[] = $vehicleId;
            } elseif (!$wants && $has) {
                $vehicleIds = array_values(array_diff($vehicleIds, [$vehicleId]));
            } else {
                continue;
            }
            $this->app->userAccess()->syncAssignments($me, $userId, $vehicleIds, $effectiveDate);
        }
        $pdo->commit();
    }

    /** @param array<string,mixed> $connection @return array<string,mixed> */
    private function safeConnectorSummary(array $connection): array
    {
        return [
            'id' => (int)$connection['id'],
            'provider' => (string)$connection['provider'],
            'status' => (string)$connection['status'],
            'external_name' => (string)($connection['external_name'] ?? ''),
            'credential_hint' => (string)($connection['credential_hint'] ?? ''),
            'credential_expires_at' => $connection['credential_expires_at'] ?? null,
            'last_synced_at' => $connection['last_synced_at'] ?? null,
            'next_sync_at' => $connection['next_sync_at'] ?? null,
            'retry_after_at' => $connection['retry_after_at'] ?? null,
            'rate_limit_limit' => $connection['rate_limit_limit'] ?? null,
            'rate_limit_remaining' => $connection['rate_limit_remaining'] ?? null,
            'rate_limit_reset_at' => $connection['rate_limit_reset_at'] ?? null,
            'last_error' => (string)($connection['last_error'] ?? ''),
        ];
    }

    /**
     * Načte pouze credentials deklarované konkrétním connector pluginem.
     * Neznámá pole z POSTu se do šifrovaného payloadu nedostanou.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function connectorCredentials(array $schema): array
    {
        $posted = isset($_POST['credentials']) && is_array($_POST['credentials'])
            ? $_POST['credentials']
            : [];
        $fields = isset($schema['fields']) && is_array($schema['fields']) ? $schema['fields'] : [];
        $credentials = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string)($field['name'] ?? '');
            if ($name === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
                continue;
            }
            $value = $posted[$name] ?? ($_POST[$name] ?? '');
            if (is_string($value) || is_numeric($value)) {
                $credentials[$name] = trim((string)$value);
            }
        }

        return $credentials;
    }

    /** @param mixed $value */
    private function decimal($value): ?float
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace([' ', ','], ['', '.'], $raw);
        return is_numeric($raw) ? (float)$raw : null;
    }
}

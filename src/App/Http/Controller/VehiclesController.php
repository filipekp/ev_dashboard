<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Správa vozidel a jejich přiřazení uživatelům.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
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
                $this->handlePost($me);
                Http::redirect('vehicles.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('vehicles.php');
        }

        $vehicles = $pdo->query(
            'SELECT v.*, (SELECT COUNT(*) FROM trips t WHERE t.vehicle_id=v.id) trip_count
             FROM vehicles v
             ORDER BY v.name'
        )->fetchAll();
        $users = $pdo->query(
            'SELECT id, name, email FROM users WHERE active=1 ORDER BY name'
        )->fetchAll();

        $assigned = [];
        foreach ($pdo->query('SELECT user_id, vehicle_id FROM user_vehicles') as $row) {
            $assigned[(int)$row['vehicle_id']][] = (int)$row['user_id'];
        }

        $this->app->template()->render('vehicles', [
            'app' => $this->app,
            'me' => $me,
            'vehicles' => $vehicles,
            'users' => $users,
            'assigned' => $assigned,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }

    /** @param array<string,mixed> $me */
    private function handlePost(array $me): void
    {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create' || $action === 'update') {
            $this->saveVehicle($action);
            return;
        }

        if ($action === 'assign') {
            $this->saveAssignment();
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

        throw new RuntimeException('Neznámá operace.');
    }

    private function saveVehicle(string $action): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $vin = strtoupper(trim((string)($_POST['vin'] ?? '')));
        $powertrain = strtoupper(trim((string)($_POST['powertrain_type'] ?? 'BEV')));
        $allowedPowertrains = ['BEV', 'PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'];

        if ($name === '' || $vin === '') {
            throw new RuntimeException('Vyplňte název a VIN vozidla.');
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

        if (in_array($powertrain, ['BEV', 'PHEV'], true) && ($battery === null || $battery <= 0)) {
            throw new RuntimeException('Pro BEV/PHEV vyplňte využitelnou kapacitu baterie.');
        }
        if ($soh !== null && ($soh < 50 || $soh > 110)) {
            throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
        }

        $values = [
            $name,
            $vin,
            $powertrain,
            $battery ?: 0,
            $nominal ?: ($battery ?: null),
            $tank,
            $registrationPlate ?: null,
            $firstRegistration ?: null,
            $odometer,
            $soh,
            $soh,
            $home ?: null,
        ];

        if ($action === 'create') {
            $query = $this->app->pdo()->prepare(
                'INSERT INTO vehicles
                    (name, vin, powertrain_type, battery_kwh, battery_nominal_kwh, fuel_tank_l,
                     registration_plate, first_registration_date, odometer_km, soh_manual_pct,
                     soh_manual_at, home_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL, NULL, NOW()), ?)'
            );
            $query->execute($values);
            $this->app->session()->flash('Vozidlo bylo přidáno.');
            return;
        }

        if ($id <= 0) {
            throw new RuntimeException('Vozidlo nebylo nalezeno.');
        }
        $values[] = $id;
        $query = $this->app->pdo()->prepare(
            'UPDATE vehicles
             SET name=?, vin=?, powertrain_type=?, battery_kwh=?, battery_nominal_kwh=?, fuel_tank_l=?,
                 registration_plate=?, first_registration_date=?, odometer_km=?, soh_manual_pct=?,
                 soh_manual_at=IF(? IS NULL, NULL, NOW()), home_label=?
             WHERE id=?'
        );
        $query->execute($values);
        $this->app->session()->flash('Vozidlo bylo upraveno.');
    }

    private function saveAssignment(): void
    {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $userIds = array_map('intval', $_POST['user_ids'] ?? []);
        if ($vehicleId <= 0) {
            throw new RuntimeException('Vozidlo nebylo nalezeno.');
        }

        $pdo = $this->app->pdo();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM user_vehicles WHERE vehicle_id=?')->execute([$vehicleId]);
        $insert = $pdo->prepare('INSERT INTO user_vehicles(user_id, vehicle_id) VALUES(?, ?)');
        foreach (array_unique($userIds) as $userId) {
            if ($userId > 0) {
                $insert->execute([$userId, $vehicleId]);
            }
        }
        $pdo->prepare(
            "UPDATE users u
             SET u.default_vehicle_id=NULL
             WHERE u.default_vehicle_id=?
               AND u.role='user'
               AND NOT EXISTS (
                   SELECT 1 FROM user_vehicles uv WHERE uv.user_id=u.id AND uv.vehicle_id=?
               )"
        )->execute([$vehicleId, $vehicleId]);
        $pdo->commit();
        $this->app->session()->flash('Přiřazení vozidla bylo uloženo.');
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

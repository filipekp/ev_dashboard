<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Třída DashboardController.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class DashboardController
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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'complete_new_vehicle') {
                $this->completeNewVehicle($user);
                return;
            }

            if ($action === 'create_self_vehicle') {
                $this->createSelfVehicle($user);
                return;
            }
        }

        $vehicles = $this->app->auth()->allowedVehicles($user);
        $vehicle = $this->app->auth()->selectVehicle($user);
        $flash = $this->app->session()->pullFlash();
        $showAddVehicleModal = (string)($_GET['add_vehicle'] ?? '') === '1';

        if (!$vehicle) {
            $this->app->template()->render('dashboard-empty', [
                'app' => $this->app,
                'user' => $user,
                'flash' => $flash,
                'vehicles' => $vehicles,
                'showAddVehicleModal' => $showAddVehicleModal,
            ]);
            return;
        }

        $newVehicleModal = null;
        if (
            (string)($_GET['new_vehicle'] ?? '') === '1'
            && (int)($_SESSION['new_vehicle_id'] ?? 0) === (int)$vehicle['id']
        ) {
            $newVehicleModal = $this->app->vehicles()->find((int)$vehicle['id']);
        }

        $data = $this->app->dashboard()->build($vehicle, $_GET);
        $this->app->template()->render('dashboard', array_merge($data, [
            'app' => $this->app,
            'user' => $user,
            'vehicles' => $vehicles,
            'vehicle' => $vehicle,
            'flash' => $flash,
            'newVehicleModal' => $newVehicleModal,
            'showAddVehicleModal' => $showAddVehicleModal,
            'vehiclePhoto' => $this->app->vehicleMedia()->primaryForVehicle((int)$vehicle['id']),
        ]));
    }

    /** @param array<string,mixed> $user */
    private function createSelfVehicle(array $user): void
    {
        try {
            $this->app->session()->verifyCsrf();

            $name = trim((string)($_POST['name'] ?? ''));
            $vin = strtoupper(trim((string)($_POST['vin'] ?? '')));
            $manufacturer = strtoupper(trim((string)($_POST['manufacturer'] ?? '')));
            $powertrain = strtoupper(trim((string)($_POST['powertrain_type'] ?? 'BEV')));
            $allowedManufacturers = ['SKODA', 'KIA', 'HYUNDAI', 'VOLKSWAGEN', 'AUDI', 'TESLA', 'OTHER'];
            $allowedPowertrains = ['BEV', 'PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'];

            if ($name === '' || $vin === '' || $manufacturer === '') {
                throw new RuntimeException('Vyplňte název, výrobce a VIN vozidla.');
            }
            if (!in_array($manufacturer, $allowedManufacturers, true)) {
                throw new RuntimeException('Neplatný výrobce vozidla.');
            }
            if (!in_array($powertrain, $allowedPowertrains, true)) {
                throw new RuntimeException('Neplatný typ pohonu.');
            }

            $existing = $this->app->vehicles()->findByVin($vin);
            if ($existing !== null) {
                if ($this->app->auth()->isVehicleAssignedToUser((int)$user['id'], (int)$existing['id'])) {
                    throw new RuntimeException('Vozidlo s tímto VIN už máte přiřazené ke svému účtu.');
                }
                throw new RuntimeException(
                    'Vozidlo s tímto VIN již v systému existuje. Z bezpečnostních důvodů jej nelze převzít. ' .
                    'Požádejte správce o přiřazení vozidla k vašemu účtu.'
                );
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
            if ($nominal !== null && $nominal <= 0) {
                throw new RuntimeException('Nominální kapacita baterie musí být větší než 0 kWh.');
            }
            if ($soh !== null && ($soh < 50 || $soh > 110)) {
                throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
            }

            $vehicleId = $this->app->vehicles()->createForUser((int)$user['id'], [
                'name' => $name,
                'vin' => $vin,
                'manufacturer' => $manufacturer,
                'powertrain_type' => $powertrain,
                'battery_kwh' => $battery ?: 0,
                'battery_nominal_kwh' => $nominal ?: $battery,
                'fuel_tank_l' => $tank,
                'registration_plate' => $registrationPlate ?: null,
                'first_registration_date' => $firstRegistration ?: null,
                'odometer_km' => $odometer,
                'soh_manual_pct' => $soh,
                'home_label' => $home ?: null,
            ]);

            $this->app->pdo()->prepare(
                'UPDATE users SET default_vehicle_id=COALESCE(default_vehicle_id, ?) WHERE id=?'
            )->execute([$vehicleId, (int)$user['id']]);

            $_SESSION['vehicle_id'] = $vehicleId;
            $this->app->session()->flash(
                'Vozidlo bylo založeno a přiřazeno k vašemu účtu. Nyní do něj můžete nahrát data.'
            );
            Http::redirect('index.php?vehicle_id=' . $vehicleId);
        } catch (PDOException $e) {
            $message = $e->getCode() === '23000'
                ? 'Vozidlo se nepodařilo založit, protože zadaný VIN již existuje.'
                : 'Vozidlo se nepodařilo založit.';
            $this->app->session()->flash($message, 'error');
            Http::redirect('index.php?add_vehicle=1');
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('index.php?add_vehicle=1');
        }
    }

    /** @param array<string,mixed> $user */
    private function completeNewVehicle(array $user): void
    {
        try {
            $this->app->session()->verifyCsrf();
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $sessionVehicleId = (int)($_SESSION['new_vehicle_id'] ?? 0);
            if (!$vehicleId || $vehicleId !== $sessionVehicleId || !$this->app->auth()->canAccessVehicle($user, $vehicleId)) {
                throw new RuntimeException('Údaje tohoto vozidla nelze upravit.');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $battery = (float)str_replace(',', '.', (string)($_POST['battery_kwh'] ?? '0'));
            $nominal = (float)str_replace(',', '.', (string)($_POST['battery_nominal_kwh'] ?? '0'));
            $sohRaw = trim((string)($_POST['soh_manual_pct'] ?? ''));
            $soh = $sohRaw === '' ? null : (float)str_replace(',', '.', $sohRaw);
            $home = trim((string)($_POST['home_label'] ?? ''));

            if ($name === '' || $battery <= 0 || $nominal <= 0) {
                throw new RuntimeException('Vyplňte název a kapacitu baterie.');
            }
            if ($soh !== null && ($soh < 50 || $soh > 110)) {
                throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
            }

            $query = $this->app->pdo()->prepare(
                'UPDATE vehicles
                 SET name=?, battery_kwh=?, battery_nominal_kwh=?, soh_manual_pct=?,
                     soh_manual_at=IF(? IS NULL,NULL,NOW()), home_label=?
                 WHERE id=?'
            );
            $query->execute([
                $name,
                $battery,
                $nominal,
                $soh,
                $soh,
                $home ?: null,
                $vehicleId,
            ]);

            unset($_SESSION['new_vehicle_id']);
            $this->app->session()->flash('Údaje nového vozidla byly uloženy.');
            Http::redirect('index.php?vehicle_id=' . $vehicleId);
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            Http::redirect('index.php' . ($vehicleId > 0 ? '?vehicle_id=' . $vehicleId . '&new_vehicle=1' : ''));
        }
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

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

            if ($action === 'save_trip') {
                $this->saveTrip($user);
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
            'timelineEvents' => $this->app->timeline()->build((int)$vehicle['id'], 6),
            'insights' => $this->app->insights()->build($vehicle),
        ]));
    }

    /** @param array<string,mixed> $user */
    private function saveTrip(array $user): void
    {
        try {
            $this->app->session()->verifyCsrf();
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $allowed = $this->app->auth()->allowedVehicles($user);
            $vehicle = null;
            foreach ($allowed as $candidate) {
                if ((int)$candidate['id'] === $vehicleId) {
                    $vehicle = $candidate;
                    break;
                }
            }
            if ($vehicle === null) {
                throw new RuntimeException('K tomuto vozidlu nemáte přístup.');
            }

            $startedAt = $this->dateTime((string)($_POST['started_at'] ?? ''));
            $endedAt = $this->dateTime((string)($_POST['ended_at'] ?? ''));
            if (strtotime($endedAt) < strtotime($startedAt)) {
                throw new RuntimeException('Čas ukončení jízdy nemůže být před jejím začátkem.');
            }
            $distance = max(0.0, (float)str_replace(',', '.', (string)($_POST['distance_km'] ?? '0')));
            $minutes = max(0, (int)round((strtotime($endedAt) - strtotime($startedAt)) / 60));
            $drivingMinutes = max(0, (int)($_POST['driving_minutes'] ?? $minutes));
            if ($drivingMinutes === 0) {
                $drivingMinutes = $minutes;
            }
            $consumed = $this->nullableDecimal($_POST['consumed_kwh'] ?? null);
            $avgSpeed = $this->nullableDecimal($_POST['avg_speed_kmh'] ?? null);
            if ($avgSpeed === null && $drivingMinutes > 0) {
                $avgSpeed = $distance / ($drivingMinutes / 60);
            }
            $avgConsumption = $this->nullableDecimal($_POST['avg_consumption_kwh_100'] ?? null);
            if ($avgConsumption === null && $consumed !== null && $distance > 0) {
                $avgConsumption = $consumed / $distance * 100;
            }
            $fuelConsumed = $this->nullableDecimal($_POST['fuel_consumed_l'] ?? null);
            $avgFuelConsumption = $this->nullableDecimal($_POST['avg_fuel_consumption_l_100'] ?? null);
            if ($avgFuelConsumption === null && $fuelConsumed !== null && $distance > 0) {
                $avgFuelConsumption = $fuelConsumed / $distance * 100;
            }
            if ($fuelConsumed === null && $avgFuelConsumption !== null && $distance > 0) {
                $fuelConsumed = $avgFuelConsumption * $distance / 100;
            }
            $startOdo = $this->nullableDecimal($_POST['start_odometer_km'] ?? null);
            $endOdo = $this->nullableDecimal($_POST['end_odometer_km'] ?? null);
            if ($distance <= 0 && $startOdo !== null && $endOdo !== null && $endOdo >= $startOdo) {
                $distance = $endOdo - $startOdo;
            }

            $trip = [
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'classification' => $this->nullableText($_POST['classification'] ?? null),
                'start_address' => trim((string)($_POST['start_address'] ?? '')),
                'end_address' => trim((string)($_POST['end_address'] ?? '')),
                'distance_km' => $distance,
                'start_odometer_km' => $startOdo,
                'end_odometer_km' => $endOdo,
                'driving_minutes' => $drivingMinutes,
                'travel_minutes' => max($drivingMinutes, (int)($_POST['travel_minutes'] ?? $minutes)),
                'avg_speed_kmh' => $avgSpeed,
                'consumed_kwh' => $consumed,
                'avg_consumption_kwh_100' => $avgConsumption,
                'fuel_consumed_l' => $fuelConsumed,
                'avg_fuel_consumption_l_100' => $avgFuelConsumption,
                'start_soc' => $this->nullableDecimal($_POST['start_soc'] ?? null),
                'end_soc' => $this->nullableDecimal($_POST['end_soc'] ?? null),
                'public_charging_stops' => max(0, (int)($_POST['public_charging_stops'] ?? 0)),
                'public_charge_soc_gained' => max(0.0, (float)str_replace(',', '.', (string)($_POST['public_charge_soc_gained'] ?? '0'))),
                'short_trip' => $distance <= 5 ? 1 : 0,
                'trip_note' => $this->nullableText($_POST['trip_note'] ?? null),
            ];

            $tripId = (int)($_POST['trip_id'] ?? 0);
            if ($tripId > 0) {
                $this->app->trips()->updateManualFields($vehicleId, $tripId, $trip);
                $this->app->session()->flash('Jízda byla upravena.');
            } else {
                $trip['trip_hash'] = hash('sha256', 'manual|' . $vehicleId . '|' . $startedAt . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
                $trip['source_format'] = 'manual';
                $this->app->trips()->insertManual($vehicleId, $trip);
                $this->app->session()->flash('Jízda byla přidána.');
            }
            $_SESSION['vehicle_id'] = $vehicleId;
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
        }
        Http::redirect('index.php?vehicle_id=' . (int)($_POST['vehicle_id'] ?? 0));
    }

    private function dateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new RuntimeException('Vyplňte platné datum a čas jízdy.');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /** @param mixed $value */
    private function nullableDecimal($value): ?float
    {
        $text = trim((string)$value);
        return $text === '' ? null : (float)str_replace(',', '.', $text);
    }

    /** @param mixed $value */
    private function nullableText($value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
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

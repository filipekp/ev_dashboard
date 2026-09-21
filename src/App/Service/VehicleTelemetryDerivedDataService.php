<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TripRepository;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleOperationRepository;
use PDO;
use PDOException;

/**
 * Odvozuje z posloupnosti OEM snapshotů dokončené jízdy a nabíjecí relace.
 *
 * Jízda se uzavírá až prvním snapshotem bez přírůstku tachometru. Díky tomu
 * se průběžně synchronizovaná delší cesta nerozpadá na více dílčích jízd.
 */
final class VehicleTelemetryDerivedDataService
{
    private const MOVEMENT_THRESHOLD_KM = 0.05;

    /** @var PDO */
    private $pdo;

    /** @var VehicleDataRepository */
    private $telemetry;

    /** @var TripRepository */
    private $trips;

    /** @var VehicleOperationRepository */
    private $operations;

    public function __construct(
        PDO $pdo,
        VehicleDataRepository $telemetry,
        TripRepository $trips,
        VehicleOperationRepository $operations
    ) {
        $this->pdo = $pdo;
        $this->telemetry = $telemetry;
        $this->trips = $trips;
        $this->operations = $operations;
    }

    /** @return array{trips:int,charges:int} */
    public function project(int $vehicleId, int $connectionId, string $provider, bool $snapshotInserted = true): array
    {
        $snapshots = $this->telemetry->recentTelemetry($connectionId, 80);
        if (count($snapshots) < 2) {
            return ['trips' => 0, 'charges' => 0];
        }

        $vehicle = $this->vehicleEnergySettings($vehicleId);
        $trips = $this->projectCompletedTrip($vehicleId, $connectionId, $provider, $vehicle, $snapshots, $snapshotInserted);
        $charges = $this->projectCompletedCharge($vehicleId, $connectionId, $provider, $vehicle, $snapshots);

        return ['trips' => $trips, 'charges' => $charges];
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<int,array<string,mixed>> $snapshots
     */
    private function projectCompletedTrip(
        int $vehicleId,
        int $connectionId,
        string $provider,
        array $vehicle,
        array $snapshots,
        bool $snapshotInserted
    ): int {
        $count = count($snapshots);
        if ($count < 2) {
            return 0;
        }

        $currentDelta = $this->odometerDelta($snapshots[0], $snapshots[1]);
        if (!$snapshotInserted && $currentDelta > self::MOVEMENT_THRESHOLD_KM) {
            // Stejný nejnovější stav přišel opakovaně: vozidlo je stabilní a
            // pohybový blok končící snapshotem 0 můžeme bezpečně uzavřít.
            $endIndex = 0;
            $startIndex = 0;
        } else {
            if ($count < 3 || $currentDelta > self::MOVEMENT_THRESHOLD_KM) {
                return 0;
            }
            if ($this->odometerDelta($snapshots[1], $snapshots[2]) <= self::MOVEMENT_THRESHOLD_KM) {
                return 0;
            }
            // Aktuální snapshot stojí, předchozí byl koncem pohybového bloku.
            $endIndex = 1;
            $startIndex = 1;
        }
        while (isset($snapshots[$startIndex + 1])
            && $this->odometerDelta($snapshots[$startIndex], $snapshots[$startIndex + 1]) > self::MOVEMENT_THRESHOLD_KM) {
            $startIndex++;
        }

        $start = $snapshots[$startIndex];
        $end = $snapshots[$endIndex];
        $startOdo = $this->number($start['odometer_km'] ?? null);
        $endOdo = $this->number($end['odometer_km'] ?? null);
        if ($startOdo === null || $endOdo === null || $endOdo <= $startOdo) {
            return 0;
        }

        $distance = round($endOdo - $startOdo, 2);
        if ($distance < self::MOVEMENT_THRESHOLD_KM || $distance > 1500) {
            return 0;
        }

        $startAt = (string)$start['observed_at'];
        $endAt = (string)$end['observed_at'];
        $intervalMinutes = max(1, (int)round((strtotime($endAt) - strtotime($startAt)) / 60));
        $travelMinutes = min(180, $intervalMinutes);
        $avgSpeed = $travelMinutes > 0 ? round($distance / ($travelMinutes / 60), 2) : null;

        $startSoc = $this->number($start['soc_pct'] ?? null);
        $endSoc = $this->number($end['soc_pct'] ?? null);
        $batteryKwh = $this->number($vehicle['battery_kwh'] ?? null);
        $containsCharging = false;
        for ($i = $endIndex; $i <= $startIndex; $i++) {
            if ((int)($snapshots[$i]['is_charging'] ?? 0) === 1) {
                $containsCharging = true;
                break;
            }
        }

        $consumed = null;
        $avgConsumption = null;
        if (!$containsCharging && $batteryKwh !== null && $batteryKwh > 0 && $startSoc !== null && $endSoc !== null) {
            $socDrop = $startSoc - $endSoc;
            if ($socDrop > 0 && $socDrop <= 60) {
                $consumed = round($batteryKwh * $socDrop / 100, 3);
                $avgConsumption = $distance > 0 ? round($consumed * 100 / $distance, 2) : null;
            }
        }

        $trip = [
            'trip_hash' => hash('sha256', implode('|', [
                'telemetry',
                (string)$connectionId,
                (string)$start['id'],
                (string)$end['id'],
                (string)$startOdo,
                (string)$endOdo,
            ])),
            'started_at' => $startAt,
            'ended_at' => $endAt,
            'classification' => null,
            'trip_note' => 'Automaticky odvozeno z OEM telemetrie; čas je omezen přesností intervalu synchronizace.',
            'start_address' => '',
            'end_address' => '',
            'start_lat' => $this->number($start['latitude'] ?? null),
            'start_lng' => $this->number($start['longitude'] ?? null),
            'end_lat' => $this->number($end['latitude'] ?? null),
            'end_lng' => $this->number($end['longitude'] ?? null),
            'distance_km' => $distance,
            'start_odometer_km' => $startOdo,
            'end_odometer_km' => $endOdo,
            'driving_minutes' => $travelMinutes,
            'travel_minutes' => $travelMinutes,
            'avg_speed_kmh' => $avgSpeed,
            'consumed_kwh' => $consumed,
            'avg_consumption_kwh_100' => $avgConsumption,
            'fuel_consumed_l' => null,
            'avg_fuel_consumption_l_100' => null,
            'start_soc' => $startSoc,
            'end_soc' => $endSoc,
            'public_charging_stops' => 0,
            'public_charge_soc_gained' => 0,
            'short_trip' => $distance <= 5 ? 1 : 0,
            'source_format' => substr('telemetry_' . $provider, 0, 32),
            'total_cost' => null,
            'total_cost_currency' => null,
            'electricity_cost' => null,
            'electricity_cost_currency' => null,
            'electricity_price_per_kwh' => null,
            'avg_aux_consumption_kwh_100' => null,
            'avg_recuperation_kwh_100' => null,
        ];

        return $this->trips->insertIgnore($vehicleId, $trip) ? 1 : 0;
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<int,array<string,mixed>> $snapshots
     */
    private function projectCompletedCharge(
        int $vehicleId,
        int $connectionId,
        string $provider,
        array $vehicle,
        array $snapshots
    ): int {
        if ((int)($snapshots[0]['is_charging'] ?? 0) === 1 || (int)($snapshots[1]['is_charging'] ?? 0) !== 1) {
            return 0;
        }

        $startIndex = 1;
        while (isset($snapshots[$startIndex + 1]) && (int)($snapshots[$startIndex + 1]['is_charging'] ?? 0) === 1) {
            $startIndex++;
        }

        $start = $snapshots[$startIndex];
        $end = $snapshots[0];
        $startSoc = $this->number($start['soc_pct'] ?? null);
        if (isset($snapshots[$startIndex + 1])) {
            $before = $snapshots[$startIndex + 1];
            $beforeSoc = $this->number($before['soc_pct'] ?? null);
            $gap = strtotime((string)$start['observed_at']) - strtotime((string)$before['observed_at']);
            if ((int)($before['is_charging'] ?? 0) !== 1
                && $beforeSoc !== null
                && $gap >= 0
                && $gap <= 7200
                && $this->odometerDelta($start, $before) <= self::MOVEMENT_THRESHOLD_KM) {
                $startSoc = $beforeSoc;
            }
        }
        $endSoc = $this->number($end['soc_pct'] ?? null);
        $batteryKwh = $this->number($vehicle['battery_kwh'] ?? null);
        $quantity = null;
        $estimateMethod = '';

        if ($batteryKwh !== null && $batteryKwh > 0 && $startSoc !== null && $endSoc !== null && $endSoc > $startSoc) {
            $quantity = round($batteryKwh * ($endSoc - $startSoc) / 100, 3);
            $estimateMethod = 'změny SoC a využitelné kapacity baterie';
        } else {
            $quantity = $this->integratedChargingEnergy($snapshots, $startIndex);
            if ($quantity !== null) {
                $estimateMethod = 'průběhu nabíjecího výkonu';
            }
        }

        if ($quantity === null || $quantity <= 0.05) {
            return 0;
        }

        $defaultPrice = $this->number($vehicle['default_electricity_price_per_kwh'] ?? null);
        $currency = trim((string)($vehicle['default_energy_currency'] ?? 'CZK')) ?: 'CZK';
        $totalPrice = $defaultPrice !== null ? round($quantity * $defaultPrice, 2) : null;
        $source = substr('telemetry_' . $provider, 0, 40);
        $sourceKey = hash('sha256', implode('|', [
            'charge',
            (string)$connectionId,
            (string)$start['id'],
            (string)$end['id'],
            (string)round($quantity, 2),
        ]));

        try {
            $this->operations->addEnergyEntry($vehicleId, [
                'occurred_at' => (string)$start['observed_at'],
                'ended_at' => (string)$end['observed_at'],
                'entry_type' => 'charging',
                'energy_type' => 'electricity',
                'quantity' => $quantity,
                'unit' => 'kWh',
                'unit_price' => $defaultPrice,
                'total_price' => $totalPrice,
                'currency' => strtoupper($currency),
                'odometer_km' => $this->number($end['odometer_km'] ?? $start['odometer_km'] ?? null),
                'start_soc' => $startSoc,
                'end_soc' => $endSoc,
                'station' => $this->locationLabel($start),
                'note' => 'Automaticky z OEM telemetrie; energie je odhad z ' . $estimateMethod . '.',
                'source' => $source,
                'source_key' => $sourceKey,
                'is_estimated' => true,
                'price_source' => $defaultPrice !== null ? 'default' : 'none',
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return 0;
            }
            throw $e;
        }

        return 1;
    }

    /** @param array<int,array<string,mixed>> $snapshots */
    private function integratedChargingEnergy(array $snapshots, int $startIndex): ?float
    {
        $ordered = array_reverse(array_slice($snapshots, 0, $startIndex + 1));
        $energy = 0.0;
        $segments = 0;
        for ($i = 0, $count = count($ordered) - 1; $i < $count; $i++) {
            $a = $ordered[$i];
            $b = $ordered[$i + 1];
            $power = $this->number($a['charging_power_kw'] ?? null);
            if ($power === null || $power <= 0) {
                continue;
            }
            $seconds = strtotime((string)$b['observed_at']) - strtotime((string)$a['observed_at']);
            if ($seconds <= 0 || $seconds > 5400) {
                continue;
            }
            $energy += $power * ($seconds / 3600);
            $segments++;
        }

        return $segments > 0 ? round($energy, 3) : null;
    }

    /** @param array<string,mixed> $newer @param array<string,mixed> $older */
    private function odometerDelta(array $newer, array $older): float
    {
        $a = $this->number($newer['odometer_km'] ?? null);
        $b = $this->number($older['odometer_km'] ?? null);
        if ($a === null || $b === null || $a < $b) {
            return 0.0;
        }
        return $a - $b;
    }

    /** @return array<string,mixed> */
    private function vehicleEnergySettings(int $vehicleId): array
    {
        $query = $this->pdo->prepare(
            'SELECT battery_kwh,default_electricity_price_per_kwh,default_energy_currency FROM vehicles WHERE id=? LIMIT 1'
        );
        $query->execute([$vehicleId]);
        return $query->fetch() ?: [];
    }

    /** @param array<string,mixed> $snapshot */
    private function locationLabel(array $snapshot): ?string
    {
        $lat = $this->number($snapshot['latitude'] ?? null);
        $lng = $this->number($snapshot['longitude'] ?? null);
        if ($lat === null || $lng === null) {
            return null;
        }
        return sprintf('GPS %.5f, %.5f', $lat, $lng);
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}

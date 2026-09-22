<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TripRepository;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleOperationRepository;
use PDO;

/**
 * Odvozuje z posloupnosti OEM snapshotů dokončené jízdy a nabíjecí relace.
 *
 * Jízda začíná prvním zaznamenaným přírůstkem tachometru. Za ukončenou se
 * považuje až po dvou hodinách bez dalšího přírůstku, takže krátké zastávky
 * nerozdělují jednu cestu na více záznamů.
 */
final class VehicleTelemetryDerivedDataService
{
    private const MOVEMENT_THRESHOLD_KM = 0.05;
    private const TRIP_IDLE_SECONDS = 7200;
    private const CHARGE_CONTEXT_SECONDS = 7200;

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
        // 240 snapshotů při běžném 5–15min intervalu pokryje nejen 2hodinovou
        // potvrzovací periodu, ale i delší cestu a její parkovací kontext.
        $snapshots = $this->telemetry->recentTelemetry($connectionId, 240);
        if (count($snapshots) < 2) {
            return ['trips' => 0, 'charges' => 0];
        }

        $snapshots = $this->chronological($snapshots);
        $vehicle = $this->vehicleEnergySettings($vehicleId);
        $trips = $this->projectCompletedTrip($vehicleId, $connectionId, $provider, $vehicle, $snapshots);
        $charges = $this->projectChargingSession($vehicleId, $connectionId, $provider, $vehicle, $snapshots);

        return ['trips' => $trips, 'charges' => $charges];
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<int,array<string,mixed>> $snapshots Chronologicky od nejstaršího.
     */
    private function projectCompletedTrip(
        int $vehicleId,
        int $connectionId,
        string $provider,
        array $vehicle,
        array $snapshots
    ): int {
        $movements = [];
        for ($i = 1, $count = count($snapshots); $i < $count; $i++) {
            if ($this->odometerDelta($snapshots[$i], $snapshots[$i - 1]) <= self::MOVEMENT_THRESHOLD_KM) {
                continue;
            }
            $movements[] = [
                'older_index' => $i - 1,
                'newer_index' => $i,
                'received_at' => $this->receivedTimestamp($snapshots[$i]),
            ];
        }

        if ($movements === []) {
            return 0;
        }

        $lastMovementIndex = count($movements) - 1;
        $lastMovement = $movements[$lastMovementIndex];
        $lastMovementReceived = (int)$lastMovement['received_at'];
        if ($lastMovementReceived <= 0 || time() - $lastMovementReceived <= self::TRIP_IDLE_SECONDS) {
            return 0;
        }

        // Přírůstky tachometru patří do stejné jízdy, dokud mezi nimi není
        // potvrzená alespoň dvouhodinová mezera bez změny odometru.
        $firstMovementIndex = $lastMovementIndex;
        while ($firstMovementIndex > 0) {
            $current = $movements[$firstMovementIndex];
            $previous = $movements[$firstMovementIndex - 1];
            if ($this->hasConfirmedTripIdleBetween($snapshots, $previous, $current)) {
                break;
            }
            $firstMovementIndex--;
        }

        $firstMovement = $movements[$firstMovementIndex];
        $originIndex = (int)$firstMovement['older_index'];
        $firstMovementSnapshotIndex = (int)$firstMovement['newer_index'];
        $lastMovementSnapshotIndex = (int)$lastMovement['newer_index'];

        $origin = $this->bestParkingSnapshotAround($snapshots, $originIndex, -1);
        $start = $snapshots[$originIndex];
        $lastMovementSnapshot = $snapshots[$lastMovementSnapshotIndex];
        $endOdo = $this->number($lastMovementSnapshot['odometer_km'] ?? null);
        if ($endOdo === null) {
            return 0;
        }

        $destinationIndex = $lastMovementSnapshotIndex;
        $endTimeIndex = $lastMovementSnapshotIndex;
        for ($i = $lastMovementSnapshotIndex + 1, $count = count($snapshots); $i < $count; $i++) {
            $odometer = $this->number($snapshots[$i]['odometer_km'] ?? null);
            if ($odometer === null || abs($odometer - $endOdo) > self::MOVEMENT_THRESHOLD_KM) {
                break;
            }
            if ($endTimeIndex === $lastMovementSnapshotIndex) {
                $endTimeIndex = $i;
            }
            if ($this->hasParkingData($snapshots[$i])) {
                $destinationIndex = $i;
            }
        }
        $destination = $this->bestParkingSnapshotAround($snapshots, $destinationIndex, 1);

        $startOdo = $this->number($start['odometer_km'] ?? null);
        if ($startOdo === null || $endOdo <= $startOdo) {
            return 0;
        }

        $distance = round($endOdo - $startOdo, 2);
        if ($distance < self::MOVEMENT_THRESHOLD_KM || $distance > 1500) {
            return 0;
        }

        $startAt = $this->dateTimeValue($start, 'observed_at');
        $endAt = $this->dateTimeValue($snapshots[$endTimeIndex], 'observed_at');
        if (strtotime($endAt) < strtotime($startAt)) {
            $startAt = $this->dateTimeValue($snapshots[$firstMovementSnapshotIndex], 'observed_at');
            $endAt = $this->dateTimeValue($lastMovementSnapshot, 'observed_at');
        }
        $intervalMinutes = max(1, (int)round((strtotime($endAt) - strtotime($startAt)) / 60));
        $travelMinutes = min(1440, $intervalMinutes);
        $avgSpeed = $travelMinutes > 0 ? round($distance / ($travelMinutes / 60), 2) : null;

        $startSoc = $this->number($origin['soc_pct'] ?? $start['soc_pct'] ?? null);
        $endSoc = $this->number($destination['soc_pct'] ?? $lastMovementSnapshot['soc_pct'] ?? null);
        $batteryKwh = $this->number($vehicle['battery_kwh'] ?? null);
        $containsCharging = false;
        for ($i = $originIndex; $i <= $destinationIndex; $i++) {
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
                (string)$snapshots[$firstMovementSnapshotIndex]['id'],
                (string)$lastMovementSnapshot['id'],
                (string)$startOdo,
                (string)$endOdo,
            ])),
            'started_at' => $startAt,
            'ended_at' => $endAt,
            'classification' => null,
            'trip_note' => 'Automaticky odvozeno z OEM telemetrie; ukončení potvrzeno po 2 hodinách bez změny tachometru.',
            'start_address' => $this->parkingAddress($origin),
            'end_address' => $this->parkingAddress($destination),
            'start_lat' => $this->number($origin['latitude'] ?? null),
            'start_lng' => $this->number($origin['longitude'] ?? null),
            'end_lat' => $this->number($destination['latitude'] ?? null),
            'end_lng' => $this->number($destination['longitude'] ?? null),
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
     * Průběžně vytváří a aktualizuje poslední OEM nabíjecí relaci. Záznam tak
     * vznikne už při zahájení nabíjení a je okamžitě vidět v historii energie.
     *
     * @param array<string,mixed> $vehicle
     * @param array<int,array<string,mixed>> $snapshots Chronologicky od nejstaršího.
     */
    private function projectChargingSession(
        int $vehicleId,
        int $connectionId,
        string $provider,
        array $vehicle,
        array $snapshots
    ): int {
        $lastChargingIndex = null;
        for ($i = count($snapshots) - 1; $i >= 0; $i--) {
            if ((int)($snapshots[$i]['is_charging'] ?? 0) === 1) {
                $lastChargingIndex = $i;
                break;
            }
        }
        if ($lastChargingIndex === null) {
            return 0;
        }

        $startIndex = $lastChargingIndex;
        while ($startIndex > 0 && (int)($snapshots[$startIndex - 1]['is_charging'] ?? 0) === 1) {
            $startIndex--;
        }
        $chargeEndIndex = $lastChargingIndex;
        while (isset($snapshots[$chargeEndIndex + 1]) && (int)($snapshots[$chargeEndIndex + 1]['is_charging'] ?? 0) === 1) {
            $chargeEndIndex++;
        }

        $latestIndex = count($snapshots) - 1;
        $ongoing = (int)($snapshots[$latestIndex]['is_charging'] ?? 0) === 1
            && $lastChargingIndex === $latestIndex;
        $endIndex = $ongoing ? $latestIndex : min($latestIndex, $chargeEndIndex + 1);

        $start = $snapshots[$startIndex];
        $end = $snapshots[$endIndex];
        $startSoc = $this->number($start['soc_pct'] ?? null);
        if ($startIndex > 0) {
            $before = $snapshots[$startIndex - 1];
            $beforeSoc = $this->number($before['soc_pct'] ?? null);
            $gap = $this->receivedTimestamp($start) - $this->receivedTimestamp($before);
            if ((int)($before['is_charging'] ?? 0) !== 1
                && $beforeSoc !== null
                && $gap >= 0
                && $gap <= self::CHARGE_CONTEXT_SECONDS
                && $this->odometerDelta($start, $before) <= self::MOVEMENT_THRESHOLD_KM) {
                $startSoc = $beforeSoc;
            }
        }

        $endSoc = $this->number($end['soc_pct'] ?? $snapshots[$lastChargingIndex]['soc_pct'] ?? null);
        $batteryKwh = $this->number($vehicle['battery_kwh'] ?? null);
        $quantity = 0.0;
        $estimateMethod = 'čeká na první měřitelný přírůstek energie';

        if ($batteryKwh !== null && $batteryKwh > 0 && $startSoc !== null && $endSoc !== null && $endSoc > $startSoc) {
            $quantity = round($batteryKwh * ($endSoc - $startSoc) / 100, 3);
            $estimateMethod = 'změny SoC a využitelné kapacity baterie';
        } else {
            $integrated = $this->integratedChargingEnergy($snapshots, $startIndex, $endIndex);
            if ($integrated !== null) {
                $quantity = $integrated;
                $estimateMethod = 'průběhu nabíjecího výkonu';
            }
        }

        $defaultPrice = $this->number($vehicle['default_electricity_price_per_kwh'] ?? null);
        $currency = trim((string)($vehicle['default_energy_currency'] ?? 'CZK')) ?: 'CZK';
        $totalPrice = $defaultPrice !== null ? round($quantity * $defaultPrice, 2) : null;
        $source = substr('telemetry_' . $provider, 0, 40);
        $sourceKey = hash('sha256', implode('|', [
            'charge',
            (string)$connectionId,
            (string)$start['id'],
        ]));

        $station = $this->parkingAddress($this->bestParkingSnapshotAround($snapshots, $startIndex, -1));
        if ($station === '') {
            $station = $this->locationLabel($start);
        }
        $note = $ongoing
            ? 'Probíhající nabíjení z OEM telemetrie; energie je průběžný odhad z ' . $estimateMethod . '.'
            : 'Automaticky z OEM telemetrie; energie je odhad z ' . $estimateMethod . '.';

        $created = $this->operations->upsertTelemetryEnergyEntry($vehicleId, [
            'occurred_at' => $this->dateTimeValue($start, 'observed_at'),
            'ended_at' => $ongoing ? null : $this->dateTimeValue($end, 'observed_at'),
            'entry_type' => 'charging',
            'energy_type' => 'electricity',
            'quantity' => max(0.0, $quantity),
            'unit' => 'kWh',
            'unit_price' => $defaultPrice,
            'total_price' => $totalPrice,
            'currency' => strtoupper($currency),
            'odometer_km' => $this->number($end['odometer_km'] ?? $start['odometer_km'] ?? null),
            'start_soc' => $startSoc,
            'end_soc' => $endSoc,
            'station' => $station,
            'note' => $note,
            'source' => $source,
            'source_key' => $sourceKey,
            'is_estimated' => true,
            'price_source' => $defaultPrice !== null ? 'default' : 'none',
        ]);

        return $created ? 1 : 0;
    }

    /**
     * @param array<int,array<string,mixed>> $snapshots
     */
    private function integratedChargingEnergy(array $snapshots, int $startIndex, int $endIndex): ?float
    {
        $energy = 0.0;
        $segments = 0;
        for ($i = $startIndex; $i < $endIndex; $i++) {
            $a = $snapshots[$i];
            $b = $snapshots[$i + 1];
            if ((int)($a['is_charging'] ?? 0) !== 1) {
                continue;
            }
            $power = $this->number($a['charging_power_kw'] ?? null);
            if ($power === null || $power <= 0) {
                continue;
            }
            $seconds = $this->receivedTimestamp($b) - $this->receivedTimestamp($a);
            if ($seconds <= 0 || $seconds > 5400) {
                continue;
            }
            $energy += $power * ($seconds / 3600);
            $segments++;
        }

        return $segments > 0 ? round($energy, 3) : null;
    }

    /** @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function chronological(array $snapshots): array
    {
        usort($snapshots, function (array $a, array $b): int {
            $time = $this->receivedTimestamp($a) <=> $this->receivedTimestamp($b);
            if ($time !== 0) {
                return $time;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });
        return $snapshots;
    }

    /**
     * Najde nejvhodnější snapshot se stejným tachometrem a parkovací polohou.
     * Směr -1 hledá k minulosti (start), +1 k budoucnosti (cíl).
     *
     * @param array<int,array<string,mixed>> $snapshots
     * @return array<string,mixed>
     */
    private function bestParkingSnapshotAround(array $snapshots, int $index, int $direction): array
    {
        $base = $snapshots[$index];
        $baseOdo = $this->number($base['odometer_km'] ?? null);
        if ($baseOdo === null) {
            return $base;
        }
        $best = $base;
        if ($direction < 0 && $this->hasParkingData($base)) {
            return $base;
        }
        for ($i = $index + $direction; isset($snapshots[$i]); $i += $direction) {
            $odometer = $this->number($snapshots[$i]['odometer_km'] ?? null);
            if ($odometer === null || abs($odometer - $baseOdo) > self::MOVEMENT_THRESHOLD_KM) {
                break;
            }
            if ($this->hasParkingData($snapshots[$i])) {
                $best = $snapshots[$i];
                // Pro start chceme nejbližší předodjezdový bod, pro cíl naopak
                // nejnovější potvrzenou parkovací pozici na koncovém odometru.
                if ($direction < 0) {
                    break;
                }
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $snapshot */
    private function hasParkingData(array $snapshot): bool
    {
        return $this->parkingAddress($snapshot) !== ''
            || ($this->number($snapshot['latitude'] ?? null) !== null && $this->number($snapshot['longitude'] ?? null) !== null);
    }

    /** @param array<string,mixed> $snapshot */
    private function parkingAddress(array $snapshot): string
    {
        $address = trim((string)($snapshot['parking_address'] ?? ''));
        if ($address !== '') {
            return $address;
        }

        // Starší snapshoty před migrací v23 mají parkingPosition pouze v raw_json.
        // Fallback umožní doplnit Odkud/Kam i bez databázového backfillu.
        $rawJson = (string)($snapshot['raw_json'] ?? '');
        if ($rawJson === '') {
            return '';
        }
        $raw = json_decode($rawJson, true);
        if (!is_array($raw)) {
            return '';
        }
        $vehicle = isset($raw['vehicle']) && is_array($raw['vehicle']) ? $raw['vehicle'] : $raw;
        $parking = isset($vehicle['parkingPosition']) && is_array($vehicle['parkingPosition'])
            ? $vehicle['parkingPosition']
            : [];
        return trim((string)($parking['formattedAddress'] ?? ''));
    }

    /**
     * Dvouhodinovou mezeru mezi dvěma přírůstky považujeme za konec jízdy jen
     * tehdy, pokud telemetrie skutečně obsahuje alespoň jeden pozdější snapshot
     * se stejným odometrem. Samotný výpadek synchronizace tak jízdu nerozdělí.
     *
     * @param array<int,array<string,mixed>> $snapshots
     * @param array<string,mixed> $previousMovement
     * @param array<string,mixed> $currentMovement
     */
    private function hasConfirmedTripIdleBetween(
        array $snapshots,
        array $previousMovement,
        array $currentMovement
    ): bool {
        $previousIndex = (int)$previousMovement['newer_index'];
        $currentOlderIndex = (int)$currentMovement['older_index'];
        $plateauOdometer = $this->number($snapshots[$previousIndex]['odometer_km'] ?? null);
        $plateauStartedAt = (int)$previousMovement['received_at'];
        if ($plateauOdometer === null || $plateauStartedAt <= 0) {
            return false;
        }

        for ($i = $previousIndex + 1; $i <= $currentOlderIndex && isset($snapshots[$i]); $i++) {
            $odometer = $this->number($snapshots[$i]['odometer_km'] ?? null);
            if ($odometer === null || abs($odometer - $plateauOdometer) > self::MOVEMENT_THRESHOLD_KM) {
                continue;
            }
            $receivedAt = $this->receivedTimestamp($snapshots[$i]);
            if ($receivedAt > 0 && $receivedAt - $plateauStartedAt > self::TRIP_IDLE_SECONDS) {
                return true;
            }
        }

        return false;
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

    /** @param array<string,mixed> $snapshot */
    private function receivedTimestamp(array $snapshot): int
    {
        $value = trim((string)($snapshot['received_at'] ?? $snapshot['observed_at'] ?? ''));
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp !== false ? $timestamp : 0;
    }

    /** @param array<string,mixed> $snapshot */
    private function dateTimeValue(array $snapshot, string $key): string
    {
        $value = trim((string)($snapshot[$key] ?? ''));
        if ($value !== '' && strtotime($value) !== false) {
            return date('Y-m-d H:i:s', (int)strtotime($value));
        }
        $fallback = $this->receivedTimestamp($snapshot);
        return $fallback > 0 ? date('Y-m-d H:i:s', $fallback) : date('Y-m-d H:i:s');
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
        $address = $this->parkingAddress($snapshot);
        if ($address !== '') {
            return $address;
        }
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

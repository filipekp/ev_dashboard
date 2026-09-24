<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TripRepository;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleOperationRepository;
use PDO;

/**
 * Odvozuje z posloupnosti OEM snapshotů živé jízdy a nabíjecí relace.
 *
 * Jízda vzniká už při prvním zaznamenaném přírůstku tachometru, během dalších
 * synchronizací se aktualizuje a po dvou hodinách bez dalšího přírůstku se
 * uzavře. Krátké zastávky proto nerozdělují jednu cestu na více záznamů.
 */
final class VehicleTelemetryDerivedDataService
{
    private const MOVEMENT_THRESHOLD_KM = 0.05;
    private const TRIP_IDLE_SECONDS = 7200;
    private const CHARGE_CONTEXT_SECONDS = 7200;
    private const MAX_PLAUSIBLE_SPEED_KMH = 300.0;

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

    /** @return array{trips:int,charges:int,events:int} */
    public function project(int $vehicleId, int $connectionId, string $provider, bool $snapshotInserted = true): array
    {
        // 240 snapshotů při běžném 5–15min intervalu pokryje nejen 2hodinovou
        // potvrzovací periodu, ale i delší cestu a její parkovací kontext.
        $snapshots = $this->telemetry->recentTelemetry($connectionId, 240);
        if ($snapshots === []) {
            return ['trips' => 0, 'charges' => 0, 'events' => 0];
        }

        $snapshots = $this->chronological($snapshots);
        $vehicle = $this->vehicleEnergySettings($vehicleId);
        $tripResult = $this->projectTripLifecycle($vehicleId, $connectionId, $provider, $vehicle, $snapshots);
        $charges = count($snapshots) >= 2
            ? $this->projectChargingSession($vehicleId, $connectionId, $provider, $vehicle, $snapshots)
            : 0;

        return [
            'trips' => (int)$tripResult['trips'],
            'charges' => $charges,
            'events' => (int)$tripResult['events'],
        ];
    }

    /**
     * Živá jízda vzniká už při prvním přírůstku odometru. Každý další sync ji
     * aktualizuje. Po dvou hodinách bez změny odometru se stejný řádek uzavře.
     *
     * @param array<string,mixed> $vehicle
     * @param array<int,array<string,mixed>> $snapshots Chronologicky od nejstaršího.
     * @return array{trips:int,events:int}
     */
    private function projectTripLifecycle(
        int $vehicleId,
        int $connectionId,
        string $provider,
        array $vehicle,
        array $snapshots
    ): array {
        $tripsChanged = 0;
        $eventsCreated = 0;
        $active = $this->trips->findActiveTelemetryTrip($vehicleId, $connectionId);
        $latest = $active !== null
            ? $active
            : $this->trips->findLatestTelemetryTrip($vehicleId, $connectionId);
        $processedSnapshotId = $latest !== null
            ? (int)($latest['telemetry_last_snapshot_id'] ?? 0)
            : 0;
        $movements = $this->movementSegments($snapshots);
        $newMovements = [];

        foreach ($movements as $movement) {
            $newerIndex = (int)$movement['newer_index'];
            $snapshotId = (int)($snapshots[$newerIndex]['id'] ?? 0);
            if ($processedSnapshotId > 0 && $snapshotId <= $processedSnapshotId) {
                continue;
            }
            $newMovements[] = $movement;
        }

        // Bez nového přírůstku pouze udržujeme cíl živé jízdy a hlídáme timeout.
        if ($newMovements === []) {
            if ($active === null) {
                return ['trips' => 0, 'events' => 0];
            }

            $lastMovementAt = strtotime((string)($active['telemetry_last_movement_at'] ?? '')) ?: 0;
            if ($lastMovementAt > 0 && time() - $lastMovementAt > self::TRIP_IDLE_SECONDS) {
                $finalPayload = $this->finalPayloadFromActive($active, $snapshots);
                $final = $this->trips->finalizeTelemetryTrip(
                    $vehicleId,
                    (int)$active['id'],
                    $finalPayload
                );
                $eventsCreated += $this->insertTripCompletedEvent(
                    $vehicleId,
                    $connectionId,
                    $provider,
                    (int)$final['id'],
                    $finalPayload
                );
                $tripsChanged++;
            } else {
                $this->refreshActiveDestination($vehicleId, $active, $snapshots);
            }

            return ['trips' => $tripsChanged, 'events' => $eventsCreated];
        }

        foreach ($newMovements as $movement) {
            if ($active !== null && $this->movementStartsNewTrip($active, $movement, $snapshots)) {
                $finalPayload = $this->finalPayloadFromActive($active, $snapshots);
                $final = $this->trips->finalizeTelemetryTrip(
                    $vehicleId,
                    (int)$active['id'],
                    $finalPayload
                );
                $eventsCreated += $this->insertTripCompletedEvent(
                    $vehicleId,
                    $connectionId,
                    $provider,
                    (int)$final['id'],
                    $finalPayload
                );
                $tripsChanged++;
                $active = null;
            }

            $singleTrip = $this->buildTripFromMovements(
                $connectionId,
                $provider,
                $vehicle,
                $snapshots,
                [$movement],
                0,
                0
            );
            if ($singleTrip === null) {
                continue;
            }

            if ($active !== null) {
                $singleTrip = $this->preserveActiveTripStart($active, $singleTrip, $vehicle);
                $this->trips->updateActiveTelemetryTrip($vehicleId, (int)$active['id'], $singleTrip);
                $active = array_merge($active, $singleTrip);
                $tripsChanged++;
                continue;
            }

            $created = $this->trips->createActiveTelemetryTrip($vehicleId, $singleTrip);
            if (!empty($created['completed'])) {
                // Hash už patří dokončenému záznamu. Nesmíme jej znovu otevřít.
                continue;
            }

            $active = array_merge($singleTrip, [
                'id' => (int)$created['id'],
                'vehicle_id' => $vehicleId,
            ]);
            if (!empty($created['created'])) {
                $tripsChanged++;
                $eventsCreated += $this->insertTripStartedEvent(
                    $vehicleId,
                    $connectionId,
                    $provider,
                    (int)$created['id'],
                    $singleTrip
                );
            }
        }

        if ($active !== null) {
            $lastMovementAt = strtotime((string)($active['telemetry_last_movement_at'] ?? '')) ?: 0;
            if ($lastMovementAt > 0 && time() - $lastMovementAt > self::TRIP_IDLE_SECONDS) {
                $finalPayload = $this->finalPayloadFromActive($active, $snapshots);
                $final = $this->trips->finalizeTelemetryTrip(
                    $vehicleId,
                    (int)$active['id'],
                    $finalPayload
                );
                $eventsCreated += $this->insertTripCompletedEvent(
                    $vehicleId,
                    $connectionId,
                    $provider,
                    (int)$final['id'],
                    $finalPayload
                );
                $tripsChanged++;
            }
        }

        return ['trips' => $tripsChanged, 'events' => $eventsCreated];
    }

    /**
     * Opraví telemetry jízdy konkrétního konektoru z raw snapshotů. Používá se
     * jednorázově po migraci v26 a je idempotentní: ruční/CSV jízdy nemaže.
     *
     * @return array{trips:int,events:int}
     */
    public function rebuildTelemetryTrips(int $vehicleId, int $connectionId, string $provider): array
    {
        $snapshots = $this->telemetry->allTelemetryForConnection($connectionId);
        if ($snapshots === []) {
            return ['trips' => 0, 'events' => 0];
        }

        $snapshots = $this->chronological($snapshots);
        $vehicle = $this->vehicleEnergySettings($vehicleId);
        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->trips->deleteTelemetryTripsForConnection($vehicleId, $connectionId);
            $this->telemetry->deleteTripLifecycleEvents($connectionId);
            $result = $this->projectTripLifecycle(
                $vehicleId,
                $connectionId,
                $provider,
                $vehicle,
                $snapshots
            );

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $snapshots
     * @return array<int,array<string,mixed>>
     */
    private function movementSegments(array $snapshots): array
    {
        $movements = [];
        for ($i = 1, $count = count($snapshots); $i < $count; $i++) {
            $olderOdo = $this->number($snapshots[$i - 1]['odometer_km'] ?? null);
            $newerOdo = $this->number($snapshots[$i]['odometer_km'] ?? null);
            if ($olderOdo === null || $newerOdo === null || $newerOdo - $olderOdo <= self::MOVEMENT_THRESHOLD_KM) {
                continue;
            }
            $movements[] = [
                'older_index' => $i - 1,
                'newer_index' => $i,
                'received_at' => $this->receivedTimestamp($snapshots[$i]),
                'start_odo' => $olderOdo,
                'end_odo' => $newerOdo,
            ];
        }

        return $movements;
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<int,array<string,mixed>> $snapshots
     * @param array<int,array<string,mixed>> $movements
     * @return array<string,mixed>|null
     */
    private function buildTripFromMovements(
        int $connectionId,
        string $provider,
        array $vehicle,
        array $snapshots,
        array $movements,
        int $firstMovementIndex,
        int $lastMovementIndex
    ): ?array {
        $firstMovement = $movements[$firstMovementIndex];
        $lastMovement = $movements[$lastMovementIndex];
        $originIndex = (int)$firstMovement['older_index'];
        $firstMovementSnapshotIndex = (int)$firstMovement['newer_index'];
        $lastMovementSnapshotIndex = (int)$lastMovement['newer_index'];

        $origin = $this->bestParkingSnapshotAround($snapshots, $originIndex, -1);
        $start = $snapshots[$originIndex];
        $lastMovementSnapshot = $snapshots[$lastMovementSnapshotIndex];
        $endOdo = $this->number($lastMovementSnapshot['odometer_km'] ?? null);
        if ($endOdo === null) {
            return null;
        }

        $destinationIndex = $lastMovementSnapshotIndex;
        for ($i = $lastMovementSnapshotIndex + 1, $count = count($snapshots); $i < $count; $i++) {
            $odometer = $this->number($snapshots[$i]['odometer_km'] ?? null);
            if ($odometer === null || abs($odometer - $endOdo) > self::MOVEMENT_THRESHOLD_KM) {
                break;
            }
            if ($this->hasParkingData($snapshots[$i])) {
                $destinationIndex = $i;
            }
        }
        $destination = $this->bestParkingSnapshotAround($snapshots, $destinationIndex, 1);

        $startOdo = $this->number($start['odometer_km'] ?? null);
        if ($startOdo === null || $endOdo <= $startOdo) {
            return null;
        }

        $distance = round($endOdo - $startOdo, 2);
        if ($distance < self::MOVEMENT_THRESHOLD_KM || $distance > 1500) {
            return null;
        }

        $firstTiming = null;
        $lastTiming = null;
        for ($movementIndex = $firstMovementIndex; $movementIndex <= $lastMovementIndex; $movementIndex++) {
            $segment = $movements[$movementIndex];
            $segmentDistance = (float)$segment['end_odo'] - (float)$segment['start_odo'];
            $timing = $this->movementTiming(
                $snapshots[(int)$segment['older_index']],
                $snapshots[(int)$segment['newer_index']],
                $segmentDistance
            );
            if ($timing === null) {
                // Z velkého/nekonzistentního odometrového skoku bez věrohodného
                // časového okna nesmíme vyrobit falešnou jízdu ani rychlost v
                // tisících km/h. Takový interval raději vynecháme.
                return null;
            }
            if ($firstTiming === null) {
                $firstTiming = $timing;
            }
            $lastTiming = $timing;
        }
        if ($firstTiming === null || $lastTiming === null) {
            return null;
        }

        $startAt = (string)$firstTiming['start_at'];
        $endAt = (string)$lastTiming['end_at'];
        $intervalSeconds = max(1, (int)$lastTiming['end_ts'] - (int)$firstTiming['start_ts']);
        $travelMinutes = max(1, min(1440, (int)round($intervalSeconds / 60)));
        $avgSpeed = $this->averageSpeedOrNull($distance, $travelMinutes);
        if ($avgSpeed === null) {
            return null;
        }

        $startSoc = $this->number($origin['soc_pct'] ?? $start['soc_pct'] ?? null);
        // SoC na konci cesty bereme z posledního snapshotu, ve kterém se změnil
        // odometr. Pozdější nabíjení při stejném odometru tak spotřebu jízdy
        // zpětně nezkreslí.
        $endSoc = $this->number($lastMovementSnapshot['soc_pct'] ?? null);
        $batteryKwh = $this->number($vehicle['battery_kwh'] ?? null);
        $containsCharging = false;
        for ($i = $originIndex; $i <= $lastMovementSnapshotIndex; $i++) {
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

        return [
            // Hash živé jízdy je stabilní od prvního přírůstku a nemění se s
            // každým dalším snapshotem. canonical_key se doplní až při uzavření.
            'trip_hash' => hash('sha256', implode('|', [
                'telemetry-live',
                (string)$connectionId,
                (string)$snapshots[$firstMovementSnapshotIndex]['id'],
                (string)$startOdo,
            ])),
            'canonical_key' => null,
            'trip_state' => 'active',
            'telemetry_connection_id' => $connectionId,
            'telemetry_start_snapshot_id' => (int)($snapshots[$firstMovementSnapshotIndex]['id'] ?? 0),
            'telemetry_last_snapshot_id' => (int)($lastMovementSnapshot['id'] ?? 0),
            'telemetry_last_movement_at' => date('Y-m-d H:i:s', (int)$lastMovement['received_at']),
            'started_at' => $startAt,
            'ended_at' => $endAt,
            'classification' => null,
            'trip_note' => 'Probíhající jízda automaticky aktualizovaná z OEM telemetrie.',
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
    }

    /**
     * @param array<string,mixed> $active
     * @param array<string,mixed> $trip
     * @param array<string,mixed> $vehicle
     * @return array<string,mixed>
     */
    private function preserveActiveTripStart(array $active, array $trip, array $vehicle): array
    {
        foreach ([
            'trip_hash',
            'started_at',
            'start_address',
            'start_lat',
            'start_lng',
            'start_odometer_km',
            'start_soc',
            'telemetry_connection_id',
            'telemetry_start_snapshot_id',
        ] as $column) {
            if (array_key_exists($column, $active)) {
                $trip[$column] = $active[$column];
            }
        }
        $trip['canonical_key'] = null;
        $trip['trip_state'] = 'active';

        $startOdo = $this->number($trip['start_odometer_km'] ?? null);
        $endOdo = $this->number($trip['end_odometer_km'] ?? null);
        if ($startOdo !== null && $endOdo !== null && $endOdo > $startOdo) {
            $trip['distance_km'] = round($endOdo - $startOdo, 2);
        }

        $startAt = strtotime((string)($trip['started_at'] ?? '')) ?: 0;
        $endAt = strtotime((string)($trip['ended_at'] ?? '')) ?: 0;
        if ($startAt > 0 && $endAt >= $startAt) {
            $minutes = max(1, min(1440, (int)round(($endAt - $startAt) / 60)));
            $trip['driving_minutes'] = $minutes;
            $trip['travel_minutes'] = $minutes;
            $distance = max(0.0, (float)($trip['distance_km'] ?? 0));
            $trip['avg_speed_kmh'] = $this->averageSpeedOrNull($distance, $minutes);
        }

        $startSoc = $this->number($trip['start_soc'] ?? null);
        $endSoc = $this->number($trip['end_soc'] ?? null);
        $batteryKwh = $this->number($vehicle['battery_kwh'] ?? null);
        if ($batteryKwh !== null && $batteryKwh > 0 && $startSoc !== null && $endSoc !== null) {
            $socDrop = $startSoc - $endSoc;
            if ($socDrop > 0 && $socDrop <= 60) {
                $trip['consumed_kwh'] = round($batteryKwh * $socDrop / 100, 3);
                $distance = max(0.0, (float)($trip['distance_km'] ?? 0));
                $trip['avg_consumption_kwh_100'] = $distance > 0
                    ? round((float)$trip['consumed_kwh'] * 100 / $distance, 2)
                    : null;
            }
        }
        $trip['short_trip'] = (float)($trip['distance_km'] ?? 0) <= 5 ? 1 : 0;

        return $trip;
    }

    /**
     * @param array<string,mixed> $active
     * @param array<int,array<string,mixed>> $snapshots
     * @return array<string,mixed>
     */
    private function finalPayloadFromActive(array $active, array $snapshots): array
    {
        $payload = $active;
        $payload['trip_note'] = 'Automaticky odvozeno z OEM telemetrie; jízda ukončena po 2 hodinách bez změny tachometru.';
        $endOdo = $this->number($active['end_odometer_km'] ?? null);
        if ($endOdo === null) {
            return $payload;
        }

        for ($i = count($snapshots) - 1; $i >= 0; $i--) {
            $odometer = $this->number($snapshots[$i]['odometer_km'] ?? null);
            if ($odometer === null || abs($odometer - $endOdo) > self::MOVEMENT_THRESHOLD_KM) {
                continue;
            }
            if (!$this->hasParkingData($snapshots[$i])) {
                continue;
            }
            $payload['end_address'] = $this->parkingAddress($snapshots[$i]);
            $payload['end_lat'] = $this->number($snapshots[$i]['latitude'] ?? null);
            $payload['end_lng'] = $this->number($snapshots[$i]['longitude'] ?? null);
            break;
        }

        return $payload;
    }

    /** @param array<string,mixed> $active @param array<int,array<string,mixed>> $snapshots */
    private function refreshActiveDestination(int $vehicleId, array $active, array $snapshots): void
    {
        $payload = $this->finalPayloadFromActive($active, $snapshots);
        $payload['trip_note'] = 'Probíhající jízda automaticky aktualizovaná z OEM telemetrie.';
        $this->trips->updateActiveTelemetryTrip($vehicleId, (int)$active['id'], $payload);
    }

    /** @param array<string,mixed> $trip */
    private function insertTripStartedEvent(
        int $vehicleId,
        int $connectionId,
        string $provider,
        int $tripId,
        array $trip
    ): int {
        return $this->telemetry->insertEvent(
            $vehicleId,
            $connectionId,
            $provider,
            'trip_started',
            'info',
            'Začala jízda',
            (string)$trip['started_at'],
            [
                'trip_id' => $tripId,
                'start_odometer_km' => $trip['start_odometer_km'] ?? null,
                'start_address' => $trip['start_address'] ?? null,
            ]
        ) ? 1 : 0;
    }

    /** @param array<string,mixed> $trip */
    private function insertTripCompletedEvent(
        int $vehicleId,
        int $connectionId,
        string $provider,
        int $tripId,
        array $trip
    ): int {
        return $this->telemetry->insertEvent(
            $vehicleId,
            $connectionId,
            $provider,
            'trip_completed',
            'info',
            'Jízda byla ukončena',
            (string)$trip['ended_at'],
            [
                'trip_id' => $tripId,
                'distance_km' => $trip['distance_km'] ?? null,
                'end_odometer_km' => $trip['end_odometer_km'] ?? null,
                'end_address' => $trip['end_address'] ?? null,
            ]
        ) ? 1 : 0;
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
     * Rozhodne, zda nový přírůstek patří do nové jízdy.
     *
     * Pokud mezi posledním přírůstkem živé jízdy a novým přírůstkem uplynuly
     * více než 2 hodiny, starou jízdu už nikdy znovu neroztahujeme. last_seen_at
     * navíc potvrzuje, že předchozí odometr byl během této mezery stále stejný.
     *
     * @param array<string,mixed> $active
     * @param array<string,mixed> $movement
     * @param array<int,array<string,mixed>> $snapshots
     */
    private function movementStartsNewTrip(array $active, array $movement, array $snapshots): bool
    {
        $lastMovementAt = strtotime((string)($active['telemetry_last_movement_at'] ?? '')) ?: 0;
        if ($lastMovementAt <= 0) {
            return false;
        }

        $olderIndex = (int)$movement['older_index'];
        $newerIndex = (int)$movement['newer_index'];
        $movementAt = (int)$movement['received_at'];
        $older = $snapshots[$olderIndex];
        $newer = $snapshots[$newerIndex];

        $stableSeenAt = $this->lastSeenTimestamp($older);
        if ($stableSeenAt > $lastMovementAt
            && $stableSeenAt - $lastMovementAt > self::TRIP_IDLE_SECONDS) {
            return true;
        }

        // U historických snapshotů před v26 last_seen_at neexistovalo. Pokud
        // nový přírůstek dorazí až po >2h, je bezpečnější založit novou jízdu,
        // než znovu započítat ranní kilometry do odpolední relace.
        if ($movementAt - $lastMovementAt > self::TRIP_IDLE_SECONDS) {
            return true;
        }

        $activeEndOdo = $this->number($active['end_odometer_km'] ?? null);
        $movementStartOdo = $this->number($older['odometer_km'] ?? null);
        if ($activeEndOdo !== null
            && $movementStartOdo !== null
            && $movementStartOdo + self::MOVEMENT_THRESHOLD_KM < $activeEndOdo) {
            return true;
        }

        return false;
    }

    /**
     * Vrátí důvěryhodné časové okno jednoho odometrového přírůstku.
     *
     * U nových dat používá last_seen_at jako poslední potvrzení původního
     * odometru. U historických dat bez takového potvrzení dovolí pouze interval
     * do dvou hodin. Pokud z časů vychází fyzikálně nesmyslná rychlost, zkusí
     * ještě serverové received_at; pokud ani to není věrohodné, segment se
     * nerekonstruuje. Je lepší nemít jednu historickou jízdu než zobrazit
     * falešných 5 000 km/h.
     *
     * @param array<string,mixed> $older
     * @param array<string,mixed> $newer
     * @return array{start_at:string,end_at:string,start_ts:int,end_ts:int}|null
     */
    private function movementTiming(array $older, array $newer, float $distanceKm): ?array
    {
        if ($distanceKm <= self::MOVEMENT_THRESHOLD_KM) {
            return null;
        }

        $olderReceived = $this->receivedTimestamp($older);
        $newerReceived = $this->receivedTimestamp($newer);
        if ($olderReceived <= 0 || $newerReceived <= $olderReceived) {
            return null;
        }

        $lastSeen = $this->lastSeenTimestamp($older);
        $hasRepeatedStableConfirmation = $lastSeen > $olderReceived + 30
            && $lastSeen <= $newerReceived;

        if ($hasRepeatedStableConfirmation) {
            return $this->plausibleTimingCandidate($lastSeen, $newerReceived, $distanceKm);
        }

        // Bez opakovaného potvrzení původního odometru nevíme, kolik jízd se
        // mohlo odehrát během dlouhé mezery. Historický skok >2h proto nikdy
        // neslepíme do jedné automatické jízdy.
        if ($newerReceived - $olderReceived > self::TRIP_IDLE_SECONDS) {
            return null;
        }

        $olderObserved = $this->fieldTimestamp($older, 'observed_at');
        $newerObserved = $this->fieldTimestamp($newer, 'observed_at');
        if ($olderObserved > 0 && $newerObserved > $olderObserved) {
            $candidate = $this->plausibleTimingCandidate($olderObserved, $newerObserved, $distanceKm);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $this->plausibleTimingCandidate($olderReceived, $newerReceived, $distanceKm);
    }

    /**
     * @return array{start_at:string,end_at:string,start_ts:int,end_ts:int}|null
     */
    private function plausibleTimingCandidate(int $startTs, int $endTs, float $distanceKm): ?array
    {
        $seconds = $endTs - $startTs;
        if ($seconds <= 0) {
            return null;
        }

        $speed = $distanceKm / ($seconds / 3600);
        if (!is_finite($speed) || $speed > self::MAX_PLAUSIBLE_SPEED_KMH) {
            return null;
        }

        return [
            'start_at' => date('Y-m-d H:i:s', $startTs),
            'end_at' => date('Y-m-d H:i:s', $endTs),
            'start_ts' => $startTs,
            'end_ts' => $endTs,
        ];
    }

    private function averageSpeedOrNull(float $distanceKm, int $minutes): ?float
    {
        if ($distanceKm <= 0 || $minutes <= 0) {
            return null;
        }

        $speed = $distanceKm / ($minutes / 60);
        if (!is_finite($speed) || $speed > self::MAX_PLAUSIBLE_SPEED_KMH) {
            return null;
        }

        return round($speed, 2);
    }

    /** @param array<string,mixed> $snapshot */
    private function fieldTimestamp(array $snapshot, string $key): int
    {
        $value = trim((string)($snapshot[$key] ?? ''));
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp !== false ? (int)$timestamp : 0;
    }

    /** @param array<string,mixed> $snapshot */
    private function lastSeenTimestamp(array $snapshot): int
    {
        $value = trim((string)($snapshot['last_seen_at'] ?? ''));
        if ($value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return $this->receivedTimestamp($snapshot);
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

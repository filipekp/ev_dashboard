<?php

declare(strict_types=1);

// Spustit: php tests/vehicle-telemetry-consumption.php
require_once __DIR__ . '/../src/App/Service/VehicleTelemetryDerivedDataService.php';

use App\Service\VehicleTelemetryDerivedDataService;

$reflection = new ReflectionClass(VehicleTelemetryDerivedDataService::class);
$service = $reflection->newInstanceWithoutConstructor();
$build = $reflection->getMethod('buildTripFromMovements');
$preserve = $reflection->getMethod('preserveActiveTripStart');
$energy = $reflection->getMethod('consumedKwhForMovement');
$build->setAccessible(true);
$preserve->setAccessible(true);
$energy->setAccessible(true);

function snapshot(int $id, string $time, float $odometer, ?float $soc, bool $charging = false): array
{
    return [
        'id' => $id,
        'received_at' => $time,
        'observed_at' => $time,
        'odometer_km' => $odometer,
        'soc_pct' => $soc,
        'is_charging' => $charging ? 1 : 0,
        'parking_address' => 'Test location',
        'quality_status' => 'good',
    ];
}

function assertNear(?float $actual, float $expected, string $label): void
{
    if ($actual === null || abs($actual - $expected) > 0.001) {
        throw new RuntimeException($label . ': expected ' . $expected . ', got ' . var_export($actual, true));
    }
}

// 239 km, 2 nabíjení. Netto SoC 33 -> 20 je pouze 13 %, skutečné
// úbytky na jízdních úsecích jsou 10 % + 16 % + 30 % = 56 %.
$snapshots = [
    snapshot(1, '2026-10-15 15:00:00', 1000, 33),
    snapshot(2, '2026-10-15 15:35:00', 1040, 23),
    snapshot(3, '2026-10-15 15:55:00', 1040, 48, true),
    snapshot(4, '2026-10-15 16:45:00', 1110, 32),
    snapshot(5, '2026-10-15 17:00:00', 1110, 50, true),
    snapshot(6, '2026-10-15 17:59:00', 1239, 20),
];

$trip = null;
foreach ([[0, 1], [2, 3], [4, 5]] as [$older, $newer]) {
    $movement = [
        'older_index' => $older,
        'newer_index' => $newer,
        'start_odo' => $snapshots[$older]['odometer_km'],
        'end_odo' => $snapshots[$newer]['odometer_km'],
        'received_at' => strtotime($snapshots[$newer]['received_at']),
    ];
    $segment = $build->invoke($service, 1, 'test', ['battery_kwh' => 75], $snapshots, [$movement], 0, 0);
    if ($segment === null) {
        throw new RuntimeException('Valid movement unexpectedly rejected.');
    }
    $trip = $trip === null ? $segment : $preserve->invoke($service, $trip, $segment);
}

assertNear((float)$trip['distance_km'], 239.0, 'Distance');
assertNear((float)$trip['start_soc'], 33.0, 'Start SoC');
assertNear((float)$trip['end_soc'], 20.0, 'End SoC');
assertNear($trip['consumed_kwh'], 42.0, 'Total energy from 3 driving segments');
assertNear($trip['avg_consumption_kwh_100'], 17.57, 'Average consumption');

// Nabíjecí přírůstky ani chybné / nezjištěné SoC nesmějí vytvořit spotřebu.
assertNear($energy->invoke($service, $snapshots[0], $snapshots[1], 75.0), 7.5, 'Normal drive');
if ($energy->invoke($service, $snapshots[1], $snapshots[2], 75.0) !== null) {
    throw new RuntimeException('Charging gain counted as consumption.');
}
if ($energy->invoke($service, snapshot(7, '2026-10-15 18:00:00', 1250, 110), $snapshots[5], 75.0) !== null) {
    throw new RuntimeException('Invalid SoC counted as consumption.');
}
if ($energy->invoke($service, snapshot(8, '2026-10-15 18:00:00', 1250, null), $snapshots[5], 75.0) !== null) {
    throw new RuntimeException('Missing SoC counted as consumption.');
}

// Následný úsek bez měřitelné změny SoC nesmí přepsat dosavadní součet.
$noDelta = ['distance_km' => 1.0, 'consumed_kwh' => null, 'avg_consumption_kwh_100' => null,
    'started_at' => $trip['ended_at'], 'ended_at' => '2026-10-15 18:01:00',
    'end_odometer_km' => 1240, 'end_soc' => 20];
$updated = $preserve->invoke($service, $trip, $noDelta);
assertNear($updated['consumed_kwh'], 42.0, 'Missing measurement preserves total');

echo "OK: telemetry consumption with charging stops\n";

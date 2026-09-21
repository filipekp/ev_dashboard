<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use Throwable;

/**
 * JSON podklad pro interaktivní mapu konkrétní jízdy.
 *
 * Kombinuje souřadnice uložené přímo u jízdy s dostupnými OEM telemetry
 * snapshoty. Pokud jsou během jízdy k dispozici nabíjecí relace, přidá je
 * jako samostatné body mapy.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   21.09.2026
 */
final class TripMapController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');

        try {
            $user = $this->app->auth()->requireLogin();
            $vehicleId = max(0, (int)($_GET['vehicle_id'] ?? 0));
            $tripId = max(0, (int)($_GET['trip_id'] ?? 0));
            if ($vehicleId === 0 || $tripId === 0) {
                $this->respond(['error' => 'Chybí vozidlo nebo jízda.'], 400);
                return;
            }

            $vehicle = null;
            foreach ($this->app->auth()->allowedVehicles($user) as $candidate) {
                if ((int)$candidate['id'] === $vehicleId) {
                    $vehicle = $candidate;
                    break;
                }
            }
            if ($vehicle === null) {
                $this->respond(['error' => 'K tomuto vozidlu nemáte přístup.'], 403);
                return;
            }

            $trip = $this->app->trips()->findByIdForVehicle($vehicleId, $tripId);
            if ($trip === null) {
                $this->respond(['error' => 'Jízda nebyla nalezena.'], 404);
                return;
            }
            if (!$this->app->userAccess()->canReadDetailAt($user, $vehicleId, (string)$trip['started_at'])) {
                $this->respond(['error' => 'K detailu této jízdy nemáte přístup.'], 403);
                return;
            }

            $from = (string)$trip['started_at'];
            $to = (string)$trip['ended_at'];
            $fromTs = strtotime($from) ?: time();
            $toTs = strtotime($to) ?: $fromTs;
            $telemetryFrom = date('Y-m-d H:i:s', $fromTs - 900);
            $telemetryTo = date('Y-m-d H:i:s', $toTs + 900);

            $telemetry = $this->app->vehicleData()->telemetryForVehiclePeriod(
                $vehicleId,
                $telemetryFrom,
                $telemetryTo,
                1200
            );
            $telemetry = $this->dominantTelemetryTrack($telemetry);
            $charging = $this->app->vehicleOperations()->chargingEntriesBetween($vehicleId, $from, $to);

            $points = $this->routePoints($trip, $telemetry);
            $telemetryPointCount = 0;
            foreach ($points as $point) {
                if (in_array((string)($point['type'] ?? ''), ['track', 'charging'], true)) {
                    $telemetryPointCount++;
                }
            }
            $stops = $this->stops($points);
            $chargingPoints = $this->chargingPoints($charging, $telemetry);

            $this->respond([
                'trip' => [
                    'id' => (int)$trip['id'],
                    'vehicle_id' => $vehicleId,
                    'vehicle_name' => (string)$vehicle['name'],
                    'started_at' => $from,
                    'ended_at' => $to,
                    'start_address' => (string)($trip['start_address'] ?? ''),
                    'end_address' => (string)($trip['end_address'] ?? ''),
                    'distance_km' => (float)($trip['distance_km'] ?? 0),
                    'driving_minutes' => (int)($trip['driving_minutes'] ?? 0),
                    'avg_speed_kmh' => $trip['avg_speed_kmh'] !== null ? (float)$trip['avg_speed_kmh'] : null,
                    'start_soc' => $trip['start_soc'] !== null ? (float)$trip['start_soc'] : null,
                    'end_soc' => $trip['end_soc'] !== null ? (float)$trip['end_soc'] : null,
                ],
                'route' => [
                    'points' => $this->decimate($points, 350),
                    'has_telemetry_track' => $telemetryPointCount >= 2,
                    'source' => $telemetryPointCount >= 2 ? 'telemetry' : (count($points) >= 2 ? 'endpoints' : 'none'),
                ],
                'stops' => $stops,
                'charging' => $chargingPoints,
                'map_available' => count($points) > 0,
            ]);
        } catch (Throwable $e) {
            $this->respond(['error' => 'Mapová data se nepodařilo načíst.'], 500);
        }
    }

    /**
     * Pokud má vozidlo současně více OEM konektorů, nemíchá jejich GPS body
     * do jedné trasy. Vybere connection s nejvyšším počtem bodů v intervalu.
     *
     * @param array<int,array<string,mixed>> $telemetry
     * @return array<int,array<string,mixed>>
     */
    private function dominantTelemetryTrack(array $telemetry): array
    {
        if (!$telemetry) {
            return [];
        }

        $counts = [];
        foreach ($telemetry as $row) {
            $connectionId = (int)($row['connection_id'] ?? 0);
            $counts[$connectionId] = ($counts[$connectionId] ?? 0) + 1;
        }
        arsort($counts);
        $dominantConnectionId = (int)array_key_first($counts);

        return array_values(array_filter($telemetry, static function (array $row) use ($dominantConnectionId): bool {
            return (int)($row['connection_id'] ?? 0) === $dominantConnectionId;
        }));
    }

    /**
     * @param array<string,mixed> $trip
     * @param array<int,array<string,mixed>> $telemetry
     * @return array<int,array<string,mixed>>
     */
    private function routePoints(array $trip, array $telemetry): array
    {
        $points = [];
        $this->appendPoint(
            $points,
            $trip['start_lat'] ?? null,
            $trip['start_lng'] ?? null,
            (string)$trip['started_at'],
            'start',
            $trip['start_address'] ?? null,
            $trip['start_odometer_km'] ?? null,
            $trip['start_soc'] ?? null
        );

        $tripStart = strtotime((string)$trip['started_at']);
        $tripEnd = strtotime((string)$trip['ended_at']);
        foreach ($telemetry as $snapshot) {
            $snapshotAt = strtotime((string)($snapshot['observed_at'] ?? ''));
            if (
                $snapshotAt !== false
                && $tripStart !== false
                && $tripEnd !== false
                && ($snapshotAt < $tripStart || $snapshotAt > $tripEnd)
            ) {
                continue;
            }
            $this->appendPoint(
                $points,
                $snapshot['latitude'] ?? null,
                $snapshot['longitude'] ?? null,
                (string)($snapshot['observed_at'] ?? ''),
                !empty($snapshot['is_charging']) ? 'charging' : 'track',
                null,
                $snapshot['odometer_km'] ?? null,
                $snapshot['soc_pct'] ?? null
            );
        }

        $this->appendPoint(
            $points,
            $trip['end_lat'] ?? null,
            $trip['end_lng'] ?? null,
            (string)$trip['ended_at'],
            'end',
            $trip['end_address'] ?? null,
            $trip['end_odometer_km'] ?? null,
            $trip['end_soc'] ?? null
        );

        usort($points, static function (array $a, array $b): int {
            return strcmp((string)$a['at'], (string)$b['at']);
        });

        $clean = [];
        foreach ($points as $point) {
            $last = $clean ? $clean[count($clean) - 1] : null;
            if ($last !== null && $this->distanceMeters($last, $point) < 8.0 && $point['type'] === 'track') {
                continue;
            }
            $clean[] = $point;
        }

        return $clean;
    }

    /**
     * @param array<int,array<string,mixed>> $points
     * @param mixed $lat
     * @param mixed $lng
     * @param mixed $address
     * @param mixed $odometer
     * @param mixed $soc
     */
    private function appendPoint(
        array &$points,
        $lat,
        $lng,
        string $at,
        string $type,
        $address,
        $odometer,
        $soc
    ): void {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return;
        }
        $latitude = (float)$lat;
        $longitude = (float)$lng;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return;
        }
        $points[] = [
            'lat' => $latitude,
            'lng' => $longitude,
            'at' => $at,
            'type' => $type,
            'address' => is_string($address) ? trim($address) : '',
            'odometer_km' => is_numeric($odometer) ? (float)$odometer : null,
            'soc_pct' => is_numeric($soc) ? (float)$soc : null,
        ];
    }

    /**
     * Odvodí významnější zastávky z telemetry bodů: vozidlo zůstane alespoň
     * deset minut prakticky na stejném místě (do 100 metrů).
     *
     * @param array<int,array<string,mixed>> $points
     * @return array<int,array<string,mixed>>
     */
    private function stops(array $points): array
    {
        $stops = [];
        $count = count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            $start = $points[$i];
            if ($start['type'] === 'start' || $start['type'] === 'end') {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $points[$j];
                if ($this->distanceMeters($start, $candidate) > 100.0) {
                    break;
                }
                $startTs = strtotime((string)$start['at']);
                $endTs = strtotime((string)$candidate['at']);
                if ($startTs !== false && $endTs !== false && ($endTs - $startTs) >= 600) {
                    $stops[] = [
                        'lat' => (float)$start['lat'],
                        'lng' => (float)$start['lng'],
                        'started_at' => (string)$start['at'],
                        'ended_at' => (string)$candidate['at'],
                        'minutes' => (int)round(($endTs - $startTs) / 60),
                    ];
                    $i = $j;
                    break;
                }
            }
            if (count($stops) >= 12) {
                break;
            }
        }

        return $stops;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @param array<int,array<string,mixed>> $telemetry
     * @return array<int,array<string,mixed>>
     */
    private function chargingPoints(array $entries, array $telemetry): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $entryTs = strtotime((string)$entry['occurred_at']);
            $nearest = null;
            $nearestDiff = PHP_INT_MAX;
            if ($entryTs !== false) {
                foreach ($telemetry as $snapshot) {
                    if (!is_numeric($snapshot['latitude'] ?? null) || !is_numeric($snapshot['longitude'] ?? null)) {
                        continue;
                    }
                    $snapshotTs = strtotime((string)($snapshot['observed_at'] ?? ''));
                    if ($snapshotTs === false) {
                        continue;
                    }
                    $diff = abs($snapshotTs - $entryTs);
                    if ($diff < $nearestDiff) {
                        $nearest = $snapshot;
                        $nearestDiff = $diff;
                    }
                }
            }
            if ($nearest === null || $nearestDiff > 7200) {
                continue;
            }

            $result[] = [
                'id' => (int)$entry['id'],
                'lat' => (float)$nearest['latitude'],
                'lng' => (float)$nearest['longitude'],
                'occurred_at' => (string)$entry['occurred_at'],
                'ended_at' => (string)($entry['ended_at'] ?? ''),
                'quantity' => (float)($entry['quantity'] ?? 0),
                'unit' => (string)($entry['unit'] ?? 'kWh'),
                'station' => (string)($entry['station'] ?? ''),
                'start_soc' => is_numeric($entry['start_soc'] ?? null) ? (float)$entry['start_soc'] : null,
                'end_soc' => is_numeric($entry['end_soc'] ?? null) ? (float)$entry['end_soc'] : null,
                'is_estimated' => !empty($entry['is_estimated']),
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function distanceMeters(array $a, array $b): float
    {
        $lat1 = deg2rad((float)$a['lat']);
        $lat2 = deg2rad((float)$b['lat']);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad((float)$b['lng'] - (float)$a['lng']);
        $h = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return 6371000.0 * 2 * atan2(sqrt($h), sqrt(max(0.0, 1 - $h)));
    }

    /**
     * @param array<int,array<string,mixed>> $points
     * @return array<int,array<string,mixed>>
     */
    private function decimate(array $points, int $max): array
    {
        $count = count($points);
        if ($count <= $max) {
            return $points;
        }
        $step = ($count - 1) / ($max - 1);
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $points[(int)round($i * $step)];
        }
        return $out;
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

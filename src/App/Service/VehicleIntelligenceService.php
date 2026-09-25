<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Skládá Battery Health, Live Drive a predikci reálného dojezdu nad historií
 * konkrétního vozidla. Výstupy vždy obsahují confidence, aby UI neprezentovalo
 * heuristický odhad jako přesné měření BMS.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   25.09.2026
 */
final class VehicleIntelligenceService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<string,mixed>|null $liveConnectedCar
     * @return array<string,mixed>
     */
    public function dashboard(array $vehicle, ?array $liveConnectedCar = null): array
    {
        $vehicleId = (int)$vehicle['id'];
        $telemetry = $liveConnectedCar !== null && isset($liveConnectedCar['telemetry']) && is_array($liveConnectedCar['telemetry'])
            ? $liveConnectedCar['telemetry']
            : null;
        $battery = $this->batteryHealth($vehicle);
        $prediction = $this->rangePrediction($vehicle, $telemetry, $battery);
        $liveDrive = $this->liveDrive($vehicleId, $telemetry, $prediction);

        return [
            'live_drive' => $liveDrive,
            'battery_health' => $battery,
            'range_prediction' => $prediction,
            'quality_summary' => $this->qualitySummary($vehicleId),
        ];
    }

    /** @return array<string,int|float> */
    public function qualitySummary(int $vehicleId): array
    {
        $query = $this->pdo->prepare(
            "SELECT COUNT(*) total,
                    SUM(quality_status='good') good,
                    SUM(quality_status='warning') warning_count,
                    SUM(quality_status='bad') bad,
                    SUM(quality_status='unknown' OR quality_status IS NULL) unknown_count,
                    AVG(quality_score) avg_score
             FROM trips WHERE vehicle_id=?"
        );
        $query->execute([$vehicleId]);
        $row = $query->fetch() ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'good' => (int)($row['good'] ?? 0),
            'warning' => (int)($row['warning_count'] ?? 0),
            'bad' => (int)($row['bad'] ?? 0),
            'unknown' => (int)($row['unknown_count'] ?? 0),
            'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
        ];
    }

    /**
     * @param array<string,mixed> $vehicle
     * @return array<string,mixed>
     */
    public function batteryHealth(array $vehicle): array
    {
        $powertrain = strtoupper((string)($vehicle['powertrain_type'] ?? 'BEV'));
        if (!in_array($powertrain, ['BEV', 'PHEV'], true)) {
            return $this->emptyBatteryHealth();
        }

        $nominal = $this->number($vehicle['battery_nominal_kwh'] ?? null);
        if ($nominal === null || $nominal <= 0) {
            $nominal = $this->number($vehicle['battery_kwh'] ?? null);
        }
        if ($nominal === null || $nominal <= 0) {
            return $this->emptyBatteryHealth();
        }

        $manual = $this->number($vehicle['soh_manual_pct'] ?? null);
        $query = $this->pdo->prepare(
            "SELECT consumed_kwh,start_soc,end_soc,end_odometer_km,started_at,quality_score
             FROM trips
             WHERE vehicle_id=? AND trip_state='completed' AND consumed_kwh>0
               AND start_soc IS NOT NULL AND end_soc IS NOT NULL
               AND (start_soc-end_soc)>=8
               AND (quality_status IS NULL OR quality_status<>'bad')
             ORDER BY started_at DESC LIMIT 160"
        );
        $query->execute([(int)$vehicle['id']]);

        $samples = [];
        foreach ($query->fetchAll() as $row) {
            $drop = (float)$row['start_soc'] - (float)$row['end_soc'];
            $energy = (float)$row['consumed_kwh'];
            if ($drop < 8 || $drop > 90 || $energy <= 0) {
                continue;
            }
            $capacity = $energy / ($drop / 100);
            $soh = $capacity / $nominal * 100;
            if ($soh < 55 || $soh > 118) {
                continue;
            }
            $samples[] = [
                'soh' => $soh,
                'capacity' => $capacity,
                'soc_drop' => $drop,
                'odometer_km' => $this->number($row['end_odometer_km'] ?? null),
                'started_at' => (string)$row['started_at'],
            ];
        }

        $filtered = $this->robustBatterySamples($samples);
        $estimatedSoh = null;
        $usableCapacity = null;
        $spread = null;
        $coverage = 0.0;
        if (count($filtered) >= 5) {
            $energyCapacityWeighted = 0.0;
            $weight = 0.0;
            $values = [];
            foreach ($filtered as $sample) {
                $w = max(8.0, (float)$sample['soc_drop']);
                $energyCapacityWeighted += (float)$sample['capacity'] * $w;
                $weight += $w;
                $coverage += (float)$sample['soc_drop'];
                $values[] = (float)$sample['soh'];
            }
            if ($weight > 0) {
                $usableCapacity = $energyCapacityWeighted / $weight;
                $estimatedSoh = $usableCapacity / $nominal * 100;
            }
            sort($values, SORT_NUMERIC);
            $spread = $this->percentile($values, 75) - $this->percentile($values, 25);
        }

        $source = 'insufficient_data';
        $soh = $estimatedSoh;
        $confidence = 0;
        if ($estimatedSoh !== null) {
            $source = 'trip_energy_model';
            $confidence = (int)round(min(94, max(35, 40 + count($filtered) * 4 + min(20, $coverage / 30) - min(20, (float)$spread * 2))));
        }
        if ($manual !== null && $manual > 0) {
            $soh = $manual;
            $usableCapacity = $nominal * $manual / 100;
            $source = 'manual_diagnostic';
            $confidence = 100;
        }

        if ($soh === null) {
            return array_merge($this->emptyBatteryHealth(), [
                'sample_count' => count($filtered),
                'nominal_capacity_kwh' => round($nominal, 2),
            ]);
        }

        $trend = $this->batteryTrend($filtered);

        return [
            'soh_pct' => round(max(0, min(110, $soh)), 1),
            'usable_capacity_kwh' => $usableCapacity !== null ? round($usableCapacity, 2) : null,
            'nominal_capacity_kwh' => round($nominal, 2),
            'confidence_pct' => $confidence,
            'sample_count' => count($filtered),
            'spread_pct' => $spread !== null ? round($spread, 2) : null,
            'soc_coverage_pct' => round($coverage, 1),
            'source' => $source,
            'trend_pct_per_10000_km' => $trend,
        ];
    }

    /**
     * @param array<string,mixed> $vehicle
     * @param array<string,mixed>|null $telemetry
     * @param array<string,mixed>|null $batteryHealth
     * @return array<string,mixed>
     */
    public function rangePrediction(array $vehicle, ?array $telemetry, ?array $batteryHealth = null): array
    {
        $powertrain = strtoupper((string)($vehicle['powertrain_type'] ?? 'BEV'));
        if (!in_array($powertrain, ['BEV', 'PHEV'], true)) {
            return $this->emptyPrediction();
        }

        $query = $this->pdo->prepare(
            "SELECT distance_km,consumed_kwh,avg_consumption_kwh_100,avg_speed_kmh,avg_outside_temperature_c,quality_score,started_at
             FROM trips
             WHERE vehicle_id=? AND trip_state='completed' AND distance_km>=2 AND consumed_kwh>0
               AND (quality_status IS NULL OR quality_status<>'bad')
             ORDER BY started_at DESC LIMIT 80"
        );
        $query->execute([(int)$vehicle['id']]);
        $rows = $query->fetchAll();
        if (count($rows) < 3) {
            return $this->emptyPrediction();
        }

        $liveTemp = $telemetry !== null ? $this->number($telemetry['outside_temperature_c'] ?? null) : null;
        $active = $this->activeTrip((int)$vehicle['id']);
        $targetSpeed = $active !== null ? $this->number($active['avg_speed_kmh'] ?? null) : null;

        $weightedEnergy = 0.0;
        $weightedDistance = 0.0;
        $matched = 0;
        foreach ($rows as $index => $row) {
            $distance = max(0.0, (float)$row['distance_km']);
            $energy = max(0.0, (float)$row['consumed_kwh']);
            if ($distance <= 0 || $energy <= 0) {
                continue;
            }
            $weight = min(80.0, max(2.0, $distance));
            $weight *= max(0.45, 1.0 - $index / 160);
            $quality = $this->number($row['quality_score'] ?? null);
            if ($quality !== null) {
                $weight *= max(0.5, min(1.0, $quality / 100));
            }
            $rowSpeed = $this->number($row['avg_speed_kmh'] ?? null);
            if ($targetSpeed !== null && $rowSpeed !== null) {
                $diff = abs($targetSpeed - $rowSpeed);
                $weight *= $diff <= 12 ? 1.35 : ($diff <= 25 ? 1.0 : 0.72);
            }
            $rowTemp = $this->number($row['avg_outside_temperature_c'] ?? null);
            if ($liveTemp !== null && $rowTemp !== null) {
                $diff = abs($liveTemp - $rowTemp);
                $weight *= $diff <= 6 ? 1.35 : ($diff <= 12 ? 1.0 : 0.78);
                if ($diff <= 8) {
                    $matched++;
                }
            }
            $weightedEnergy += $energy * $weight;
            $weightedDistance += $distance * $weight;
        }

        if ($weightedDistance <= 0) {
            return $this->emptyPrediction();
        }
        $expected = $weightedEnergy / $weightedDistance * 100;

        if ($active !== null && (float)($active['distance_km'] ?? 0) >= 5 && (float)($active['avg_consumption_kwh_100'] ?? 0) > 0) {
            $expected = $expected * 0.72 + (float)$active['avg_consumption_kwh_100'] * 0.28;
        }

        $batteryHealth = $batteryHealth ?? $this->batteryHealth($vehicle);
        $usableCapacity = $this->number($batteryHealth['usable_capacity_kwh'] ?? null);
        if ($usableCapacity === null || $usableCapacity <= 0) {
            $usableCapacity = $this->number($vehicle['battery_kwh'] ?? null);
        }
        if ($usableCapacity === null || $usableCapacity <= 0 || $expected <= 0) {
            return $this->emptyPrediction();
        }

        $soc = $telemetry !== null ? $this->number($telemetry['soc_pct'] ?? null) : null;
        $fullRange = $usableCapacity / $expected * 100;
        $remainingRange = $soc !== null ? $fullRange * max(0, min(100, $soc)) / 100 : null;
        $reportedRange = $telemetry !== null ? $this->number($telemetry['range_km'] ?? null) : null;
        $confidence = (int)round(min(95, 45 + min(35, count($rows) * 1.2) + min(10, $matched * 2)));

        return [
            'expected_consumption_kwh_100' => round($expected, 1),
            'full_range_km' => round($fullRange, 0),
            'remaining_range_km' => $remainingRange !== null ? round($remainingRange, 0) : null,
            'vehicle_reported_range_km' => $reportedRange !== null ? round($reportedRange, 0) : null,
            'confidence_pct' => $confidence,
            'sample_count' => count($rows),
            'temperature_matched_samples' => $matched,
            'soc_pct' => $soc,
        ];
    }

    /**
     * @param array<string,mixed>|null $telemetry
     * @param array<string,mixed> $prediction
     * @return array<string,mixed>|null
     */
    public function liveDrive(int $vehicleId, ?array $telemetry, array $prediction): ?array
    {
        $trip = $this->activeTrip($vehicleId);
        if ($trip === null) {
            return null;
        }

        $startedAt = strtotime((string)$trip['started_at']) ?: time();
        $elapsed = max(1, (int)round((time() - $startedAt) / 60));
        $currentSpeed = $telemetry !== null ? $this->number($telemetry['vehicle_speed_kmh'] ?? null) : null;
        $qualityScore = is_numeric($trip['quality_score'] ?? null) ? (int)$trip['quality_score'] : null;
        $qualityStatus = (string)($trip['quality_status'] ?? 'unknown');

        return [
            'trip_id' => (int)$trip['id'],
            'started_at' => (string)$trip['started_at'],
            'elapsed_minutes' => $elapsed,
            'distance_km' => round((float)$trip['distance_km'], 1),
            'avg_speed_kmh' => $trip['avg_speed_kmh'] !== null ? round((float)$trip['avg_speed_kmh'], 1) : null,
            'current_speed_kmh' => $currentSpeed !== null ? round($currentSpeed, 1) : null,
            'avg_consumption_kwh_100' => $trip['avg_consumption_kwh_100'] !== null ? round((float)$trip['avg_consumption_kwh_100'], 1) : null,
            'start_soc' => $trip['start_soc'] !== null ? (float)$trip['start_soc'] : null,
            'current_soc' => $telemetry !== null ? $this->number($telemetry['soc_pct'] ?? null) : $this->number($trip['end_soc'] ?? null),
            'end_soc' => $trip['end_soc'] !== null ? (float)$trip['end_soc'] : null,
            'start_address' => (string)($trip['start_address'] ?? ''),
            'current_address' => $telemetry !== null ? (string)($telemetry['parking_address'] ?? '') : (string)($trip['end_address'] ?? ''),
            'quality_score' => $qualityScore,
            'quality_status' => $qualityStatus,
            'predicted_remaining_range_km' => $prediction['remaining_range_km'] ?? null,
            'prediction_confidence_pct' => $prediction['confidence_pct'] ?? null,
            'last_snapshot_at' => $telemetry !== null ? (string)($telemetry['received_at'] ?? $telemetry['observed_at'] ?? '') : '',
        ];
    }

    /** @return array<string,mixed>|null */
    private function activeTrip(int $vehicleId): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT * FROM trips WHERE vehicle_id=? AND trip_state='active' ORDER BY started_at DESC,id DESC LIMIT 1"
        );
        $query->execute([$vehicleId]);
        $row = $query->fetch();
        return $row ?: null;
    }

    /** @param array<int,array<string,mixed>> $samples @return array<int,array<string,mixed>> */
    private function robustBatterySamples(array $samples): array
    {
        if (count($samples) < 5) {
            return $samples;
        }
        $values = array_map(static function (array $sample): float {
            return (float)$sample['soh'];
        }, $samples);
        $median = $this->median($values);
        $deviations = array_map(static function (float $value) use ($median): float {
            return abs($value - $median);
        }, $values);
        $mad = $this->median($deviations);
        $band = max(3.0, 3.0 * 1.4826 * $mad);

        return array_values(array_filter($samples, static function (array $sample) use ($median, $band): bool {
            return abs((float)$sample['soh'] - $median) <= $band;
        }));
    }

    /** @param array<int,array<string,mixed>> $samples */
    private function batteryTrend(array $samples): ?float
    {
        $points = [];
        foreach ($samples as $sample) {
            if ($sample['odometer_km'] === null) {
                continue;
            }
            $points[] = [(float)$sample['odometer_km'], (float)$sample['soh']];
        }
        if (count($points) < 8) {
            return null;
        }

        $meanX = array_sum(array_column($points, 0)) / count($points);
        $meanY = array_sum(array_column($points, 1)) / count($points);
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($points as $point) {
            $dx = $point[0] - $meanX;
            $numerator += $dx * ($point[1] - $meanY);
            $denominator += $dx * $dx;
        }
        if ($denominator <= 0) {
            return null;
        }
        return round(($numerator / $denominator) * 10000, 2);
    }

    /** @param float[] $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float)$values[$middle];
        }
        return ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
    }

    /** @param float[] $values */
    private function percentile(array $values, float $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float)$values[0];
        }
        $position = ($percentile / 100) * ($count - 1);
        $lower = (int)floor($position);
        $upper = (int)ceil($position);
        if ($lower === $upper) {
            return (float)$values[$lower];
        }
        $weight = $position - $lower;
        return (float)$values[$lower] * (1 - $weight) + (float)$values[$upper] * $weight;
    }

    /** @return array<string,mixed> */
    private function emptyBatteryHealth(): array
    {
        return [
            'soh_pct' => null,
            'usable_capacity_kwh' => null,
            'nominal_capacity_kwh' => null,
            'confidence_pct' => 0,
            'sample_count' => 0,
            'spread_pct' => null,
            'soc_coverage_pct' => 0.0,
            'source' => 'insufficient_data',
            'trend_pct_per_10000_km' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyPrediction(): array
    {
        return [
            'expected_consumption_kwh_100' => null,
            'full_range_km' => null,
            'remaining_range_km' => null,
            'vehicle_reported_range_km' => null,
            'confidence_pct' => 0,
            'sample_count' => 0,
            'temperature_matched_samples' => 0,
            'soc_pct' => null,
        ];
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}

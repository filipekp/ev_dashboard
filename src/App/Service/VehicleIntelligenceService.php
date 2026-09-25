<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Skládá Battery Health AI, Live Trip Intelligence a predikci reálného dojezdu
 * nad historií konkrétního vozidla. Výstupy vždy obsahují confidence, aby UI
 * neprezentovalo heuristický odhad jako přesné měření BMS.
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
            'review_count' => (int)($row['warning_count'] ?? 0) + (int)($row['bad'] ?? 0),
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
            "SELECT consumed_kwh,start_soc,end_soc,start_odometer_km,end_odometer_km,started_at,quality_score
             FROM trips
             WHERE vehicle_id=? AND trip_state='completed' AND consumed_kwh>0
               AND start_soc IS NOT NULL AND end_soc IS NOT NULL
               AND (start_soc-end_soc)>=8
               AND (quality_status IS NULL OR quality_status<>'bad')
             ORDER BY started_at DESC LIMIT 180"
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
                'odometer_km' => $this->number($row['end_odometer_km'] ?? $row['start_odometer_km'] ?? null),
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
            $confidence = (int)round(min(
                94,
                max(35, 40 + count($filtered) * 4 + min(20, $coverage / 30) - min(20, (float)$spread * 2))
            ));
        }
        if ($manual !== null && $manual > 0) {
            $soh = $manual;
            $usableCapacity = $nominal * $manual / 100;
            $source = 'manual_diagnostic';
            $confidence = 100;
        }

        $sampleMeta = $this->batterySampleMeta($filtered);
        if ($soh === null) {
            return array_merge($this->emptyBatteryHealth(), [
                'sample_count' => count($filtered),
                'nominal_capacity_kwh' => round($nominal, 2),
                'sample_window_days' => $sampleMeta['window_days'],
                'sample_mileage_km' => $sampleMeta['mileage_km'],
            ]);
        }

        $trend = $this->batteryTrend($filtered);
        $forecastTrend = $trend;
        if ($forecastTrend !== null) {
            // Kladný trend bývá u této heuristiky spíše šum než skutečné "zlepšování" baterie.
            $forecastTrend = min(0.0, max(-5.0, $forecastTrend));
        }
        $forecastAvailable = $forecastTrend !== null
            && $forecastTrend < -0.05
            && count($filtered) >= 8
            && (float)$sampleMeta['mileage_km'] >= 1000;
        $forecast10k = $forecastAvailable ? max(0.0, $soh + $forecastTrend) : null;
        $forecast50k = $forecastAvailable ? max(0.0, $soh + $forecastTrend * 5) : null;
        $capacityLoss = max(0.0, $nominal - (float)($usableCapacity ?? $nominal));

        return [
            'soh_pct' => round(max(0, min(110, $soh)), 1),
            'usable_capacity_kwh' => $usableCapacity !== null ? round($usableCapacity, 2) : null,
            'nominal_capacity_kwh' => round($nominal, 2),
            'capacity_loss_kwh' => round($capacityLoss, 2),
            'capacity_loss_pct' => round(max(0.0, 100 - $soh), 1),
            'confidence_pct' => $confidence,
            'confidence_label' => $this->confidenceLabel($confidence),
            'sample_count' => count($filtered),
            'spread_pct' => $spread !== null ? round($spread, 2) : null,
            'soc_coverage_pct' => round($coverage, 1),
            'source' => $source,
            'health_label' => $this->batteryHealthLabel($soh),
            'trend_pct_per_10000_km' => $trend,
            'trend_label' => $this->batteryTrendLabel($trend),
            'forecast_soh_10000_km' => $forecast10k !== null ? round($forecast10k, 1) : null,
            'forecast_soh_50000_km' => $forecast50k !== null ? round($forecast50k, 1) : null,
            'sample_window_days' => $sampleMeta['window_days'],
            'sample_mileage_km' => $sampleMeta['mileage_km'],
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
            "SELECT distance_km,consumed_kwh,avg_consumption_kwh_100,avg_speed_kmh,avg_outside_temperature_c,
                    quality_score,started_at
             FROM trips
             WHERE vehicle_id=? AND trip_state='completed' AND distance_km>=2 AND consumed_kwh>0
               AND (quality_status IS NULL OR quality_status<>'bad')
             ORDER BY started_at DESC LIMIT 120"
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
        $consumptionSamples = [];
        foreach ($rows as $index => $row) {
            $distance = max(0.0, (float)$row['distance_km']);
            $energy = max(0.0, (float)$row['consumed_kwh']);
            if ($distance <= 0 || $energy <= 0) {
                continue;
            }
            $consumption = $energy / $distance * 100;
            if ($consumption >= 5 && $consumption <= 60) {
                $consumptionSamples[] = $consumption;
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
        $activeBlend = false;
        if ($active !== null && (float)($active['distance_km'] ?? 0) >= 5 && (float)($active['avg_consumption_kwh_100'] ?? 0) > 0) {
            $expected = $expected * 0.72 + (float)$active['avg_consumption_kwh_100'] * 0.28;
            $activeBlend = true;
        }

        $batteryHealth = $batteryHealth ?? $this->batteryHealth($vehicle);
        $usableCapacity = $this->number($batteryHealth['usable_capacity_kwh'] ?? null);
        if ($usableCapacity === null || $usableCapacity <= 0) {
            $usableCapacity = $this->number($vehicle['battery_kwh'] ?? null);
        }
        if ($usableCapacity === null || $usableCapacity <= 0 || $expected <= 0) {
            return $this->emptyPrediction();
        }

        sort($consumptionSamples, SORT_NUMERIC);
        $p25 = count($consumptionSamples) >= 3 ? $this->percentile($consumptionSamples, 25) : $expected;
        $p75 = count($consumptionSamples) >= 3 ? $this->percentile($consumptionSamples, 75) : $expected;
        $p25 = max(1.0, min($expected, $p25));
        $p75 = max($expected, $p75);
        $spread = max(0.0, $p75 - $p25);

        $soc = $telemetry !== null ? $this->number($telemetry['soc_pct'] ?? null) : null;
        if ($soc === null && $active !== null) {
            $soc = $this->number($active['end_soc'] ?? null);
        }
        $socRatio = $soc !== null ? max(0.0, min(1.0, $soc / 100)) : null;
        $fullRange = $usableCapacity / $expected * 100;
        $remainingRange = $socRatio !== null ? $fullRange * $socRatio : null;
        $conservativeFull = $usableCapacity / $p75 * 100;
        $optimisticFull = $usableCapacity / $p25 * 100;
        $conservativeRemaining = $socRatio !== null ? $conservativeFull * $socRatio : null;
        $optimisticRemaining = $socRatio !== null ? $optimisticFull * $socRatio : null;

        $reportedRange = $telemetry !== null ? $this->number($telemetry['range_km'] ?? null) : null;
        $vehicleDelta = null;
        if ($reportedRange !== null && $reportedRange > 0 && $remainingRange !== null) {
            $vehicleDelta = ($remainingRange - $reportedRange) / $reportedRange * 100;
        }

        $spreadPenalty = $expected > 0 ? min(18.0, ($spread / $expected) * 35) : 0.0;
        $confidence = (int)round(min(
            96,
            max(35, 45 + min(35, count($rows) * 1.2) + min(10, $matched * 2) - $spreadPenalty)
        ));

        $scenarioRanges = [
            'city' => $this->scenarioRange($rows, null, 45.0, $usableCapacity),
            'mixed' => $this->scenarioRange($rows, 45.0, 80.0, $usableCapacity),
            'highway' => $this->scenarioRange($rows, 80.0, null, $usableCapacity),
        ];

        return [
            'expected_consumption_kwh_100' => round($expected, 1),
            'full_range_km' => round($fullRange, 0),
            'remaining_range_km' => $remainingRange !== null ? round($remainingRange, 0) : null,
            'conservative_full_range_km' => round($conservativeFull, 0),
            'optimistic_full_range_km' => round($optimisticFull, 0),
            'conservative_remaining_range_km' => $conservativeRemaining !== null ? round($conservativeRemaining, 0) : null,
            'optimistic_remaining_range_km' => $optimisticRemaining !== null ? round($optimisticRemaining, 0) : null,
            'vehicle_reported_range_km' => $reportedRange !== null ? round($reportedRange, 0) : null,
            'vehicle_delta_pct' => $vehicleDelta !== null ? round($vehicleDelta, 1) : null,
            'confidence_pct' => $confidence,
            'confidence_label' => $this->confidenceLabel($confidence),
            'sample_count' => count($rows),
            'temperature_matched_samples' => $matched,
            'temperature_c' => $liveTemp,
            'soc_pct' => $soc,
            'usable_capacity_kwh' => round($usableCapacity, 2),
            'consumption_spread_kwh_100' => round($spread, 1),
            'active_trip_blend' => $activeBlend,
            'scenarios' => $scenarioRanges,
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
        $startSoc = $this->number($trip['start_soc'] ?? null);
        $currentSoc = $telemetry !== null
            ? $this->number($telemetry['soc_pct'] ?? null)
            : $this->number($trip['end_soc'] ?? null);
        $socUsed = $startSoc !== null && $currentSoc !== null ? max(0.0, $startSoc - $currentSoc) : null;
        $actualConsumption = $this->number($trip['avg_consumption_kwh_100'] ?? null);
        $expectedConsumption = $this->number($prediction['expected_consumption_kwh_100'] ?? null);
        $consumptionDelta = null;
        $efficiencyScore = null;
        $efficiencyLabel = 'Model se učí';
        if ($actualConsumption !== null && $actualConsumption > 0 && $expectedConsumption !== null && $expectedConsumption > 0) {
            $consumptionDelta = ($actualConsumption - $expectedConsumption) / $expectedConsumption * 100;
            $efficiencyScore = $consumptionDelta <= 0
                ? (int)round(min(100, 94 + min(6, abs($consumptionDelta) / 4)))
                : (int)round(max(20, 94 - $consumptionDelta * 1.35));
            if ($consumptionDelta <= -7) {
                $efficiencyLabel = 'Úspornější než model';
            } elseif ($consumptionDelta >= 7) {
                $efficiencyLabel = 'Náročnější než model';
            } else {
                $efficiencyLabel = 'Odpovídá modelu';
            }
        }

        $usableCapacity = $this->number($prediction['usable_capacity_kwh'] ?? null);
        $paceFullRange = $usableCapacity !== null && $usableCapacity > 0 && $actualConsumption !== null && $actualConsumption > 0
            ? $usableCapacity / $actualConsumption * 100
            : null;
        $energyUsed = $this->number($trip['consumed_kwh'] ?? null);
        $reportedRange = $this->number($prediction['vehicle_reported_range_km'] ?? null);
        $predictedRange = $this->number($prediction['remaining_range_km'] ?? null);
        $rangeBuffer = $reportedRange !== null && $predictedRange !== null ? $predictedRange - $reportedRange : null;
        $lastSnapshot = $telemetry !== null ? (string)($telemetry['received_at'] ?? $telemetry['observed_at'] ?? '') : '';
        $snapshotTimestamp = $lastSnapshot !== '' ? strtotime($lastSnapshot) : false;
        $freshnessSeconds = $snapshotTimestamp !== false ? max(0, time() - $snapshotTimestamp) : null;

        return [
            'trip_id' => (int)$trip['id'],
            'started_at' => (string)$trip['started_at'],
            'elapsed_minutes' => $elapsed,
            'distance_km' => round((float)$trip['distance_km'], 1),
            'avg_speed_kmh' => $trip['avg_speed_kmh'] !== null ? round((float)$trip['avg_speed_kmh'], 1) : null,
            'current_speed_kmh' => $currentSpeed !== null ? round($currentSpeed, 1) : null,
            'avg_consumption_kwh_100' => $actualConsumption !== null ? round($actualConsumption, 1) : null,
            'expected_consumption_kwh_100' => $expectedConsumption !== null ? round($expectedConsumption, 1) : null,
            'consumption_delta_pct' => $consumptionDelta !== null ? round($consumptionDelta, 1) : null,
            'efficiency_score' => $efficiencyScore,
            'efficiency_label' => $efficiencyLabel,
            'energy_used_kwh' => $energyUsed !== null ? round($energyUsed, 2) : null,
            'start_soc' => $startSoc,
            'current_soc' => $currentSoc,
            'soc_used_pct' => $socUsed !== null ? round($socUsed, 1) : null,
            'end_soc' => $trip['end_soc'] !== null ? (float)$trip['end_soc'] : null,
            'start_address' => (string)($trip['start_address'] ?? ''),
            'current_address' => $telemetry !== null ? (string)($telemetry['parking_address'] ?? '') : (string)($trip['end_address'] ?? ''),
            'quality_score' => $qualityScore,
            'quality_status' => $qualityStatus,
            'predicted_remaining_range_km' => $prediction['remaining_range_km'] ?? null,
            'conservative_remaining_range_km' => $prediction['conservative_remaining_range_km'] ?? null,
            'optimistic_remaining_range_km' => $prediction['optimistic_remaining_range_km'] ?? null,
            'projected_full_range_at_pace_km' => $paceFullRange !== null ? round($paceFullRange, 0) : null,
            'range_buffer_vs_vehicle_km' => $rangeBuffer !== null ? round($rangeBuffer, 0) : null,
            'prediction_confidence_pct' => $prediction['confidence_pct'] ?? null,
            'last_snapshot_at' => $lastSnapshot,
            'freshness_seconds' => $freshnessSeconds,
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

    /** @param array<int,array<string,mixed>> $samples @return array<string,int|float> */
    private function batterySampleMeta(array $samples): array
    {
        $timestamps = [];
        $odometers = [];
        foreach ($samples as $sample) {
            $timestamp = strtotime((string)($sample['started_at'] ?? ''));
            if ($timestamp !== false) {
                $timestamps[] = $timestamp;
            }
            if ($sample['odometer_km'] !== null) {
                $odometers[] = (float)$sample['odometer_km'];
            }
        }

        $windowDays = 0;
        if (count($timestamps) >= 2) {
            $windowDays = (int)round((max($timestamps) - min($timestamps)) / 86400);
        }
        $mileage = count($odometers) >= 2 ? max($odometers) - min($odometers) : 0.0;

        return [
            'window_days' => max(0, $windowDays),
            'mileage_km' => round(max(0.0, $mileage), 0),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function scenarioRange(array $rows, ?float $minSpeed, ?float $maxSpeed, float $usableCapacity): array
    {
        $energy = 0.0;
        $distance = 0.0;
        $samples = 0;
        foreach ($rows as $row) {
            $speed = $this->number($row['avg_speed_kmh'] ?? null);
            if ($speed === null) {
                continue;
            }
            if ($minSpeed !== null && $speed < $minSpeed) {
                continue;
            }
            if ($maxSpeed !== null && $speed >= $maxSpeed) {
                continue;
            }
            $rowDistance = max(0.0, (float)($row['distance_km'] ?? 0));
            $rowEnergy = max(0.0, (float)($row['consumed_kwh'] ?? 0));
            if ($rowDistance <= 0 || $rowEnergy <= 0) {
                continue;
            }
            $distance += $rowDistance;
            $energy += $rowEnergy;
            $samples++;
        }

        if ($samples < 2 || $distance < 10 || $energy <= 0) {
            return [
                'range_km' => null,
                'consumption_kwh_100' => null,
                'sample_count' => $samples,
            ];
        }

        $consumption = $energy / $distance * 100;
        return [
            'range_km' => round($usableCapacity / $consumption * 100, 0),
            'consumption_kwh_100' => round($consumption, 1),
            'sample_count' => $samples,
        ];
    }

    private function batteryHealthLabel(float $soh): string
    {
        if ($soh >= 95) {
            return 'Výborná kondice';
        }
        if ($soh >= 90) {
            return 'Velmi dobrá kondice';
        }
        if ($soh >= 80) {
            return 'Dobrá kondice';
        }
        if ($soh >= 70) {
            return 'Doporučeno sledovat';
        }
        return 'Výrazně snížená kapacita';
    }

    private function batteryTrendLabel(?float $trend): string
    {
        if ($trend === null) {
            return 'trend zatím nelze určit';
        }
        if ($trend < -0.3) {
            return 'pozvolný pokles';
        }
        if ($trend > 0.3) {
            return 'model kolísá';
        }
        return 'stabilní';
    }

    private function confidenceLabel(int $confidence): string
    {
        if ($confidence >= 85) {
            return 'vysoká';
        }
        if ($confidence >= 65) {
            return 'střední';
        }
        return 'orientační';
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
            'capacity_loss_kwh' => null,
            'capacity_loss_pct' => null,
            'confidence_pct' => 0,
            'confidence_label' => 'orientační',
            'sample_count' => 0,
            'spread_pct' => null,
            'soc_coverage_pct' => 0.0,
            'source' => 'insufficient_data',
            'health_label' => 'Nedostatek dat',
            'trend_pct_per_10000_km' => null,
            'trend_label' => 'trend zatím nelze určit',
            'forecast_soh_10000_km' => null,
            'forecast_soh_50000_km' => null,
            'sample_window_days' => 0,
            'sample_mileage_km' => 0.0,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyPrediction(): array
    {
        return [
            'expected_consumption_kwh_100' => null,
            'full_range_km' => null,
            'remaining_range_km' => null,
            'conservative_full_range_km' => null,
            'optimistic_full_range_km' => null,
            'conservative_remaining_range_km' => null,
            'optimistic_remaining_range_km' => null,
            'vehicle_reported_range_km' => null,
            'vehicle_delta_pct' => null,
            'confidence_pct' => 0,
            'confidence_label' => 'orientační',
            'sample_count' => 0,
            'temperature_matched_samples' => 0,
            'temperature_c' => null,
            'soc_pct' => null,
            'usable_capacity_kwh' => null,
            'consumption_spread_kwh_100' => null,
            'active_trip_blend' => false,
            'scenarios' => [
                'city' => ['range_km' => null, 'consumption_kwh_100' => null, 'sample_count' => 0],
                'mixed' => ['range_km' => null, 'consumption_kwh_100' => null, 'sample_count' => 0],
                'highway' => ['range_km' => null, 'consumption_kwh_100' => null, 'sample_count' => 0],
            ],
        ];
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}

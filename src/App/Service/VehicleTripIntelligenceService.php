<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Vysvětluje efektivitu jednotlivých jízd vůči historii konkrétního vozidla.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   25.09.2026
 */
final class VehicleTripIntelligenceService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $trip
     * @return array<string,mixed>
     */
    public function analyze(int $vehicleId, array $trip): array
    {
        $actual = $this->actualConsumption($trip);
        if ($actual === null || $actual <= 0) {
            return $this->emptyResult();
        }

        $profile = $this->comparisonProfile($vehicleId, $trip);
        if ((int)$profile['sample_count'] < 3 || (float)$profile['expected_consumption'] <= 0) {
            return $this->emptyResult();
        }

        $expected = (float)$profile['expected_consumption'];
        $deltaPct = ($actual - $expected) / $expected * 100;
        $qualityScore = is_numeric($trip['quality_score'] ?? null) ? (int)$trip['quality_score'] : 80;
        $sampleCount = (int)$profile['sample_count'];
        $confidence = (int)round(min(96, max(25, 35 + min(40, $sampleCount * 3) + max(0, $qualityScore - 60) * 0.45)));

        if ($deltaPct <= 0) {
            $efficiencyScore = (int)round(min(100, 94 + min(6, abs($deltaPct) / 4)));
        } else {
            $efficiencyScore = (int)round(max(20, 94 - $deltaPct * 1.35));
        }

        $factors = [];
        $speed = $this->number($trip['avg_speed_kmh'] ?? null);
        $profileSpeed = $this->number($profile['avg_speed_kmh'] ?? null);
        if ($speed !== null && $profileSpeed !== null && $profileSpeed > 0) {
            if ($speed > $profileSpeed + 12) {
                $factors[] = [
                    'code' => 'speed_high',
                    'label' => 'vyšší průměrná rychlost',
                    'direction' => 'up',
                ];
            } elseif ($speed + 12 < $profileSpeed) {
                $factors[] = [
                    'code' => 'speed_low',
                    'label' => 'nižší průměrná rychlost',
                    'direction' => 'down',
                ];
            }
        }

        $temperature = $this->number($trip['avg_outside_temperature_c'] ?? null);
        if ($temperature !== null) {
            if ($temperature < 5) {
                $factors[] = [
                    'code' => 'cold_weather',
                    'label' => 'nízká venkovní teplota',
                    'direction' => 'up',
                ];
            } elseif ($temperature > 30) {
                $factors[] = [
                    'code' => 'hot_weather',
                    'label' => 'vysoká venkovní teplota',
                    'direction' => 'up',
                ];
            }
        }

        $summary = $deltaPct > 5
            ? 'Spotřeba byla vyšší než srovnatelné jízdy.'
            : ($deltaPct < -5
                ? 'Jízda byla úspornější než srovnatelné jízdy.'
                : 'Spotřeba odpovídala běžnému profilu vozidla.');

        $payload = [
            'summary' => $summary,
            'sample_count' => $sampleCount,
            'factors' => $factors,
            'profile_speed_kmh' => $profileSpeed,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'expected_consumption_kwh_100' => round($expected, 2),
            'consumption_delta_pct' => round($deltaPct, 2),
            'efficiency_score' => $efficiencyScore,
            'prediction_confidence_pct' => $confidence,
            'trip_intelligence_json' => $json !== false ? $json : '{}',
        ];
    }

    /** @param array<string,mixed> $trip @return array<string,mixed> */
    private function comparisonProfile(int $vehicleId, array $trip): array
    {
        $tripId = (int)($trip['id'] ?? 0);
        $startedAt = trim((string)($trip['started_at'] ?? ''));
        if ($startedAt === '') {
            $startedAt = date('Y-m-d H:i:s');
        }
        $speed = $this->number($trip['avg_speed_kmh'] ?? null);
        $temperature = $this->number($trip['avg_outside_temperature_c'] ?? null);

        $where = [
            'vehicle_id=?',
            'id<>?',
            "trip_state='completed'",
            'started_at<?',
            'distance_km>=2',
            'consumed_kwh>0',
            "(quality_status IS NULL OR quality_status<>'bad')",
        ];
        $params = [$vehicleId, $tripId, $startedAt];

        if ($speed !== null) {
            $where[] = 'avg_speed_kmh BETWEEN ? AND ?';
            $params[] = max(0, $speed - 18);
            $params[] = $speed + 18;
        }
        if ($temperature !== null) {
            $where[] = '(avg_outside_temperature_c IS NULL OR avg_outside_temperature_c BETWEEN ? AND ?)';
            $params[] = $temperature - 8;
            $params[] = $temperature + 8;
        }

        $profile = $this->profileQuery($where, $params);
        if ((int)$profile['sample_count'] >= 3) {
            return $profile;
        }

        $where = [
            'vehicle_id=?',
            'id<>?',
            "trip_state='completed'",
            'started_at<?',
            'distance_km>=2',
            'consumed_kwh>0',
            "(quality_status IS NULL OR quality_status<>'bad')",
        ];
        return $this->profileQuery($where, [$vehicleId, $tripId, $startedAt]);
    }

    /** @param string[] $where @param array<int,mixed> $params @return array<string,mixed> */
    private function profileQuery(array $where, array $params): array
    {
        $sql = 'SELECT COUNT(*) sample_count,COALESCE(SUM(distance_km),0) km,COALESCE(SUM(consumed_kwh),0) kwh,'
            . 'AVG(avg_speed_kmh) avg_speed_kmh FROM trips WHERE ' . implode(' AND ', $where);
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $row = $query->fetch() ?: [];
        $km = (float)($row['km'] ?? 0);
        $kwh = (float)($row['kwh'] ?? 0);

        return [
            'sample_count' => (int)($row['sample_count'] ?? 0),
            'expected_consumption' => $km > 0 ? $kwh / $km * 100 : 0.0,
            'avg_speed_kmh' => $row['avg_speed_kmh'] !== null ? (float)$row['avg_speed_kmh'] : null,
        ];
    }

    /** @param array<string,mixed> $trip */
    private function actualConsumption(array $trip): ?float
    {
        $average = $this->number($trip['avg_consumption_kwh_100'] ?? null);
        if ($average !== null && $average > 0) {
            return $average;
        }
        $distance = $this->number($trip['distance_km'] ?? null);
        $consumed = $this->number($trip['consumed_kwh'] ?? null);
        if ($distance !== null && $distance > 0 && $consumed !== null && $consumed > 0) {
            return $consumed / $distance * 100;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function emptyResult(): array
    {
        return [
            'expected_consumption_kwh_100' => null,
            'consumption_delta_pct' => null,
            'efficiency_score' => null,
            'prediction_confidence_pct' => null,
            'trip_intelligence_json' => null,
        ];
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}

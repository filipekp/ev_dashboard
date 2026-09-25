<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Hodnotí důvěryhodnost OEM telemetrie a odvozených jízd.
 *
 * Engine data nemaže. Podezřelé body zachová pro audit, ale označí je tak,
 * aby je projekce jízd mohla bezpečně ignorovat a UI dokázalo vysvětlit proč.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   25.09.2026
 */
final class VehicleDataQualityService
{
    private const MAX_PLAUSIBLE_SPEED_KMH = 300.0;
    private const MAX_GPS_SPEED_KMH = 420.0;
    private const TELEMETRY_GAP_WARNING_SECONDS = 1800;
    private const TELEMETRY_GAP_MAJOR_SECONDS = 7200;

    /**
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $current
     * @return array{score:int,status:string,issues:array<int,array<string,mixed>>}
     */
    public function assessTelemetry(?array $previous, array $current): array
    {
        $previous = $previous !== null ? $this->telemetryRow($previous) : null;
        $current = $this->telemetryRow($current);
        $issues = [];

        $observedAt = $this->timestamp($current['observed_at'] ?? null);
        if ($observedAt <= 0) {
            $this->issue($issues, 'missing_time', 'critical', 'Snapshot nemá platný čas pozorování.', 45);
        } elseif ($observedAt > time() + 600) {
            $this->issue($issues, 'future_time', 'warning', 'Čas telemetrie je více než 10 minut v budoucnosti.', 18);
        }

        $speed = $this->number($current['vehicle_speed_kmh'] ?? null);
        if ($speed !== null && ($speed < -1 || $speed > self::MAX_PLAUSIBLE_SPEED_KMH)) {
            $this->issue($issues, 'vehicle_speed_invalid', 'critical', 'Vozidlo hlásí fyzikálně nevěrohodnou rychlost.', 55);
        }

        $soc = $this->number($current['soc_pct'] ?? null);
        if ($soc !== null && ($soc < -0.1 || $soc > 100.1)) {
            $this->issue($issues, 'soc_out_of_range', 'critical', 'SoC je mimo rozsah 0–100 %.', 45);
        }

        $range = $this->number($current['range_km'] ?? null);
        if ($range !== null && ($range < 0 || $range > 2000)) {
            $this->issue($issues, 'range_out_of_range', 'warning', 'Odhadovaný dojezd je mimo běžný rozsah.', 18);
        }

        $batteryTemp = $this->number($current['battery_temperature_c'] ?? null);
        if ($batteryTemp !== null && ($batteryTemp < -60 || $batteryTemp > 100)) {
            $this->issue($issues, 'battery_temperature_invalid', 'warning', 'Teplota baterie je mimo věrohodný rozsah.', 20);
        }

        $outsideTemp = $this->number($current['outside_temperature_c'] ?? null);
        if ($outsideTemp !== null && ($outsideTemp < -70 || $outsideTemp > 70)) {
            $this->issue($issues, 'outside_temperature_invalid', 'warning', 'Venkovní teplota je mimo věrohodný rozsah.', 15);
        }

        $this->assessCoordinates($current, $issues);

        if ($previous !== null) {
            $previousAt = $this->bestTimestamp($previous);
            $currentAt = $this->bestTimestamp($current);
            $seconds = $currentAt > 0 && $previousAt > 0 ? $currentAt - $previousAt : 0;

            if ($seconds > self::TELEMETRY_GAP_MAJOR_SECONDS) {
                $this->issue($issues, 'telemetry_gap_major', 'warning', 'Mezi snapshoty je více než 2 hodiny bez telemetrie.', 10);
            } elseif ($seconds > self::TELEMETRY_GAP_WARNING_SECONDS) {
                $this->issue($issues, 'telemetry_gap', 'notice', 'Mezi snapshoty je delší mezera v telemetrii.', 4);
            }

            $previousOdo = $this->number($previous['odometer_km'] ?? null);
            $currentOdo = $this->number($current['odometer_km'] ?? null);
            if ($previousOdo !== null && $currentOdo !== null) {
                $delta = $currentOdo - $previousOdo;
                if ($delta < -0.5) {
                    $this->issue($issues, 'odometer_regression', 'critical', 'Tachometr se proti předchozímu snapshotu snížil.', 65);
                } elseif ($delta > 0.05 && $seconds > 0) {
                    $impliedSpeed = $delta / ($seconds / 3600);
                    if ($impliedSpeed > self::MAX_PLAUSIBLE_SPEED_KMH) {
                        $this->issue($issues, 'odometer_jump', 'critical', 'Skok tachometru by vyžadoval rychlost přes 300 km/h.', 65, [
                            'implied_speed_kmh' => round($impliedSpeed, 1),
                            'distance_km' => round($delta, 2),
                        ]);
                    } elseif ($impliedSpeed > 220) {
                        $this->issue($issues, 'odometer_speed_high', 'warning', 'Přírůstek tachometru odpovídá neobvykle vysoké rychlosti.', 18, [
                            'implied_speed_kmh' => round($impliedSpeed, 1),
                        ]);
                    }
                }
            }

            $previousSoc = $this->number($previous['soc_pct'] ?? null);
            if ($soc !== null && $previousSoc !== null && $seconds > 0 && $seconds <= 1800) {
                $socDelta = $soc - $previousSoc;
                $charging = !empty($current['is_charging']) || !empty($previous['is_charging']);
                if (!$charging && $socDelta > 18) {
                    $this->issue($issues, 'soc_jump_up', 'warning', 'SoC bez nabíjení skokově vzrostlo.', 20, [
                        'delta_pct' => round($socDelta, 1),
                    ]);
                }
                if ($socDelta < -35) {
                    $this->issue($issues, 'soc_jump_down', 'warning', 'SoC během krátkého intervalu neobvykle kleslo.', 20, [
                        'delta_pct' => round($socDelta, 1),
                    ]);
                }
            }

            if ($seconds > 0) {
                $gpsDistance = $this->gpsDistanceKm($previous, $current);
                if ($gpsDistance !== null && $gpsDistance > 0.2) {
                    $gpsSpeed = $gpsDistance / ($seconds / 3600);
                    if ($gpsSpeed > self::MAX_GPS_SPEED_KMH) {
                        $this->issue($issues, 'gps_jump', 'critical', 'GPS poloha mezi snapshoty přeskočila nereálnou rychlostí.', 45, [
                            'implied_speed_kmh' => round($gpsSpeed, 1),
                            'distance_km' => round($gpsDistance, 2),
                        ]);
                    }
                }
            }
        }

        return $this->result($issues);
    }

    /**
     * @param array<string,mixed> $trip
     * @param array<string,mixed> $context
     * @return array{score:int,status:string,issues:array<int,array<string,mixed>>}
     */
    public function assessTrip(array $trip, array $context = []): array
    {
        $issues = [];
        $start = $this->timestamp($trip['started_at'] ?? null);
        $end = $this->timestamp($trip['ended_at'] ?? null);
        $distance = max(0.0, (float)($trip['distance_km'] ?? 0));
        $minutes = max(0, (int)($trip['driving_minutes'] ?? 0));

        if ($start <= 0 || $end <= 0 || $end < $start) {
            $this->issue($issues, 'trip_time_invalid', 'critical', 'Jízda má neplatný začátek nebo konec.', 70);
        }
        if ($distance <= 0.01) {
            $this->issue($issues, 'trip_distance_missing', 'warning', 'Jízda nemá věrohodnou ujetou vzdálenost.', 22);
        }

        $durationSeconds = $start > 0 && $end >= $start ? max(1, $end - $start) : 0;
        if ($durationSeconds > 0 && $distance > 0) {
            $impliedSpeed = $distance / ($durationSeconds / 3600);
            if ($impliedSpeed > self::MAX_PLAUSIBLE_SPEED_KMH) {
                $this->issue($issues, 'trip_speed_impossible', 'critical', 'Vzdálenost a čas jízdy znamenají rychlost přes 300 km/h.', 70, [
                    'implied_speed_kmh' => round($impliedSpeed, 1),
                ]);
            } elseif ($impliedSpeed > 200) {
                $this->issue($issues, 'trip_speed_high', 'warning', 'Průměrná rychlost jízdy je neobvykle vysoká.', 20, [
                    'implied_speed_kmh' => round($impliedSpeed, 1),
                ]);
            }
        }

        $avgSpeed = $this->number($trip['avg_speed_kmh'] ?? null);
        if ($avgSpeed !== null && ($avgSpeed < 0 || $avgSpeed > self::MAX_PLAUSIBLE_SPEED_KMH)) {
            $this->issue($issues, 'avg_speed_invalid', 'critical', 'Uložená průměrná rychlost je nevěrohodná.', 55);
        } elseif ($avgSpeed !== null && $minutes > 0 && $distance > 0) {
            $calculated = $distance / ($minutes / 60);
            if ($calculated > 0 && abs($avgSpeed - $calculated) / $calculated > 0.35) {
                $this->issue($issues, 'avg_speed_mismatch', 'warning', 'Průměrná rychlost nesouhlasí s délkou a dobou jízdy.', 15);
            }
        }

        $startOdo = $this->number($trip['start_odometer_km'] ?? null);
        $endOdo = $this->number($trip['end_odometer_km'] ?? null);
        if ($startOdo !== null && $endOdo !== null) {
            if ($endOdo + 0.05 < $startOdo) {
                $this->issue($issues, 'trip_odometer_regression', 'critical', 'Koncový tachometr je nižší než počáteční.', 65);
            } else {
                $odoDistance = max(0.0, $endOdo - $startOdo);
                $tolerance = max(1.0, $distance * 0.15);
                if ($distance > 0 && abs($odoDistance - $distance) > $tolerance) {
                    $this->issue($issues, 'trip_odometer_mismatch', 'warning', 'Délka jízdy nesouhlasí s rozdílem tachometru.', 22, [
                        'odometer_distance_km' => round($odoDistance, 2),
                    ]);
                }
            }
        }

        foreach (['start_soc' => 'počáteční', 'end_soc' => 'koncové'] as $key => $label) {
            $value = $this->number($trip[$key] ?? null);
            if ($value !== null && ($value < 0 || $value > 100)) {
                $this->issue($issues, 'trip_soc_invalid', 'warning', 'Jízda má ' . $label . ' SoC mimo rozsah 0–100 %.', 20);
            }
        }

        $overlapCount = max(0, (int)($context['overlap_count'] ?? 0));
        if ($overlapCount > 0) {
            $this->issue($issues, 'trip_overlap', 'critical', 'Jízda se časově překrývá s jiným záznamem stejného vozidla.', 45, [
                'overlap_count' => $overlapCount,
            ]);
        }

        $badPoints = max(0, (int)($trip['telemetry_bad_point_count'] ?? $context['telemetry_bad_point_count'] ?? 0));
        if ($badPoints > 0) {
            $penalty = min(40, 12 + $badPoints * 6);
            $this->issue($issues, 'bad_telemetry_points', $badPoints >= 3 ? 'critical' : 'warning', 'Jízda obsahuje podezřelé telemetry body.', $penalty, [
                'count' => $badPoints,
            ]);
        }

        $gapMinutes = $this->number($trip['telemetry_gap_minutes'] ?? $context['telemetry_gap_minutes'] ?? null);
        if ($gapMinutes !== null && $gapMinutes > 120) {
            $this->issue($issues, 'trip_telemetry_gap_major', 'warning', 'Během jízdy chybí více než 2 hodiny telemetrie.', 25, [
                'gap_minutes' => round($gapMinutes, 0),
            ]);
        } elseif ($gapMinutes !== null && $gapMinutes > 30) {
            $this->issue($issues, 'trip_telemetry_gap', 'notice', 'Během jízdy je delší mezera v telemetrii.', 8, [
                'gap_minutes' => round($gapMinutes, 0),
            ]);
        }

        $maxSpeed = $this->number($trip['max_speed_kmh'] ?? $context['max_speed_kmh'] ?? null);
        if ($maxSpeed !== null && $maxSpeed > self::MAX_PLAUSIBLE_SPEED_KMH) {
            $this->issue($issues, 'trip_max_speed_invalid', 'critical', 'Telemetrie jízdy obsahuje rychlost přes 300 km/h.', 45);
        }

        return $this->result($issues);
    }

    /** @param array<string,mixed> $assessment */
    public function issuesJson(array $assessment): string
    {
        $json = json_encode($assessment['issues'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '[]';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function telemetryRow(array $row): array
    {
        if (isset($row['telemetry']) && is_array($row['telemetry'])) {
            $telemetry = $row['telemetry'];
            $telemetry['observed_at'] = $row['observed_at'] ?? $telemetry['observed_at'] ?? null;
            $telemetry['received_at'] = $row['received_at'] ?? $telemetry['received_at'] ?? null;
            return $telemetry;
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function assessCoordinates(array $row, array &$issues): void
    {
        $lat = $this->number($row['latitude'] ?? null);
        $lng = $this->number($row['longitude'] ?? null);
        if ($lat === null && $lng === null) {
            return;
        }
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $this->issue($issues, 'gps_invalid', 'critical', 'GPS souřadnice jsou neplatné.', 45);
        }
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function gpsDistanceKm(array $a, array $b): ?float
    {
        $lat1 = $this->number($a['latitude'] ?? null);
        $lon1 = $this->number($a['longitude'] ?? null);
        $lat2 = $this->number($b['latitude'] ?? null);
        $lon2 = $this->number($b['longitude'] ?? null);
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }
        if ($lat1 < -90 || $lat1 > 90 || $lat2 < -90 || $lat2 > 90 || $lon1 < -180 || $lon1 > 180 || $lon2 < -180 || $lon2 > 180) {
            return null;
        }

        $earthRadius = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $h = sin($dLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        return 2 * $earthRadius * asin(min(1.0, sqrt($h)));
    }

    /** @param array<string,mixed> $row */
    private function bestTimestamp(array $row): int
    {
        $observed = $this->timestamp($row['observed_at'] ?? null);
        if ($observed > 0) {
            return $observed;
        }
        return $this->timestamp($row['received_at'] ?? null);
    }

    /** @param array<int,array<string,mixed>> $issues @param array<string,mixed> $details */
    private function issue(
        array &$issues,
        string $code,
        string $severity,
        string $message,
        int $penalty,
        array $details = []
    ): void {
        $issues[] = [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'penalty' => max(0, $penalty),
            'details' => $details,
        ];
    }

    /** @param array<int,array<string,mixed>> $issues @return array{score:int,status:string,issues:array<int,array<string,mixed>>} */
    private function result(array $issues): array
    {
        $penalty = 0;
        $critical = false;
        foreach ($issues as $issue) {
            $penalty += (int)($issue['penalty'] ?? 0);
            if ((string)($issue['severity'] ?? '') === 'critical') {
                $critical = true;
            }
        }

        $score = max(0, min(100, 100 - $penalty));
        if ($critical || $score < 60) {
            $status = 'bad';
        } elseif ($score < 85) {
            $status = 'warning';
        } else {
            $status = 'good';
        }

        return [
            'score' => $score,
            'status' => $status,
            'issues' => $issues,
        ];
    }

    /** @param mixed $value */
    private function timestamp($value): int
    {
        $text = trim((string)$value);
        if ($text === '') {
            return 0;
        }
        $timestamp = strtotime($text);
        return $timestamp !== false ? (int)$timestamp : 0;
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}

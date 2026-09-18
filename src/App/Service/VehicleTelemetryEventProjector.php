<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Převádí změny normalizované telemetrie na doménové události.
 *
 * Tato vrstva je provider-agnostická. Anomaly Engine může později pracovat
 * nad stejným event streamem bez znalosti konkrétního OEM API nebo automobilky.
 */
final class VehicleTelemetryEventProjector
{
    /**
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $current
     * @return array<int,array<string,mixed>>
     */
    public function project(?array $previous, array $current): array
    {
        $events = [];
        $occurredAt = (string)($current['observed_at'] ?? date('Y-m-d H:i:s'));
        $telemetry = isset($current['telemetry']) && is_array($current['telemetry']) ? $current['telemetry'] : [];

        if ($previous === null) {
            $events[] = $this->event(
                'telemetry_started',
                'info',
                'Začal sběr online telemetrie',
                $occurredAt,
                ['soc_pct' => $telemetry['soc_pct'] ?? null, 'odometer_km' => $telemetry['odometer_km'] ?? null]
            );
            return $events;
        }

        $previousCharging = $this->nullableBool($previous['is_charging'] ?? null);
        $currentCharging = $this->nullableBool($telemetry['is_charging'] ?? null);
        if ($previousCharging !== null && $currentCharging !== null && $previousCharging !== $currentCharging) {
            $events[] = $this->event(
                $currentCharging ? 'charging_started' : 'charging_stopped',
                'info',
                $currentCharging ? 'Začalo nabíjení' : 'Nabíjení skončilo',
                $occurredAt,
                ['soc_pct' => $telemetry['soc_pct'] ?? null, 'charging_power_kw' => $telemetry['charging_power_kw'] ?? null]
            );
        }

        $previousPlugged = $this->nullableBool($previous['is_plugged_in'] ?? null);
        $currentPlugged = $this->nullableBool($telemetry['is_plugged_in'] ?? null);
        if ($previousPlugged !== null && $currentPlugged !== null && $previousPlugged !== $currentPlugged) {
            $events[] = $this->event(
                $currentPlugged ? 'vehicle_plugged_in' : 'vehicle_unplugged',
                'info',
                $currentPlugged ? 'Vozidlo bylo připojeno k nabíjení' : 'Vozidlo bylo odpojeno od nabíjení',
                $occurredAt,
                ['soc_pct' => $telemetry['soc_pct'] ?? null]
            );
        }

        $previousSoc = $this->nullableFloat($previous['soc_pct'] ?? null);
        $currentSoc = $this->nullableFloat($telemetry['soc_pct'] ?? null);
        if ($previousSoc !== null && $currentSoc !== null) {
            if ($previousSoc >= 20.0 && $currentSoc < 20.0) {
                $events[] = $this->event(
                    'low_soc',
                    'warning',
                    'Baterie klesla pod 20 %',
                    $occurredAt,
                    ['soc_pct' => $currentSoc, 'range_km' => $telemetry['range_km'] ?? null]
                );
            }
            if ($previousSoc < 80.0 && $currentSoc >= 80.0 && $currentCharging === true) {
                $events[] = $this->event(
                    'charge_target_80',
                    'notice',
                    'Nabíjení dosáhlo 80 %',
                    $occurredAt,
                    ['soc_pct' => $currentSoc]
                );
            }
        }

        $previousOdometer = $this->nullableFloat($previous['odometer_km'] ?? null);
        $currentOdometer = $this->nullableFloat($telemetry['odometer_km'] ?? null);
        if ($previousOdometer !== null && $currentOdometer !== null && $currentOdometer > $previousOdometer) {
            $previousMilestone = (int)floor($previousOdometer / 1000);
            $currentMilestone = (int)floor($currentOdometer / 1000);
            if ($currentMilestone > $previousMilestone) {
                $events[] = $this->event(
                    'odometer_milestone',
                    'notice',
                    'Vozidlo překročilo ' . number_format($currentMilestone * 1000, 0, ',', ' ') . ' km',
                    $occurredAt,
                    ['odometer_km' => $currentOdometer]
                );
            }
        }

        return $events;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function event(string $type, string $severity, string $title, string $occurredAt, array $data): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'occurred_at' => $occurredAt,
            'data' => $data,
        ];
    }

    private function nullableBool($value): ?bool
    {
        if ($value === null) {
            return null;
        }
        return (bool)$value;
    }

    private function nullableFloat($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}

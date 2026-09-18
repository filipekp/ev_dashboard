<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Tesla;

/**
 * Překlad Tesla Fleet API vehicle_data do společného EV Stats modelu.
 *
 * Tesla vehicle_data používá u dojezdu a odometru míle; normalizátor je
 * převádí na kilometry ještě před uložením do telemetry vrstvy.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class TeslaVehicleNormalizer
{
    private const MILES_TO_KM = 1.609344;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function normalize(array $payload): array
    {
        $vehicle = isset($payload['response']) && is_array($payload['response']) ? $payload['response'] : $payload;
        $charge = self::arrayValue($vehicle, 'charge_state');
        $state = self::arrayValue($vehicle, 'vehicle_state');
        $drive = self::arrayValue($vehicle, 'drive_state');
        $climate = self::arrayValue($vehicle, 'climate_state');

        $chargeState = strtolower(trim((string)($charge['charging_state'] ?? $charge['charge_state'] ?? '')));
        $cable = trim((string)($charge['conn_charge_cable'] ?? ''));
        $isCharging = $chargeState !== '' ? in_array($chargeState, ['charging', 'starting'], true) : null;
        $isPlugged = null;
        if ($chargeState !== '') {
            $isPlugged = !in_array($chargeState, ['disconnected'], true);
        }
        if ($cable !== '') {
            $normalizedCable = strtolower($cable);
            $isPlugged = !in_array($normalizedCable, ['<invalid>', 'invalid', 'none', ''], true);
        }

        $rangeMiles = self::firstNumber([
            $charge['est_battery_range'] ?? null,
            $charge['battery_range'] ?? null,
            $charge['ideal_battery_range'] ?? null,
        ]);
        $odometerMiles = self::firstNumber([$state['odometer'] ?? null]);
        $observedAt = self::latestTimestamp([
            $charge['timestamp'] ?? null,
            $state['timestamp'] ?? null,
            $drive['timestamp'] ?? null,
            $climate['timestamp'] ?? null,
            $vehicle['timestamp'] ?? null,
        ]);

        return [
            'external_id' => self::firstString([$vehicle['vin'] ?? null, $vehicle['id_s'] ?? null, $vehicle['id'] ?? null]),
            'vin' => self::firstString([$vehicle['vin'] ?? null]),
            'name' => self::firstString([$vehicle['display_name'] ?? null, 'Tesla']),
            'vendor' => 'TESLA',
            'capabilities' => [
                'odometer' => $odometerMiles !== null,
                'charging' => $charge !== [],
                'parking_position' => isset($drive['latitude']) || isset($drive['longitude']),
                'climate' => $climate !== [],
                'remote_commands' => false,
            ],
            'observed_at' => $observedAt ?: date('Y-m-d H:i:s'),
            'telemetry' => [
                'soc_pct' => self::firstNumber([
                    $charge['usable_battery_level'] ?? null,
                    $charge['battery_level'] ?? null,
                ]),
                'range_km' => $rangeMiles !== null ? $rangeMiles * self::MILES_TO_KM : null,
                'odometer_km' => $odometerMiles !== null ? $odometerMiles * self::MILES_TO_KM : null,
                'latitude' => self::firstNumber([$drive['latitude'] ?? null]),
                'longitude' => self::firstNumber([$drive['longitude'] ?? null]),
                'is_charging' => $isCharging,
                'is_plugged_in' => $isPlugged,
                'charging_power_kw' => self::firstNumber([$charge['charger_power'] ?? null]),
                'battery_temperature_c' => null,
                'fuel_level_pct' => null,
            ],
            'raw' => $payload,
        ];
    }

    /** @param array<string,mixed> $array @return array<string,mixed> */
    private static function arrayValue(array $array, string $key): array
    {
        return isset($array[$key]) && is_array($array[$key]) ? $array[$key] : [];
    }

    /** @param array<int,mixed> $values */
    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string)$value;
            }
        }
        return null;
    }

    /** @param array<int,mixed> $values */
    private static function firstNumber(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    /** @param array<int,mixed> $values */
    private static function latestTimestamp(array $values): ?string
    {
        $latest = null;
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $number = (float)$value;
                $timestamp = $number > 9999999999 ? (int)floor($number / 1000) : (int)$number;
            } elseif (is_string($value) && trim($value) !== '') {
                $parsed = strtotime($value);
                if ($parsed === false) {
                    continue;
                }
                $timestamp = $parsed;
            } else {
                continue;
            }
            if ($latest === null || $timestamp > $latest) {
                $latest = $timestamp;
            }
        }
        return $latest !== null ? date('Y-m-d H:i:s', $latest) : null;
    }
}

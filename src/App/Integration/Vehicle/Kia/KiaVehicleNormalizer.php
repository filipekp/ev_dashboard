<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Kia;

/**
 * Převádí odpovědi Kia/Pleos Vehicle Data API do společného EV Stats snapshotu.
 *
 * Normalizátor je záměrně tolerantní vůči rozdílům mezi modely. Používá pouze
 * známá pole aktuálních Pleos status endpointů a chybějící hodnoty ponechá NULL.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaVehicleNormalizer
{
    /**
     * @param array<string,mixed> $payloads
     * @param array<string,string> $partialErrors
     * @return array<string,mixed>
     */
    public static function normalize(string $vin, array $payloads, array $partialErrors = []): array
    {
        $battery = self::data($payloads['battery'] ?? []);
        $location = self::data($payloads['location'] ?? []);
        $driving = self::data($payloads['driving'] ?? []);
        $powertrain = self::data($payloads['powertrain'] ?? []);
        $status = self::data($payloads['status'] ?? []);

        $charge = isset($battery['charge']) && is_array($battery['charge']) ? $battery['charge'] : [];
        $locationData = isset($location['location']) && is_array($location['location']) ? $location['location'] : [];

        $soc = self::number($charge['stateOfCharge'] ?? null, 0.0, 100.0);
        $isCharging = array_key_exists('charging', $charge) ? self::boolValue($charge['charging']) : null;
        $plugin = strtolower(trim((string)($charge['plugin'] ?? '')));
        $isPlugged = $plugin === '' || $plugin === 'invalid' ? null : $plugin === 'connected';

        $odometer = self::firstNumericRecursive($driving, [
            'odometer',
            'odometerKm',
            'odometerValue',
            'cumulativeMileage',
            'totalMileage',
            'mileage',
        ]);
        $range = self::firstNumericRecursive($powertrain, [
            'distanceToEmpty',
            'remainingRange',
            'electricRange',
            'evRange',
            'range',
        ]);
        $fuelLevel = self::firstNumericRecursive($powertrain, [
            'fuelLevelPercent',
            'fuelLevelPct',
            'fuelLevel',
        ]);
        if ($fuelLevel !== null && $fuelLevel > 100) {
            $fuelLevel = null;
        }
        $batteryTemperature = self::firstNumericRecursive($powertrain, [
            'batteryTemperature',
            'batteryTemp',
            'batteryTemperatureCelsius',
        ]);

        $observedAt = self::latestTimestamp([
            $battery['timestamp'] ?? null,
            $location['timestamp'] ?? null,
            $driving['timestamp'] ?? null,
            $powertrain['timestamp'] ?? null,
            $status['timestamp'] ?? null,
        ]);

        return [
            'external_id' => strtoupper(trim($vin)),
            'vin' => strtoupper(trim($vin)),
            'name' => 'Kia',
            'observed_at' => $observedAt,
            'telemetry' => [
                'soc_pct' => $soc,
                'range_km' => $range,
                'odometer_km' => $odometer,
                'latitude' => self::number($locationData['latitude'] ?? null, -90.0, 90.0),
                'longitude' => self::number($locationData['longitude'] ?? null, -180.0, 180.0),
                'is_charging' => $isCharging,
                'is_plugged_in' => $isPlugged,
                'charging_power_kw' => self::firstNumericRecursive($charge, ['chargingPower', 'chargingPowerKw', 'power']),
                'battery_temperature_c' => $batteryTemperature,
                'fuel_level_pct' => $fuelLevel,
            ],
            'capabilities' => [
                'battery' => isset($payloads['battery']),
                'location' => isset($payloads['location']),
                'driving' => isset($payloads['driving']),
                'powertrain' => isset($payloads['powertrain']),
                'vehicle_status' => isset($payloads['status']),
            ],
            'raw' => [
                'provider' => 'Kia Vehicle Data API / Pleos',
                'payloads' => $payloads,
                'partial_errors' => $partialErrors,
            ],
        ];
    }

    /** @param mixed $payload @return array<string,mixed> */
    private static function data($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        $data = $payload['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /** @param mixed $value */
    private static function number($value, ?float $min = null, ?float $max = null): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if ($min !== null && $number < $min) {
            return null;
        }
        if ($max !== null && $number > $max) {
            return null;
        }
        return $number;
    }

    /** @param mixed $value */
    private static function boolValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['true', 'yes', 'on', '1', 'charging', 'running'], true)) {
                return true;
            }
            if (in_array($value, ['false', 'no', 'off', '0', 'notcharging', 'stopped'], true)) {
                return false;
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     * @param string[] $keys
     */
    private static function firstNumericRecursive($value, array $keys): ?float
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                return (float)$value[$key];
            }
        }
        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }
            $found = self::firstNumericRecursive($child, $keys);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /** @param array<int,mixed> $values */
    private static function latestTimestamp(array $values): string
    {
        $latest = 0;
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false && $timestamp > $latest) {
                $latest = $timestamp;
            }
        }
        return date('Y-m-d H:i:s', $latest > 0 ? $latest : time());
    }
}

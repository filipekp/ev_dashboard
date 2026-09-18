<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Skoda;

/**
 * Překládá odpověď MyŠkoda Public API do jednotného EV Stats telemetry modelu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class SkodaVehicleNormalizer
{
    /** @param array<string,mixed> $response @return array<string,mixed> */
    public static function normalize(array $response): array
    {
        $vehicle = isset($response['vehicle']) && is_array($response['vehicle']) ? $response['vehicle'] : [];
        $charging = self::arrayValue($vehicle, 'charging');
        $chargingStatus = self::arrayValue($charging, 'status');
        $battery = self::arrayValue($chargingStatus, 'battery');
        $odometer = self::arrayValue($vehicle, 'odometer');
        $position = self::position($vehicle);
        $fuel = self::fuel($vehicle);

        $state = strtoupper((string)($chargingStatus['state'] ?? ''));
        $chargePowerKw = self::firstNumber([
            $chargingStatus['chargePowerInKw'] ?? null,
            $chargingStatus['chargingPowerInKw'] ?? null,
        ]);
        $isCharging = null;
        $isPluggedIn = null;
        if ($state !== '') {
            $isCharging = in_array($state, ['CHARGING', 'CHARGE_IN_PROGRESS'], true);
            if ($state === 'CONNECT_CABLE' || $state === 'DISCONNECTED') {
                $isPluggedIn = false;
            } elseif (in_array($state, [
                'CHARGING',
                'CHARGE_IN_PROGRESS',
                'CHARGING_INTERRUPTED',
                'READY_FOR_CHARGING',
                'CONNECTED',
                'FULLY_CHARGED',
            ], true)) {
                $isPluggedIn = true;
            }
        } elseif ($chargePowerKw !== null && $chargePowerKw > 0.0) {
            $isCharging = true;
            $isPluggedIn = true;
        }

        $observedAt = self::latestDateTime(self::collectTimestamps($vehicle));
        $rangeMeters = self::firstNumber([
            $battery['remainingCruisingRangeInMeters'] ?? null,
            self::path($vehicle, ['fuelStatus', 'remainingRangeInMeters']),
        ]);
        $rangeKm = $rangeMeters !== null ? $rangeMeters / 1000 : self::firstNumber([
            self::path($vehicle, ['fuelStatus', 'remainingRangeInKm']),
            self::path($vehicle, ['range', 'totalRangeInKm']),
        ]);

        return [
            'external_id' => self::firstString([$vehicle['vin'] ?? null]),
            'vin' => self::firstString([$vehicle['vin'] ?? null]),
            'name' => self::firstString([$vehicle['name'] ?? null, $vehicle['licensePlate'] ?? null, 'Škoda']),
            'vendor' => 'SKODA',
            'capabilities' => self::capabilities($vehicle, $response),
            'observed_at' => $observedAt ?: date('Y-m-d H:i:s'),
            'telemetry' => [
                'soc_pct' => self::firstNumber([
                    $battery['stateOfChargeInPercent'] ?? null,
                    self::path($vehicle, ['range', 'primaryEngineRange', 'currentSoCInPercent']),
                ]),
                'range_km' => $rangeKm,
                'odometer_km' => self::firstNumber([
                    $odometer['mileageInKm'] ?? null,
                    self::path($vehicle, ['vehicleHealth', 'mileageInKm']),
                ]),
                'latitude' => self::firstNumber([
                    $position['latitude'] ?? null,
                    $position['lat'] ?? null,
                ]),
                'longitude' => self::firstNumber([
                    $position['longitude'] ?? null,
                    $position['lon'] ?? null,
                    $position['lng'] ?? null,
                ]),
                'is_charging' => $isCharging,
                'is_plugged_in' => $isPluggedIn,
                'charging_power_kw' => $chargePowerKw,
                'battery_temperature_c' => self::firstNumber([
                    self::path($charging, ['status', 'battery', 'temperatureInCelsius']),
                    self::path($vehicle, ['battery', 'temperatureInCelsius']),
                ]),
                'fuel_level_pct' => self::firstNumber([
                    $fuel['fuelLevelInPercent'] ?? null,
                    $fuel['levelInPercent'] ?? null,
                    $fuel['stateOfChargeInPercent'] ?? null,
                ]),
            ],
            'raw' => $response,
        ];
    }

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $response @return array<string,mixed> */
    private static function capabilities(array $vehicle, array $response): array
    {
        $caps = [
            'odometer' => isset($vehicle['odometer']),
            'charging' => isset($vehicle['charging']),
            'air_conditioning' => isset($vehicle['airConditioning']),
            'status' => isset($vehicle['status']),
            'parking_position' => isset($vehicle['parkingPosition']) || isset($vehicle['position']) || isset($vehicle['positions']),
            'fuel' => isset($vehicle['fuelStatus']) || isset($vehicle['fuel']),
        ];
        $errors = isset($response['errors']) && is_array($response['errors']) ? $response['errors'] : [];
        if ($errors !== []) {
            $caps['partial_errors'] = count($errors);
        }
        return $caps;
    }

    /** @param array<string,mixed> $vehicle @return array<string,mixed> */
    private static function position(array $vehicle): array
    {
        foreach (['parkingPosition', 'position'] as $key) {
            if (isset($vehicle[$key]) && is_array($vehicle[$key])) {
                $candidate = $vehicle[$key];
                if (isset($candidate['gpsCoordinates']) && is_array($candidate['gpsCoordinates'])) {
                    return $candidate['gpsCoordinates'];
                }
                return $candidate;
            }
        }
        if (isset($vehicle['positions']) && is_array($vehicle['positions'])) {
            foreach ($vehicle['positions'] as $position) {
                if (!is_array($position)) {
                    continue;
                }
                if (isset($position['gpsCoordinates']) && is_array($position['gpsCoordinates'])) {
                    return $position['gpsCoordinates'];
                }
                return $position;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $vehicle @return array<string,mixed> */
    private static function fuel(array $vehicle): array
    {
        foreach (['fuelStatus', 'fuel'] as $key) {
            if (isset($vehicle[$key]) && is_array($vehicle[$key])) {
                return $vehicle[$key];
            }
        }
        return [];
    }

    /** @param array<string,mixed> $vehicle @return array<int,mixed> */
    private static function collectTimestamps(array $vehicle): array
    {
        $result = [];
        $walk = static function ($value) use (&$walk, &$result): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(?:captured|updated|timestamp|at)$/i', $key) === 1 && is_string($item)) {
                    $result[] = $item;
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($vehicle);
        return $result;
    }

    /** @param array<string,mixed> $array @return array<string,mixed> */
    private static function arrayValue(array $array, string $key): array
    {
        return isset($array[$key]) && is_array($array[$key]) ? $array[$key] : [];
    }

    /** @param array<string,mixed> $array @param string[] $path @return mixed */
    private static function path(array $array, array $path)
    {
        $value = $array;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /** @param array<int,mixed> $values */
    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value) || is_float($value)) {
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
    private static function latestDateTime(array $values): ?string
    {
        $latest = null;
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                continue;
            }
            if ($latest === null || $timestamp > $latest) {
                $latest = $timestamp;
            }
        }
        return $latest !== null ? date('Y-m-d H:i:s', $latest) : null;
    }
}

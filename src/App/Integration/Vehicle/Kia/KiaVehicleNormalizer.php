<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Kia;

/**
 * Převádí odpovědi Kia/Pleos Vehicle Data API do společného EV Stats snapshotu.
 *
 * Normalizace vychází z konkrétní struktury aktuálních Pleos endpointů
 * /batteries, /locations, /driving, /powertrains a /status. Chybějící nebo pro
 * daný model nepodporované hodnoty se ukládají jako NULL.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaVehicleNormalizer
{
    private const MILES_TO_KM = 1.609344;
    private const FEET_TO_KM = 0.0003048;

    /**
     * @param array<string,mixed> $payloads
     * @param array<string,string> $partialErrors
     * @return array<string,mixed>
     */
    public static function normalize(
        string $vin,
        array $payloads,
        array $partialErrors = [],
        string $powertrainType = ''
    ): array
    {
        $battery = self::data($payloads['battery'] ?? []);
        $location = self::data($payloads['location'] ?? []);
        $driving = self::data($payloads['driving'] ?? []);
        $powertrain = self::data($payloads['powertrain'] ?? []);
        $status = self::data($payloads['status'] ?? []);

        $charge = self::arrayValue($battery, 'charge');
        $locationData = self::arrayValue($location, 'location');
        $odometerData = self::arrayValue($driving, 'odometer');
        $transmission = self::arrayValue($driving, 'transmission');
        $ignition = self::arrayValue($driving, 'ignition');
        $climate = self::arrayValue($status, 'climateControl');
        $windshield = self::arrayValue($status, 'windshield');
        $outsideTemperature = self::arrayValue($status, 'outsideTemperature');
        $targetSoc = self::arrayValue($charge, 'targetStateOfCharge');

        $normalizedPowertrain = strtoupper(trim($powertrainType));
        $hasFuelSystem = $normalizedPowertrain === '' || in_array(
            $normalizedPowertrain,
            ['PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'],
            true
        );

        $soc = self::number($charge['stateOfCharge'] ?? null, 0.0, 100.0);
        if ($soc === null) {
            $mainBattery = self::typedEntry($powertrain['batteries'] ?? [], 'main');
            $soc = self::number($mainBattery['remainRatio'] ?? null, 0.0, 100.0);
        }

        $chargingStatus = self::nullableState($charge['status'] ?? null);
        $isCharging = array_key_exists('charging', $charge)
            ? self::boolValue($charge['charging'])
            : self::chargingState($chargingStatus);
        $pluginStatus = self::nullableState($charge['plugin'] ?? null);
        $isPlugged = self::pluginState($pluginStatus);

        $odometer = self::distanceToKm($odometerData);
        $range = self::rangeToKm($powertrain['distanceToEmpties'] ?? [], $normalizedPowertrain);

        $fuelLevel = null;
        if ($hasFuelSystem) {
            $engine = self::arrayValue($powertrain, 'engine');
            $fuelLevel = self::number($engine['fuelLevel'] ?? null, 0.0, 100.0);
        }

        $batteryTemperature = self::firstNumericRecursive($powertrain, [
            'batteryTemperature',
            'batteryTemp',
            'batteryTemperatureCelsius',
        ]);

        $climateStatus = self::nullableState($climate['status'] ?? null);
        $climateMode = self::nullableState($climate['mode'] ?? null);
        $climateTemperature = self::temperatureToC(self::arrayValue($climate, 'temperature'));
        $outsideTemperatureC = self::temperatureToC($outsideTemperature);
        $locationSpeed = self::speedToKmh(self::arrayValue($locationData, 'speed'));

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
                'vehicle_speed_kmh' => $locationSpeed,
                'is_charging' => $isCharging,
                'is_plugged_in' => $isPlugged,
                'charging_power_kw' => self::firstNumericRecursive($charge, ['chargingPower', 'chargingPowerKw', 'power']),
                'charging_status' => $chargingStatus,
                'charging_plugin_status' => $pluginStatus,
                'charging_target_standard_pct' => self::number($targetSoc['standard'] ?? null, 0.0, 100.0),
                'charging_target_quick_pct' => self::number($targetSoc['quick'] ?? null, 0.0, 100.0),
                'charging_remaining_minutes' => self::integer($charge['remainTime'] ?? null, 0, 65535),
                'battery_temperature_c' => $batteryTemperature,
                'fuel_level_pct' => $fuelLevel,
                'ignition_status' => self::nullableState($ignition['status'] ?? null),
                'sleep_mode' => self::nullableState($driving['sleepMode'] ?? null),
                'is_parked' => array_key_exists('parkingPosition', $transmission)
                    ? self::boolValue($transmission['parkingPosition'])
                    : null,
                'air_conditioning_state' => $climateStatus,
                'air_conditioning_target_c' => $climateTemperature,
                'climate_control_mode' => $climateMode,
                'climate_blower_speed' => self::integer($climate['speed'] ?? null, -1, 8),
                'windshield_defrost_state' => self::nullableState($windshield['defrost'] ?? null),
                'steering_wheel_heat_state' => self::nullableState($status['steeringWheelHeat'] ?? null),
                'outside_temperature_c' => $outsideTemperatureC,
            ],
            'capabilities' => [
                'battery' => isset($payloads['battery']),
                'location' => isset($payloads['location']),
                'driving' => isset($payloads['driving']),
                'powertrain' => isset($payloads['powertrain']),
                'vehicle_status' => isset($payloads['status']),
                'charging' => $charge !== [],
                'climate_control' => $climate !== [],
                'fuel' => $hasFuelSystem && $fuelLevel !== null,
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

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function arrayValue(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /** @param mixed $items @return array<string,mixed> */
    private static function typedEntry($items, string $type): array
    {
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (strcasecmp(trim((string)($item['type'] ?? '')), $type) === 0) {
                return $item;
            }
        }
        return [];
    }

    /** @param mixed $entries */
    private static function rangeToKm($entries, string $powertrainType): ?float
    {
        if (!is_array($entries)) {
            return null;
        }

        $preferredTypes = ['EV', 'PHEV', 'HEV', 'ICE'];
        if ($powertrainType === 'PHEV') {
            $preferredTypes = ['PHEV', 'EV', 'ICE', 'HEV'];
        } elseif ($powertrainType === 'HEV') {
            $preferredTypes = ['HEV', 'ICE', 'EV', 'PHEV'];
        } elseif (in_array($powertrainType, ['PETROL', 'DIESEL', 'LPG', 'CNG'], true)) {
            $preferredTypes = ['ICE', 'HEV', 'PHEV', 'EV'];
        }

        foreach ($preferredTypes as $type) {
            $entry = self::typedEntry($entries, $type);
            if ($entry === []) {
                continue;
            }
            $distance = self::distanceToKm($entry);
            if ($distance !== null) {
                return $distance;
            }
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $distance = self::distanceToKm($entry);
            if ($distance !== null) {
                return $distance;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $distance */
    private static function distanceToKm(array $distance): ?float
    {
        $value = self::number($distance['value'] ?? null, 0.0);
        if ($value === null) {
            return null;
        }

        $unit = strtolower(trim((string)($distance['unit'] ?? 'km')));
        if ($unit === '' || $unit === 'km' || $unit === 'kilometer' || $unit === 'kilometers') {
            return $value;
        }
        if ($unit === 'mile' || $unit === 'miles' || $unit === 'mi') {
            return $value * self::MILES_TO_KM;
        }
        if ($unit === 'meter' || $unit === 'meters' || $unit === 'm') {
            return $value / 1000.0;
        }
        if ($unit === 'feet' || $unit === 'foot' || $unit === 'ft') {
            return $value * self::FEET_TO_KM;
        }

        return null;
    }

    /** @param array<string,mixed> $speed */
    private static function speedToKmh(array $speed): ?float
    {
        $value = self::number($speed['value'] ?? null, 0.0);
        if ($value === null) {
            return null;
        }
        $unit = strtolower(trim((string)($speed['unit'] ?? 'kph')));
        if ($unit === 'kph' || $unit === 'kmh' || $unit === 'km/h' || $unit === '') {
            return $value;
        }
        if ($unit === 'mph') {
            return $value * self::MILES_TO_KM;
        }
        if ($unit === 'mps' || $unit === 'm/s') {
            return $value * 3.6;
        }
        return null;
    }

    /** @param array<string,mixed> $temperature */
    private static function temperatureToC(array $temperature): ?float
    {
        $value = self::number($temperature['value'] ?? null, -100.0, 150.0);
        if ($value === null) {
            return null;
        }
        $unit = strtoupper(trim((string)($temperature['unit'] ?? 'C')));
        if ($unit === 'F' || $unit === 'FAHRENHEIT') {
            return ($value - 32.0) * 5.0 / 9.0;
        }
        return $value;
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
    private static function integer($value, int $min, int $max): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (int)$value;
        return $number >= $min && $number <= $max ? $number : null;
    }

    /** @param mixed $value */
    private static function integer($value, int $min, int $max): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (int)$value;
        return $number >= $min && $number <= $max ? $number : null;
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
            if (in_array($value, ['true', 'yes', 'on', '1', 'charging', 'running', 'connected'], true)) {
                return true;
            }
            if (in_array($value, ['false', 'no', 'off', '0', 'notcharging', 'stopped', 'disconnected'], true)) {
                return false;
            }
        }
        return null;
    }

    private static function chargingState(?string $status): ?bool
    {
        if ($status === null) {
            return null;
        }
        $value = strtolower($status);
        if (in_array($value, ['charging', 'fastcharging', 'wirelesscharging'], true)) {
            return true;
        }
        if (in_array($value, ['notcharging', 'v2loperating', 'v2lstop', 'v2xoperating', 'reservedcharging'], true)) {
            return false;
        }
        return null;
    }

    private static function pluginState(?string $status): ?bool
    {
        if ($status === null) {
            return null;
        }
        $value = strtolower($status);
        if ($value === 'connected') {
            return true;
        }
        if ($value === 'disconnected') {
            return false;
        }
        return null;
    }

    /** @param mixed $value */
    private static function nullableState($value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' || strcasecmp($value, 'invalid') === 0 ? null : $value;
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

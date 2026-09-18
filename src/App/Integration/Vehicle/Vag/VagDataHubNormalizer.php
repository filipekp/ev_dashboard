<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Vag;

/**
 * Normalizátor Volkswagen Group Data Hub / EU Data Act payloadů.
 *
 * Podporuje jak běžný JSON objekt, tak seznam datových bodů ve tvaru
 * dataFieldName/key + value + timestampUtc. Celý vendor payload se zachová.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class VagDataHubNormalizer
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function normalize(array $payload, string $vendor): array
    {
        $flat = [];
        $timestamps = [];
        self::flatten($payload, '', $flat, $timestamps);
        self::collectDataPoints($payload, $flat, $timestamps);

        $vin = self::firstString($flat, [
            'vin', 'vehicle.vin', 'vehicleidentificationnumber', 'vehicle_identification_number',
        ]);
        $soc = self::number($flat, [
            'state_of_charge', 'stateofcharge', 'hv_soc', 'hvsoc', 'battery_level_hv.value',
            'batterylevelhvvalue', 'battery_level', 'soc',
        ]);
        $rangeKm = self::distance($flat, [
            'cruising_range_combined', 'cruisingrangecombined', 'cruising_range_primary_engine',
            'cruisingrangeprimaryengine', 'electric_range', 'electricrange', 'range',
            '0ca40e18-0564-3eda-bcc0-7aee9ef44f04',
        ]);
        $odometerKm = self::distance($flat, [
            'mileage.value', 'mileage', 'odometer', 'total_mileage', 'totalmileage',
        ]);
        $chargeState = strtolower((string)self::value($flat, [
            'charging_state_report.current_charge_state', 'charging_state', 'chargingstate',
            'current_charge_state', 'charge_state',
        ]));
        $plugState = strtolower((string)self::value($flat, [
            'charging_plug1_connectionstate', 'plug_state', 'plugstate', 'connectionstate',
        ]));

        $isCharging = null;
        if ($chargeState !== '') {
            $isCharging = strpos($chargeState, 'charg') !== false
                && strpos($chargeState, 'not') === false
                && strpos($chargeState, 'stop') === false
                && strpos($chargeState, 'disconnect') === false;
        }
        $isPlugged = null;
        if ($plugState !== '') {
            $isPlugged = strpos($plugState, 'disconnect') === false
                && strpos($plugState, 'unplug') === false
                && !in_array($plugState, ['false', '0', 'none'], true);
        } elseif ($chargeState !== '') {
            $isPlugged = strpos($chargeState, 'disconnect') === false;
        }

        return [
            'external_id' => $vin,
            'vin' => $vin,
            'name' => self::firstString($flat, ['vehiclename', 'vehicle.name', 'model', 'modelname']) ?: ucfirst(strtolower($vendor)),
            'vendor' => strtoupper($vendor),
            'capabilities' => [
                'odometer' => $odometerKm !== null,
                'charging' => $soc !== null || $chargeState !== '',
                'parking_position' => self::number($flat, ['latitude', 'position.latitude']) !== null,
                'fuel' => self::number($flat, ['fuel_level', 'fuellevel', 'fuel_level_percent']) !== null,
                'remote_commands' => false,
            ],
            'observed_at' => self::latestTimestamp($timestamps) ?: date('Y-m-d H:i:s'),
            'telemetry' => [
                'soc_pct' => $soc,
                'range_km' => $rangeKm,
                'odometer_km' => $odometerKm,
                'latitude' => self::number($flat, ['position.latitude', 'latitude', 'gps.latitude']),
                'longitude' => self::number($flat, ['position.longitude', 'longitude', 'gps.longitude', 'lng']),
                'is_charging' => $isCharging,
                'is_plugged_in' => $isPlugged,
                'charging_power_kw' => self::number($flat, ['charging_power_kw', 'chargingpowerkw', 'charging_power', 'chargingpower']),
                'battery_temperature_c' => self::number($flat, ['battery_temperature', 'batterytemperature', 'battery_temp']),
                'fuel_level_pct' => self::number($flat, ['fuel_level_percent', 'fuellevelpercent', 'fuel_percentage', 'fuelpercentage']),
            ],
            'raw' => $payload,
        ];
    }

    /** @param mixed $value @param array<string,mixed> $flat @param array<int,mixed> $timestamps */
    private static function flatten($value, string $prefix, array &$flat, array &$timestamps): void
    {
        if (!is_array($value)) {
            if ($prefix !== '') {
                $flat[strtolower($prefix)] = $value;
                if (preg_match('/(?:time|timestamp|updated|captured|date)/i', $prefix) === 1) {
                    $timestamps[] = $value;
                }
            }
            return;
        }
        foreach ($value as $key => $item) {
            $name = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (is_array($item)) {
                self::flatten($item, $name, $flat, $timestamps);
            } else {
                $flat[strtolower($name)] = $item;
                if (preg_match('/(?:time|timestamp|updated|captured|date)/i', $name) === 1) {
                    $timestamps[] = $item;
                }
            }
        }
    }

    /** @param mixed $value @param array<string,mixed> $flat @param array<int,mixed> $timestamps */
    private static function collectDataPoints($value, array &$flat, array &$timestamps): void
    {
        if (!is_array($value)) {
            return;
        }
        $name = null;
        foreach (['dataFieldName', 'fieldName', 'key', 'name'] as $key) {
            if (isset($value[$key]) && (is_string($value[$key]) || is_numeric($value[$key]))) {
                $name = strtolower(trim((string)$value[$key]));
                break;
            }
        }
        if ($name !== null && array_key_exists('value', $value)) {
            $flat[$name] = $value['value'];
            if (isset($value['timestampUtc'])) {
                $timestamps[] = $value['timestampUtc'];
            } elseif (isset($value['timestamp'])) {
                $timestamps[] = $value['timestamp'];
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                self::collectDataPoints($item, $flat, $timestamps);
            }
        }
    }

    /** @param array<string,mixed> $flat @param string[] $aliases @return mixed */
    private static function value(array $flat, array $aliases)
    {
        foreach ($aliases as $alias) {
            $needle = self::compact($alias);
            foreach ($flat as $key => $value) {
                $keyCompact = self::compact($key);
                if ($keyCompact === $needle || ($needle !== '' && substr($keyCompact, -strlen($needle)) === $needle)) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $flat @param string[] $aliases */
    private static function firstString(array $flat, array $aliases): ?string
    {
        $value = self::value($flat, $aliases);
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $text = trim((string)$value);
        return $text !== '' ? $text : null;
    }

    /** @param array<string,mixed> $flat @param string[] $aliases */
    private static function number(array $flat, array $aliases): ?float
    {
        $value = self::value($flat, $aliases);
        return is_numeric($value) ? (float)$value : null;
    }

    /** @param array<string,mixed> $flat @param string[] $aliases */
    private static function distance(array $flat, array $aliases): ?float
    {
        foreach ($aliases as $alias) {
            $needle = self::compact($alias);
            foreach ($flat as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                $keyCompact = self::compact($key);
                if ($keyCompact !== $needle && ($needle === '' || substr($keyCompact, -strlen($needle)) !== $needle)) {
                    continue;
                }
                $number = (float)$value;
                $lower = strtolower($key);
                if (self::keyImpliesMeters($lower)) {
                    return $number / 1000;
                }
                if (self::keyImpliesMiles($lower)) {
                    return $number * 1.609344;
                }
                return $number;
            }
        }
        return null;
    }

    private static function keyImpliesMeters(string $key): bool
    {
        if (strpos($key, 'odometer') !== false || strpos($key, 'kilometer') !== false || strpos($key, 'kilometre') !== false) {
            return false;
        }

        return preg_match('/(?:^|[._-])(?:m|meter|meters|metre|metres)(?:$|[._-])/', $key) === 1;
    }

    private static function keyImpliesMiles(string $key): bool
    {
        if (strpos($key, 'mileage') !== false) {
            return false;
        }

        return preg_match('/(?:^|[._-])(?:mi|mile|miles)(?:$|[._-])/', $key) === 1;
    }

    /** @param array<int,mixed> $timestamps */
    private static function latestTimestamp(array $timestamps): ?string
    {
        $latest = null;
        foreach ($timestamps as $value) {
            if (is_numeric($value)) {
                $number = (float)$value;
                $timestamp = $number > 9999999999 ? (int)floor($number / 1000) : (int)$number;
            } elseif (is_string($value) && trim($value) !== '') {
                $timestamp = strtotime($value);
                if ($timestamp === false) {
                    continue;
                }
            } else {
                continue;
            }
            if ($latest === null || $timestamp > $latest) {
                $latest = $timestamp;
            }
        }
        return $latest !== null ? date('Y-m-d H:i:s', $latest) : null;
    }

    private static function compact(string $value): string
    {
        return (string)preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}

<?php

declare(strict_types=1);

namespace App\Integration;

use DateTimeImmutable;
use RuntimeException;

/**
 * Generic CSV mapper pro zdroje bez nativního CSV pluginu.
 *
 * Profil mapování používá interní názvy polí jako klíče a názvy sloupců CSV
 * jako hodnoty. Volitelná sekce defaults doplní konstantní hodnoty a sekce
 * transforms dovolí základní převody bez spouštění uživatelského kódu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class GenericCsvMapper
{
    /** @var string[] */
    private const ALLOWED_FIELDS = [
        'started_at', 'ended_at', 'distance_km', 'driving_minutes', 'travel_minutes',
        'avg_speed_kmh', 'avg_consumption_kwh_100', 'consumed_kwh', 'start_soc', 'end_soc',
        'start_odometer_km', 'end_odometer_km', 'start_address', 'end_address',
        'start_lat', 'start_lng', 'end_lat', 'end_lng', 'total_cost', 'electricity_cost',
        'electricity_price_per_kwh', 'classification', 'trip_note',
    ];

    /**
     * @param array<string,string|null> $row
     * @param array<string,mixed>       $profile
     * @return array<string,mixed>
     */
    public function map(array $row, array $profile): array
    {
        $mapping = isset($profile['mapping']) && is_array($profile['mapping']) ? $profile['mapping'] : [];
        $defaults = isset($profile['defaults']) && is_array($profile['defaults']) ? $profile['defaults'] : [];
        $transforms = isset($profile['transforms']) && is_array($profile['transforms']) ? $profile['transforms'] : [];
        $result = [];

        foreach ($mapping as $target => $source) {
            if (!in_array($target, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            $value = $row[(string)$source] ?? null;
            $result[$target] = $this->transform($target, $value, $transforms[$target] ?? null);
        }

        foreach ($defaults as $target => $value) {
            if (in_array($target, self::ALLOWED_FIELDS, true) && (!array_key_exists($target, $result) || $result[$target] === null || $result[$target] === '')) {
                $result[$target] = $value;
            }
        }

        if (empty($result['started_at'])) {
            throw new RuntimeException('Generic CSV profil musí namapovat started_at.');
        }
        if (!isset($result['distance_km']) || (float)$result['distance_km'] < 0) {
            throw new RuntimeException('Generic CSV profil musí namapovat platnou distance_km.');
        }

        $result['source_format'] = 'generic';
        return $result;
    }

    /** @param mixed $value @param mixed $transform @return mixed */
    private function transform(string $field, $value, $transform)
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        if (is_array($transform)) {
            if (isset($transform['decimal_separator']) && $transform['decimal_separator'] === ',') {
                $raw = str_replace(',', '.', $raw);
            }
            if (isset($transform['multiply']) && is_numeric($raw)) {
                $raw = (string)((float)$raw * (float)$transform['multiply']);
            }
            if (isset($transform['date_format']) && in_array($field, ['started_at', 'ended_at'], true)) {
                $date = DateTimeImmutable::createFromFormat((string)$transform['date_format'], $raw);
                if ($date === false) {
                    throw new RuntimeException('Datum v Generic CSV neodpovídá nastavenému formátu.');
                }
                return $date->format('Y-m-d H:i:s');
            }
        }

        if (in_array($field, [
            'distance_km', 'driving_minutes', 'travel_minutes', 'avg_speed_kmh',
            'avg_consumption_kwh_100', 'consumed_kwh', 'start_soc', 'end_soc',
            'start_odometer_km', 'end_odometer_km', 'start_lat', 'start_lng',
            'end_lat', 'end_lng', 'total_cost', 'electricity_cost', 'electricity_price_per_kwh',
        ], true)) {
            $normalized = str_replace(',', '.', $raw);
            return is_numeric($normalized) ? (float)$normalized : null;
        }

        return $raw;
    }
}

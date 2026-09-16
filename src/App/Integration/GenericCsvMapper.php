<?php

declare(strict_types=1);

namespace App\Integration;

use DateTimeImmutable;
use RuntimeException;

/**
 * Univerzální mapper tabulkových importů do interního formátu jízd.
 */
final class GenericCsvMapper
{
    /** @var string[] */
    private const ALLOWED_FIELDS = [
        'started_at','ended_at','distance_km','driving_minutes','travel_minutes',
        'avg_speed_kmh','avg_consumption_kwh_100','consumed_kwh','fuel_consumed_l',
        'avg_fuel_consumption_l_100','start_soc','end_soc','start_odometer_km','end_odometer_km',
        'start_address','end_address','start_lat','start_lng','end_lat','end_lng','total_cost',
        'electricity_cost','electricity_price_per_kwh','classification','trip_note',
        'avg_aux_consumption_kwh_100','avg_recuperation_kwh_100'
    ];

    /** @return array<string,string> */
    public static function fieldLabels(): array
    {
        return [
            'started_at'=>'Začátek jízdy *','ended_at'=>'Konec jízdy','distance_km'=>'Vzdálenost (km) *',
            'driving_minutes'=>'Doba jízdy (min)','travel_minutes'=>'Celkový čas (min)','avg_speed_kmh'=>'Průměrná rychlost (km/h)',
            'avg_consumption_kwh_100'=>'Spotřeba (kWh/100 km)','consumed_kwh'=>'Spotřebovaná energie (kWh)',
            'fuel_consumed_l'=>'Spotřebované palivo (l/kg)','avg_fuel_consumption_l_100'=>'Spotřeba paliva (l/100 km)',
            'start_soc'=>'SoC na začátku (%)','end_soc'=>'SoC na konci (%)','start_odometer_km'=>'Tachometr na začátku (km)',
            'end_odometer_km'=>'Tachometr na konci (km)','start_address'=>'Start – adresa','end_address'=>'Cíl – adresa',
            'start_lat'=>'Start – latitude','start_lng'=>'Start – longitude','end_lat'=>'Cíl – latitude','end_lng'=>'Cíl – longitude',
            'total_cost'=>'Celkové náklady','electricity_cost'=>'Cena elektřiny','electricity_price_per_kwh'=>'Cena za kWh',
            'classification'=>'Klasifikace jízdy','trip_note'=>'Poznámka','avg_aux_consumption_kwh_100'=>'Pomocná spotřeba (kWh/100 km)',
            'avg_recuperation_kwh_100'=>'Rekuperace (kWh/100 km)',
        ];
    }

    /**
     * @param array<string,string|null> $row
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function map(array $row, array $profile): array
    {
        $mapping = isset($profile['mapping']) && is_array($profile['mapping']) ? $profile['mapping'] : [];
        $defaults = isset($profile['defaults']) && is_array($profile['defaults']) ? $profile['defaults'] : [];
        $transforms = isset($profile['transforms']) && is_array($profile['transforms']) ? $profile['transforms'] : [];
        $result = [];

        foreach ($mapping as $target => $source) {
            if (!in_array($target, self::ALLOWED_FIELDS, true) || trim((string)$source) === '') continue;
            $value = $row[(string)$source] ?? null;
            $result[$target] = $this->transform($target, $value, $transforms[$target] ?? null);
        }
        foreach ($defaults as $target => $value) {
            if (in_array($target, self::ALLOWED_FIELDS, true) && (!array_key_exists($target,$result) || $result[$target] === null || $result[$target] === '')) {
                $result[$target] = $value;
            }
        }

        if (empty($result['started_at'])) throw new RuntimeException('Musíte namapovat začátek jízdy.');
        if (!isset($result['distance_km']) || !is_numeric($result['distance_km']) || (float)$result['distance_km'] < 0) {
            throw new RuntimeException('Musíte namapovat platnou vzdálenost jízdy.');
        }

        $result['ended_at'] = !empty($result['ended_at']) ? $result['ended_at'] : $result['started_at'];
        $result['start_address'] = (string)($result['start_address'] ?? '');
        $result['end_address'] = (string)($result['end_address'] ?? '');
        $result['driving_minutes'] = max(0, (int)round((float)($result['driving_minutes'] ?? 0)));
        $result['travel_minutes'] = max($result['driving_minutes'], (int)round((float)($result['travel_minutes'] ?? $result['driving_minutes'])));
        $result['source_format'] = 'generic';
        $result['trip_hash'] = hash('sha256', implode('|', [
            (string)$result['started_at'], (string)$result['ended_at'], number_format((float)$result['distance_km'], 3, '.', ''),
            (string)$result['start_address'], (string)$result['end_address'], (string)($result['start_odometer_km'] ?? ''),
            (string)($result['end_odometer_km'] ?? '')
        ]));

        return $result;
    }

    /** @param mixed $value @param mixed $transform @return mixed */
    private function transform(string $field, $value, $transform)
    {
        if ($value === null) return null;
        $raw = trim((string)$value);
        if ($raw === '') return null;

        if (in_array($field, ['started_at','ended_at'], true)) {
            $format = is_array($transform) ? trim((string)($transform['date_format'] ?? '')) : '';
            if ($format !== '' && $format !== 'auto') {
                $date = DateTimeImmutable::createFromFormat($format, $raw);
                if ($date === false) throw new RuntimeException('Datum „'.$raw.'“ neodpovídá zvolenému formátu '.$format.'.');
                return $date->format('Y-m-d H:i:s');
            }
            if (is_numeric($raw) && (float)$raw > 1000) {
                $seconds = ((float)$raw - 25569) * 86400;
                return gmdate('Y-m-d H:i:s', (int)round($seconds));
            }
            try {
                return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                throw new RuntimeException('Datum „'.$raw.'“ se nepodařilo automaticky rozpoznat.');
            }
        }

        if (is_array($transform) && isset($transform['multiply']) && is_numeric(str_replace(',','.',$raw))) {
            $raw = (string)((float)str_replace(',','.',$raw) * (float)$transform['multiply']);
        }

        if (in_array($field, [
            'distance_km','driving_minutes','travel_minutes','avg_speed_kmh','avg_consumption_kwh_100','consumed_kwh',
            'fuel_consumed_l','avg_fuel_consumption_l_100','start_soc','end_soc','start_odometer_km','end_odometer_km',
            'start_lat','start_lng','end_lat','end_lng','total_cost','electricity_cost','electricity_price_per_kwh',
            'avg_aux_consumption_kwh_100','avg_recuperation_kwh_100'
        ], true)) {
            $normalized = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $raw);
            return is_numeric($normalized) ? (float)$normalized : null;
        }
        return $raw;
    }
}

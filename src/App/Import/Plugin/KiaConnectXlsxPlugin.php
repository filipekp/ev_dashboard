<?php

declare(strict_types=1);

namespace App\Import\Plugin;

use App\Import\TripImportPluginInterface;
use App\Import\XlsxArchive;
use DateTime;
use RuntimeException;

/**
 * Importní plugin pro XLSX export historie jízd z aplikace Kia Connect.
 *
 * Kia soubor neobsahuje VIN, stav tachometru, SoC ani adresy. Plugin proto
 * normalizuje pouze hodnoty, které export skutečně poskytuje, a dopočítává
 * průměrnou spotřebu, rekuperaci a rychlost.
 *
 * Kia uvádí spotřebovanou a rekuperovanou energii odděleně. Interní
 * consumed_kwh je v celé aplikaci normalizované netto zatížení baterie,
 * proto se pro Kia počítá jako spotřeba minus rekuperace. Rekuperace se
 * současně uchovává samostatně v avg_recuperation_kwh_100.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class KiaConnectXlsxPlugin implements TripImportPluginInterface
{
    /** @var string[] */
    private const REQUIRED_HEADERS = [
        'datum',
        'Čas zahájení',
        'Čas ukončení',
        'Vzdálenost (km)',
        'hodina(minuta)',
        'Spotřeba energie (kWh)',
        'Rekuperace (kWh)',
    ];

    public function key(): string
    {
        return 'kia_connect_xlsx';
    }

    public function supports(string $path, ?string $originalName = null): bool
    {
        if (!$this->isZipContainer($path)) {
            return false;
        }

        try {
            return $this->findHeaderRow($this->readRows($path)) !== null;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function inspect(string $path, ?string $originalName = null): array
    {
        $rows = $this->readRows($path);
        if ($this->findHeaderRow($rows) === null) {
            throw new RuntimeException('Soubor není podporovaný export jízd z Kia Connect.');
        }

        return [
            'vin' => null,
            'format' => 'kia_ev',
            'plugin_label' => 'Kia Connect',
            'manufacturer' => 'KIA',
            'suggested_name' => 'Kia EV',
            'battery_kwh' => 0.0,
            'battery_nominal_kwh' => 0.0,
            'requires_vehicle_selection' => true,
        ];
    }

    /** @return iterable<int,array<string,mixed>> */
    public function records(string $path): iterable
    {
        $rows = $this->readRows($path);
        $headerIndex = $this->findHeaderRow($rows);
        if ($headerIndex === null) {
            throw new RuntimeException('V XLSX nebyla nalezena tabulka jízd Kia Connect.');
        }

        $columns = [];
        foreach ($rows[$headerIndex] as $column => $value) {
            $columns[trim((string)$value)] = $column;
        }

        $count = count($rows);
        for ($index = $headerIndex + 1; $index < $count; $index++) {
            $trip = $this->parseRow($rows[$index], $columns);
            if ($trip !== null) {
                yield $trip;
            }
        }
    }

    /** @param array<string,string> $row @param array<string,string> $columns @return array<string,mixed>|null */
    private function parseRow(array $row, array $columns): ?array
    {
        $date = $this->cell($row, $columns, 'datum');
        $startTime = $this->cell($row, $columns, 'Čas zahájení');
        $endTime = $this->cell($row, $columns, 'Čas ukončení');

        if ($date === '' || $startTime === '' || $endTime === '') {
            return null;
        }

        $start = DateTime::createFromFormat('d-m-Y H:i', $date . ' ' . $startTime);
        $end = DateTime::createFromFormat('d-m-Y H:i', $date . ' ' . $endTime);
        if (!$start || !$end) {
            return null;
        }
        if ($end < $start) {
            $end->modify('+1 day');
        }

        $distance = $this->parseDistance($this->cell($row, $columns, 'Vzdálenost (km)'));
        $consumed = $this->numOrNull($this->cell($row, $columns, 'Spotřeba energie (kWh)'));
        $recuperated = $this->numOrNull($this->cell($row, $columns, 'Rekuperace (kWh)'));
        if ($distance === null || $consumed === null) {
            return null;
        }

        $minutes = $this->parseMinutes($this->cell($row, $columns, 'hodina(minuta)'));
        if ($minutes <= 0) {
            $minutes = max(0, (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60));
        }

        $recuperatedKwh = max(0.0, $recuperated ?? 0.0);
        $netConsumed = max(0.0, $consumed - $recuperatedKwh);
        $avgConsumption = $distance > 0 ? $netConsumed / $distance * 100 : null;
        $avgRecuperation = ($distance > 0 && $recuperated !== null) ? $recuperatedKwh / $distance * 100 : null;
        $avgSpeed = ($distance > 0 && $minutes > 0) ? $distance / ($minutes / 60) : null;

        return [
            'trip_hash' => hash('sha256', implode('|', [
                'kia_ev',
                $start->format('c'),
                $end->format('c'),
                (string)$distance,
                (string)$consumed,
                (string)$recuperated,
            ])),
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'classification' => $this->classification($row, $columns),
            'start_address' => '',
            'end_address' => '',
            'start_lat' => null,
            'start_lng' => null,
            'end_lat' => null,
            'end_lng' => null,
            'distance_km' => $distance,
            'start_odometer_km' => null,
            'end_odometer_km' => null,
            'driving_minutes' => $minutes,
            'travel_minutes' => $minutes,
            'avg_speed_kmh' => $avgSpeed,
            'consumed_kwh' => $netConsumed,
            'avg_consumption_kwh_100' => $avgConsumption,
            'start_soc' => null,
            'end_soc' => null,
            'public_charging_stops' => 0,
            'public_charge_soc_gained' => 0,
            'short_trip' => $distance <= 5 ? 1 : 0,
            'source_format' => 'kia_ev',
            'total_cost' => null,
            'total_cost_currency' => null,
            'electricity_cost' => null,
            'electricity_cost_currency' => null,
            'electricity_price_per_kwh' => null,
            'avg_aux_consumption_kwh_100' => null,
            'avg_recuperation_kwh_100' => $avgRecuperation,
        ];
    }

    private function isZipContainer(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        try {
            return fread($handle, 4) === "PK\x03\x04";
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int,array<string,string>> */
    private function readRows(string $path): array
    {
        $archive = new XlsxArchive($path);
        $sheetXml = $archive->get('xl/worksheets/sheet1.xml');
        if ($sheetXml === null) {
            throw new RuntimeException('XLSX neobsahuje první list.');
        }
        $sharedStrings = $this->readSharedStrings($archive);

        if (!preg_match_all(
            '/<(?:[A-Za-z0-9_]+:)?row\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_]+:)?row>/si',
            $sheetXml,
            $rowMatches
        )) {
            return [];
        }

        $rows = [];
        foreach ($rowMatches[1] as $rowXml) {
            $row = [];
            if (!preg_match_all(
                '/<(?:[A-Za-z0-9_]+:)?c\\b([^>]*)>(.*?)<\\/(?:[A-Za-z0-9_]+:)?c>/si',
                $rowXml,
                $cellMatches,
                PREG_SET_ORDER
            )) {
                $rows[] = $row;
                continue;
            }

            foreach ($cellMatches as $cellMatch) {
                $attributes = (string)$cellMatch[1];
                $cellXml = (string)$cellMatch[2];
                $reference = $this->xmlAttribute($attributes, 'r');
                if ($reference === null || !preg_match('/^([A-Z]+)/i', $reference, $matches)) {
                    continue;
                }

                $column = strtoupper($matches[1]);
                $type = $this->xmlAttribute($attributes, 't') ?? '';
                $raw = $this->xmlTagValue($cellXml, 'v') ?? '';

                if ($type === 's' && $raw !== '') {
                    $value = $sharedStrings[(int)$raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $this->xmlTextParts($cellXml);
                } else {
                    $value = $raw;
                }

                $row[$column] = trim($value);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return string[] */
    private function readSharedStrings(XlsxArchive $archive): array
    {
        $content = $archive->get('xl/sharedStrings.xml');
        if ($content === null) {
            return [];
        }

        if (!preg_match_all(
            '/<(?:[A-Za-z0-9_]+:)?si\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_]+:)?si>/si',
            $content,
            $matches
        )) {
            return [];
        }

        $strings = [];
        foreach ($matches[1] as $itemXml) {
            $strings[] = $this->xmlTextParts((string)$itemXml);
        }

        return $strings;
    }

    private function xmlAttribute(string $attributes, string $name): ?string
    {
        if (!preg_match(
            '/(?:^|\\s)' . preg_quote($name, '/') . '\\s*=\\s*(["\\\'])(.*?)\\1/si',
            $attributes,
            $matches
        )) {
            return null;
        }

        return $this->xmlDecode((string)$matches[2]);
    }

    private function xmlTagValue(string $xml, string $tag): ?string
    {
        if (!preg_match(
            '/<(?:[A-Za-z0-9_]+:)?' . preg_quote($tag, '/') . '\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_]+:)?' . preg_quote($tag, '/') . '>/si',
            $xml,
            $matches
        )) {
            return null;
        }

        return $this->xmlDecode(strip_tags((string)$matches[1]));
    }

    private function xmlTextParts(string $xml): string
    {
        if (!preg_match_all(
            '/<(?:[A-Za-z0-9_]+:)?t\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_]+:)?t>/si',
            $xml,
            $matches
        )) {
            return '';
        }

        $value = '';
        foreach ($matches[1] as $part) {
            $value .= $this->xmlDecode(strip_tags((string)$part));
        }

        return $value;
    }

    private function xmlDecode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @param array<int,array<string,string>> $rows */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $values = array_map('trim', array_values($row));
            $matches = 0;
            foreach (self::REQUIRED_HEADERS as $required) {
                if (in_array($required, $values, true)) {
                    $matches++;
                }
            }
            if ($matches === count(self::REQUIRED_HEADERS)) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string,string> $row @param array<string,string> $columns */
    private function cell(array $row, array $columns, string $name): string
    {
        $column = $columns[$name] ?? null;

        return $column === null ? '' : trim((string)($row[$column] ?? ''));
    }

    private function parseDistance(string $value): ?float
    {
        if ($value === '') {
            return null;
        }
        if (stripos($value, 'Méně než 1') !== false) {
            return 0.0;
        }

        return $this->numOrNull(str_replace(["\xc2\xa0", 'km', ' '], '', $value));
    }

    private function parseMinutes(string $value): int
    {
        if ($value === '' || stripos($value, 'Méně než 1') !== false) {
            return 0;
        }
        if (preg_match('/(\d+)/', $value, $matches)) {
            return max(0, (int)$matches[1]);
        }

        return 0;
    }

    /** @param array<string,string> $row @param array<string,string> $columns */
    private function classification(array $row, array $columns): ?string
    {
        foreach (['Vlastní štítek', 'Obchodní štítek'] as $column) {
            $value = $this->cell($row, $columns, $column);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return null;
    }

    private function numOrNull(string $value): ?float
    {
        $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $value = str_replace(',', '.', $value);

        return $value !== '' && is_numeric($value) ? (float)$value : null;
    }
}

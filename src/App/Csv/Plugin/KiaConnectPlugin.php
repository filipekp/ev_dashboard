<?php

declare(strict_types=1);

namespace App\Csv\Plugin;

use DateTime;

/**
 * CSV reprezentace jízd importovaných z Kia Connect.
 *
 * Originální Kia export je XLSX a zpracovává jej KiaConnectXlsxPlugin. Tento
 * plugin zajišťuje především zpětný export z EV Stats a možnost takový export
 * později znovu naimportovat.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class KiaConnectPlugin extends AbstractCsvVehiclePlugin
{
    public function id(): string
    {
        return 'kia_ev';
    }

    public function label(): string
    {
        return 'Kia Connect';
    }

    public function supports(array $header): bool
    {
        return $this->hasColumns($header, [
            'datum',
            'Čas zahájení',
            'Čas ukončení',
            'Vzdálenost (km)',
            'hodina(minuta)',
            'Spotřeba energie (kWh)',
            'Rekuperace (kWh)',
        ]);
    }

    public function inspect(?string $vin): array
    {
        return [
                'manufacturer'        => 'KIA',
            'suggested_name' => 'Kia EV',
            'battery_kwh' => 0.0,
            'battery_nominal_kwh' => 0.0,
        ];
    }

    public function parse(array $row): ?array
    {
        $date = trim((string)($row['datum'] ?? ''));
        $startTime = trim((string)($row['Čas zahájení'] ?? ''));
        $endTime = trim((string)($row['Čas ukončení'] ?? ''));
        $start = DateTime::createFromFormat('d-m-Y H:i', $date . ' ' . $startTime);
        $end = DateTime::createFromFormat('d-m-Y H:i', $date . ' ' . $endTime);
        if (!$start || !$end) {
            return null;
        }
        if ($end < $start) {
            $end->modify('+1 day');
        }

        $distance = $this->parseDistance($row['Vzdálenost (km)'] ?? null);
        $consumed = $this->numOrNull($row['Spotřeba energie (kWh)'] ?? null);
        $recuperated = $this->numOrNull($row['Rekuperace (kWh)'] ?? null);
        if ($distance === null || $consumed === null) {
            return null;
        }

        $minutes = max(0, (int)($row['hodina(minuta)'] ?? 0));
        if ($minutes === 0) {
            $minutes = max(0, (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60));
        }

        $classification = $this->valueOrNull($row['Vlastní štítek'] ?? null)
            ?: $this->valueOrNull($row['Obchodní štítek'] ?? null);
        if ($classification === '-') {
            $classification = null;
        }

        return [
            'trip_hash' => hash('sha256', implode('|', [
                $this->id(),
                $start->format('c'),
                $end->format('c'),
                (string)$distance,
                (string)$consumed,
                (string)$recuperated,
            ])),
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'classification' => $classification,
            'start_address' => '',
            'end_address' => '',
            'distance_km' => $distance,
            'driving_minutes' => $minutes,
            'travel_minutes' => $minutes,
            'avg_speed_kmh' => ($distance > 0 && $minutes > 0) ? $distance / ($minutes / 60) : null,
            'consumed_kwh' => $consumed,
            'avg_consumption_kwh_100' => $distance > 0 ? $consumed / $distance * 100 : null,
            'public_charging_stops' => 0,
            'public_charge_soc_gained' => 0,
            'short_trip' => $distance <= 5 ? 1 : 0,
            'source_format' => $this->id(),
            'avg_recuperation_kwh_100' => ($distance > 0 && $recuperated !== null) ? $recuperated / $distance * 100 : null,
        ];
    }

    public function exportHeaders(): array
    {
        return [
            'datum',
            'Čas zahájení',
            'Čas ukončení',
            'Vzdálenost (km)',
            'hodina(minuta)',
            'Spotřeba energie (kWh)',
            'Rekuperace (kWh)',
            'Obchodní štítek',
            'Vlastní štítek',
        ];
    }

    public function exportRow(array $trip): array
    {
        $distance = (float)($trip['distance_km'] ?? 0);
        $avgRecuperation = $this->numOrNull($trip['avg_recuperation_kwh_100'] ?? null);
        $recuperated = $avgRecuperation !== null && $distance > 0
            ? $avgRecuperation * $distance / 100
            : null;

        return [
            date('d-m-Y', strtotime((string)$trip['started_at'])),
            date('H:i', strtotime((string)$trip['started_at'])),
            date('H:i', strtotime((string)$trip['ended_at'])),
            $this->out($trip['distance_km'] ?? null),
            $this->out($trip['driving_minutes'] ?? null),
            $this->out($trip['consumed_kwh'] ?? null),
            $this->out($recuperated),
            '-',
            $this->out($trip['classification'] ?? '-'),
        ];
    }

    /** @param mixed $value */
    private function parseDistance($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (stripos($value, 'Méně než 1') !== false) {
            return 0.0;
        }

        return $this->numOrNull(str_replace(["\xc2\xa0", 'km', ' '], '', $value));
    }
}

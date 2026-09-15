<?php

declare(strict_types=1);

namespace App\Csv\Plugin;

use DateTime;

final class SkodaMySkodaPlugin extends AbstractCsvVehiclePlugin
{
    public function id(): string { return 'full'; }
    public function label(): string { return 'MyŠkoda'; }

    public function supports(array $header): bool
    {
        return $this->hasColumns($header, ['Start of trip', 'End of trip', 'Start address', 'End address', 'Distance in km']);
    }

    public function inspect(?string $vin): array
    {
        $name = 'Škoda EV';
        if ($vin !== null && strpos($vin, 'TMBNH') === 0) {
            $name = 'Škoda Elroq';
        }
        return [
            'suggested_name' => $name,
            'battery_kwh' => 77.0,
            'battery_nominal_kwh' => 77.0,
        ];
    }

    public function parse(array $row): ?array
    {
        $start = DateTime::createFromFormat('d.m.Y H:i', trim((string)($row['Start of trip'] ?? '')));
        $end = DateTime::createFromFormat('d.m.Y H:i', trim((string)($row['End of trip'] ?? '')));
        if (!$start || !$end) {
            return null;
        }
        $distance = $this->numOrNull($row['Distance in km'] ?? null);
        if ($distance === null) {
            return null;
        }
        [$slat, $slng] = $this->parseCoord($row['Start coordinates'] ?? null);
        [$elat, $elng] = $this->parseCoord($row['End coordinates'] ?? null);
        $hash = hash('sha256', implode('|', [$this->id(), $start->format('c'), $end->format('c'), (string)($row['Start address'] ?? ''), (string)($row['End address'] ?? ''), (string)$distance]));
        return [
            'trip_hash' => $hash,
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'classification' => $this->valueOrNull($row['Classification'] ?? null),
            'start_address' => (string)($row['Start address'] ?? ''),
            'end_address' => (string)($row['End address'] ?? ''),
            'start_lat' => $slat,
            'start_lng' => $slng,
            'end_lat' => $elat,
            'end_lng' => $elng,
            'distance_km' => $distance,
            'start_odometer_km' => $this->numOrNull($row['Start odometer in km'] ?? null),
            'end_odometer_km' => $this->numOrNull($row['End odometer in km'] ?? null),
            'driving_minutes' => (int)($row['Driving time in minutes'] ?? 0),
            'travel_minutes' => (int)($row['Travel time in minutes'] ?? 0),
            'avg_speed_kmh' => $this->numOrNull($row['Average speed in km/h'] ?? null),
            'consumed_kwh' => $this->numOrNull($row['Electricity consumption in kWh'] ?? null),
            'avg_consumption_kwh_100' => $this->numOrNull($row['Average electricity consumption in kWh/100km'] ?? null),
            'start_soc' => $this->numOrNull($row['Start SoC in %'] ?? null),
            'end_soc' => $this->numOrNull($row['End SoC in %'] ?? null),
            'public_charging_stops' => (int)($row['Public charging stops'] ?? 0),
            'public_charge_soc_gained' => (float)($row['Public charge SoC gained in %'] ?? 0),
            'short_trip' => filter_var($row['Short trip'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'source_format' => $this->id(),
            'total_cost' => null,
            'total_cost_currency' => null,
            'electricity_cost' => null,
            'electricity_cost_currency' => null,
            'electricity_price_per_kwh' => null,
            'avg_aux_consumption_kwh_100' => null,
            'avg_recuperation_kwh_100' => null,
        ];
    }

    public function exportHeaders(): array
    {
        return ['Start of trip','End of trip','Classification','Start address','Start coordinates','End address','End coordinates','Distance in km','Start odometer in km','End odometer in km','Driving time in minutes','Travel time in minutes','Average speed in km/h','Electricity consumption in kWh','Average electricity consumption in kWh/100km','Start SoC in %','End SoC in %','Public charging stops','Public charge SoC gained in %','Short trip'];
    }

    public function exportRow(array $t): array
    {
        return [
            date('d.m.Y H:i', strtotime((string)$t['started_at'])),
            date('d.m.Y H:i', strtotime((string)$t['ended_at'])),
            $this->out($t['classification'] ?? null), $this->out($t['start_address'] ?? null),
            $this->coords($t['start_lat'] ?? null, $t['start_lng'] ?? null), $this->out($t['end_address'] ?? null),
            $this->coords($t['end_lat'] ?? null, $t['end_lng'] ?? null), $this->out($t['distance_km'] ?? null),
            $this->out($t['start_odometer_km'] ?? null), $this->out($t['end_odometer_km'] ?? null),
            $this->out($t['driving_minutes'] ?? null), $this->out($t['travel_minutes'] ?? null), $this->out($t['avg_speed_kmh'] ?? null),
            $this->out($t['consumed_kwh'] ?? null), $this->out($t['avg_consumption_kwh_100'] ?? null),
            $this->out($t['start_soc'] ?? null), $this->out($t['end_soc'] ?? null), $this->out($t['public_charging_stops'] ?? 0),
            $this->out($t['public_charge_soc_gained'] ?? 0), ((int)($t['short_trip'] ?? 0) === 1 ? 'true' : 'false'),
        ];
    }
}

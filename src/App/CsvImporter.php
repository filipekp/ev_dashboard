<?php

declare(strict_types=1);

namespace App;

use DateTime;
use PDO;
use RuntimeException;
use Throwable;

final class CsvImporter
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function vinFromFilename(?string $originalName): ?string
    {
        if (!$originalName || !preg_match('/([A-HJ-NPR-Z0-9]{17})/i', $originalName, $m)) {
            return null;
        }
        return strtoupper($m[1]);
    }

    /** @return array<string,mixed> */
    public function inspect(string $path, ?string $originalName = null): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('CSV nelze otevřít.');
        }
        try {
            $header = fgetcsv($fh);
            if (!$header) {
                throw new RuntimeException('CSV nemá hlavičku.');
            }
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
            }
            $format = $this->detectFormat(array_flip($header));
        } finally {
            fclose($fh);
        }

        $vin = $this->vinFromFilename($originalName);
        $suggestedName = 'Škoda EV';
        $batteryKwh = 77.0;
        $nominalKwh = 77.0;
        if ($format === 'citigo_iv') {
            $suggestedName = 'Škoda Citigo iV';
            $batteryKwh = 32.3;
            $nominalKwh = 32.3;
        } elseif ($vin !== null && strpos($vin, 'TMBNH') === 0) {
            $suggestedName = 'Škoda Elroq';
        }

        return [
            'vin' => $vin,
            'format' => $format,
            'suggested_name' => $suggestedName,
            'battery_kwh' => $batteryKwh,
            'battery_nominal_kwh' => $nominalKwh,
        ];
    }

    /** @return array<string,mixed> */
    public function import(int $vehicleId, string $path, ?string $originalName = null): array
    {
        if ($originalName) {
            $this->validateVin($vehicleId, $originalName);
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('CSV nelze otevřít.');
        }
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            throw new RuntimeException('CSV nemá hlavičku.');
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        $map = array_flip($header);
        $format = $this->detectFormat($map);

        $sql = 'INSERT IGNORE INTO trips (
            vehicle_id, trip_hash, started_at, ended_at, classification, start_address, end_address,
            start_lat,start_lng,end_lat,end_lng,distance_km,start_odometer_km,end_odometer_km,
            driving_minutes,travel_minutes,avg_speed_kmh,consumed_kwh,avg_consumption_kwh_100,
            start_soc,end_soc,public_charging_stops,public_charge_soc_gained,short_trip,
            source_format,total_cost,total_cost_currency,electricity_cost,electricity_cost_currency,
            electricity_price_per_kwh,avg_aux_consumption_kwh_100,avg_recuperation_kwh_100
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $ins = $this->pdo->prepare($sql);
        $inserted = 0;
        $skipped = 0;

        $this->pdo->beginTransaction();
        try {
            while (($row = fgetcsv($fh)) !== false) {
                if (!$row || count($row) < count($header)) {
                    $skipped++;
                    continue;
                }
                $v = static function (string $column) use ($row, $map) {
                    return isset($map[$column]) ? ($row[$map[$column]] ?? null) : null;
                };
                $record = $format === 'full' ? $this->parseFullRow($v) : $this->parseCitigoRow($v);
                if ($record === null) {
                    $skipped++;
                    continue;
                }
                $ins->execute(array_merge([$vehicleId], $record));
                $inserted += $ins->rowCount();
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            fclose($fh);
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'format' => $format];
    }

    /** @param array<string,int> $map */
    public function detectFormat(array $map): string
    {
        $full = ['Start of trip', 'End of trip', 'Start address', 'End address', 'Distance in km'];
        $citigo = ['End of trip', 'Start mileage in km', 'End mileage in km', 'Mileage in km', 'Travel time in minutes', 'Average electric consumption in kWh/100km'];
        if ($this->hasColumns($map, $full)) {
            return 'full';
        }
        if ($this->hasColumns($map, $citigo)) {
            return 'citigo_iv';
        }
        throw new RuntimeException('Nepodporovaný formát CSV. Očekávám export jízd z MyŠkoda (Elroq/novější vozy nebo Citigo iV).');
    }

    /** @param array<string,int> $map @param string[] $columns */
    private function hasColumns(array $map, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!isset($map[$column])) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,mixed>|null */
    private function parseFullRow(callable $v): ?array
    {
        $start = DateTime::createFromFormat('d.m.Y H:i', trim((string)$v('Start of trip')));
        $end = DateTime::createFromFormat('d.m.Y H:i', trim((string)$v('End of trip')));
        if (!$start || !$end) {
            return null;
        }
        [$slat, $slng] = View::parseCoord($v('Start coordinates'));
        [$elat, $elng] = View::parseCoord($v('End coordinates'));
        $distance = $this->numOrNull($v('Distance in km'));
        if ($distance === null) {
            return null;
        }
        $hash = hash('sha256', implode('|', ['full', $start->format('c'), $end->format('c'), (string)$v('Start address'), (string)$v('End address'), (string)$distance]));
        $short = filter_var($v('Short trip'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        return [
            $hash, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $this->valueOrNull($v('Classification')),
            (string)$v('Start address'), (string)$v('End address'), $slat, $slng, $elat, $elng, $distance,
            $this->numOrNull($v('Start odometer in km')), $this->numOrNull($v('End odometer in km')),
            (int)($v('Driving time in minutes') ?: 0), (int)($v('Travel time in minutes') ?: 0),
            $this->numOrNull($v('Average speed in km/h')), $this->numOrNull($v('Electricity consumption in kWh')),
            $this->numOrNull($v('Average electricity consumption in kWh/100km')), $this->numOrNull($v('Start SoC in %')),
            $this->numOrNull($v('End SoC in %')), (int)($v('Public charging stops') ?: 0), (float)($v('Public charge SoC gained in %') ?: 0),
            $short, 'full', null, null, null, null, null, null, null,
        ];
    }

    /** @return array<int,mixed>|null */
    private function parseCitigoRow(callable $v): ?array
    {
        $end = DateTime::createFromFormat('d.m.Y H:i', trim((string)$v('End of trip')));
        if (!$end) {
            return null;
        }
        $travelMinutes = max(0, (int)($v('Travel time in minutes') ?: 0));
        $start = clone $end;
        if ($travelMinutes > 0) {
            $start->modify('-' . $travelMinutes . ' minutes');
        }
        $distance = $this->numOrNull($v('Mileage in km'));
        if ($distance === null) {
            return null;
        }
        $avgConsumption = $this->numOrNull($v('Average electric consumption in kWh/100km'));
        $consumed = ($avgConsumption !== null && $distance > 0) ? max(0.0, $distance * $avgConsumption / 100) : 0.0;
        $startOdo = $this->numOrNull($v('Start mileage in km'));
        $endOdo = $this->numOrNull($v('End mileage in km'));
        $hash = hash('sha256', implode('|', ['citigo_iv', $end->format('c'), (string)$startOdo, (string)$endOdo, (string)$distance, (string)$travelMinutes]));
        return [
            $hash, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), null, '', '', null, null, null, null, $distance,
            $startOdo, $endOdo, $travelMinutes, $travelMinutes, $this->numOrNull($v('Average speed in km/h')), $consumed, $avgConsumption,
            null, null, 0, 0, ($distance <= 5 ? 1 : 0), 'citigo_iv', $this->numOrNull($v('Total cost')), $this->valueOrNull($v('Total cost currency')),
            $this->numOrNull($v('Electricity cost')), $this->valueOrNull($v('Electricity cost currency')), $this->numOrNull($v('Electricity price per kWh')),
            $this->numOrNull($v('Average auxiliary consumption in kWh/100km')), $this->numOrNull($v('Average recuperation in kWh/100km')),
        ];
    }

    /** @param mixed $value */
    private function numOrNull($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace(',', '.', trim((string)$value));
        return $value !== '' && is_numeric($value) ? (float)$value : null;
    }

    /** @param mixed $value */
    private function valueOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    public function validateVin(int $vehicleId, string $originalName): void
    {
        $csvVin = $this->vinFromFilename($originalName);
        if ($csvVin === null) {
            return;
        }
        $q = $this->pdo->prepare('SELECT vin FROM vehicles WHERE id=?');
        $q->execute([$vehicleId]);
        $vehicleVin = strtoupper((string)$q->fetchColumn());
        if ($vehicleVin !== '' && $csvVin !== $vehicleVin) {
            throw new RuntimeException('CSV podle názvu patří vozidlu VIN ' . $csvVin . ', ale vybrané vozidlo má VIN ' . $vehicleVin . '.');
        }
    }
}

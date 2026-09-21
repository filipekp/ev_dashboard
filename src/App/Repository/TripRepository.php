<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use RuntimeException;

/**
 * Perzistence jízd včetně deduplikace napříč importy a OEM telemetrií.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class TripRepository
{
    /** @var PDO */
    private $pdo;

    /** @var string[] */
    private $columns = [
        'trip_hash',
        'canonical_key',
        'started_at',
        'ended_at',
        'classification',
        'trip_note',
        'start_address',
        'end_address',
        'start_lat',
        'start_lng',
        'end_lat',
        'end_lng',
        'distance_km',
        'start_odometer_km',
        'end_odometer_km',
        'driving_minutes',
        'travel_minutes',
        'avg_speed_kmh',
        'consumed_kwh',
        'avg_consumption_kwh_100',
        'fuel_consumed_l',
        'avg_fuel_consumption_l_100',
        'start_soc',
        'end_soc',
        'public_charging_stops',
        'public_charge_soc_gained',
        'short_trip',
        'source_format',
        'total_cost',
        'total_cost_currency',
        'electricity_cost',
        'electricity_cost_currency',
        'electricity_price_per_kwh',
        'avg_aux_consumption_kwh_100',
        'avg_recuperation_kwh_100',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @param array<string,mixed> $trip */
    public function insertIgnore(int $vehicleId, array $trip): bool
    {
        $trip['canonical_key'] = $trip['canonical_key'] ?? $this->canonicalKey($trip);

        $existing = $this->findByHash($vehicleId, (string)($trip['trip_hash'] ?? ''));
        if ($existing === null) {
            $existing = $this->findDuplicate($vehicleId, $trip);
        }
        if ($existing !== null) {
            $this->mergeDuplicate($vehicleId, $existing, $trip);
            return false;
        }

        $columns = array_merge(['vehicle_id'], $this->columns);
        $sql = 'INSERT IGNORE INTO trips (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->valuesForTrip($vehicleId, $trip));

        if ($statement->rowCount() > 0) {
            return true;
        }

        // Souběžný import mohl mezitím vložit stejný canonical_key.
        $existing = $this->findByCanonicalKey($vehicleId, (string)$trip['canonical_key']);
        if ($existing !== null) {
            $this->mergeDuplicate($vehicleId, $existing, $trip);
        }

        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public function findForExport(int $vehicleId, ?string $from, ?string $to): array
    {
        [$where, $parameters] = $this->periodWhere($vehicleId, $from, $to);
        $query = $this->pdo->prepare('SELECT * FROM trips WHERE ' . $where . ' ORDER BY started_at ASC');
        $query->execute($parameters);

        return $query->fetchAll();
    }

    public function dominantSourceFormat(int $vehicleId, ?string $from, ?string $to): string
    {
        [$where, $parameters] = $this->periodWhere($vehicleId, $from, $to);
        $query = $this->pdo->prepare(
            "SELECT source_format, COUNT(*) c FROM trips WHERE {$where} "
            . "AND source_format<>'manual' GROUP BY source_format ORDER BY c DESC LIMIT 1"
        );
        $query->execute($parameters);

        return (string)($query->fetchColumn() ?: '');
    }

    /** @param array<string,mixed> $trip */
    public function insertManual(int $vehicleId, array $trip): int
    {
        $trip['canonical_key'] = $trip['canonical_key'] ?? $this->canonicalKey($trip);
        $columns = array_merge(['vehicle_id'], $this->columns);
        $sql = 'INSERT INTO trips (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->valuesForTrip($vehicleId, $trip));

        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $trip */
    public function updateManualFields(int $vehicleId, int $tripId, array $trip): void
    {
        $editableColumns = array_values(array_filter(
            $this->columns,
            static function (string $column): bool {
                return !in_array($column, ['trip_hash', 'canonical_key', 'source_format'], true);
            }
        ));

        $setClauses = [];
        $values = [];
        foreach ($editableColumns as $column) {
            $setClauses[] = $column . '=?';
            $values[] = $trip[$column] ?? null;
        }
        $values[] = $tripId;
        $values[] = $vehicleId;

        $statement = $this->pdo->prepare(
            'UPDATE trips SET ' . implode(',', $setClauses) . ' WHERE id=? AND vehicle_id=?'
        );
        $statement->execute($values);

        if ($statement->rowCount() === 0 && !$this->findByIdForVehicle($vehicleId, $tripId)) {
            throw new RuntimeException('Jízda nebyla nalezena.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findByIdForVehicle(int $vehicleId, int $tripId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM trips WHERE id=? AND vehicle_id=? LIMIT 1');
        $statement->execute([$tripId, $vehicleId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function countForVehicle(int $vehicleId): int
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM trips WHERE vehicle_id=?');
        $query->execute([$vehicleId]);

        return (int)$query->fetchColumn();
    }

    /** @param array<string,mixed> $trip */
    public function canonicalKey(array $trip): string
    {
        $startOdo = $this->number($trip['start_odometer_km'] ?? null);
        $endOdo = $this->number($trip['end_odometer_km'] ?? null);
        $distance = max(0.0, (float)($trip['distance_km'] ?? 0));

        $start = strtotime((string)($trip['started_at'] ?? '')) ?: 0;
        $end = strtotime((string)($trip['ended_at'] ?? '')) ?: $start;

        if ($startOdo !== null && $endOdo !== null) {
            return hash('sha256', implode('|', [
                'odo-time',
                (string)round($startOdo * 10),
                (string)round($endOdo * 10),
                (string)round($distance * 10),
                (string)floor($start / 300),
                (string)floor($end / 300),
            ]));
        }

        return hash('sha256', implode('|', [
            'time',
            (string)floor($start / 300),
            (string)floor($end / 300),
            (string)round($distance * 10),
        ]));
    }

    /** @param array<string,mixed> $trip @return array<string,mixed>|null */
    private function findDuplicate(int $vehicleId, array $trip): ?array
    {
        $started = strtotime((string)($trip['started_at'] ?? ''));
        $ended = strtotime((string)($trip['ended_at'] ?? ''));
        if ($started === false || $ended === false) {
            return null;
        }

        $query = $this->pdo->prepare(
            'SELECT * FROM trips WHERE vehicle_id=? AND started_at>=? AND started_at<=? AND ended_at>=? AND ended_at<=? '
            . 'ORDER BY ABS(TIMESTAMPDIFF(SECOND, started_at, ?)) + ABS(TIMESTAMPDIFF(SECOND, ended_at, ?)) LIMIT 20'
        );
        $query->execute([
            $vehicleId,
            date('Y-m-d H:i:s', $started - 3600),
            date('Y-m-d H:i:s', $started + 3600),
            date('Y-m-d H:i:s', $ended - 3600),
            date('Y-m-d H:i:s', $ended + 3600),
            date('Y-m-d H:i:s', $started),
            date('Y-m-d H:i:s', $ended),
        ]);

        foreach ($query->fetchAll() as $candidate) {
            if ($this->sameTrip($candidate, $trip)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming */
    private function sameTrip(array $existing, array $incoming): bool
    {
        $distanceA = max(0.0, (float)($existing['distance_km'] ?? 0));
        $distanceB = max(0.0, (float)($incoming['distance_km'] ?? 0));
        $distanceTolerance = max(0.75, max($distanceA, $distanceB) * 0.08);
        if (abs($distanceA - $distanceB) > $distanceTolerance) {
            return false;
        }

        $startA = strtotime((string)$existing['started_at']);
        $endA = strtotime((string)$existing['ended_at']);
        $startB = strtotime((string)$incoming['started_at']);
        $endB = strtotime((string)$incoming['ended_at']);
        if ($startA === false || $endA === false || $startB === false || $endB === false) {
            return false;
        }

        $overlap = max(0, min($endA, $endB) - max($startA, $startB));
        $durationA = max(60, $endA - $startA);
        $durationB = max(60, $endB - $startB);
        $overlapRatio = $overlap / min($durationA, $durationB);

        $startOdoA = $this->number($existing['start_odometer_km'] ?? null);
        $endOdoA = $this->number($existing['end_odometer_km'] ?? null);
        $startOdoB = $this->number($incoming['start_odometer_km'] ?? null);
        $endOdoB = $this->number($incoming['end_odometer_km'] ?? null);
        if ($startOdoA !== null && $endOdoA !== null && $startOdoB !== null && $endOdoB !== null) {
            $odometerMatches = abs($startOdoA - $startOdoB) <= 0.75 && abs($endOdoA - $endOdoB) <= 0.75;
            $timeCompatible = $overlapRatio >= 0.20
                || (abs($startA - $startB) <= 1800 && abs($endA - $endB) <= 1800);
            return $odometerMatches && $timeCompatible;
        }

        return $overlapRatio >= 0.55
            || (abs($startA - $startB) <= 300 && abs($endA - $endB) <= 300);
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming */
    private function mergeDuplicate(int $vehicleId, array $existing, array $incoming): void
    {
        $existingSource = (string)($existing['source_format'] ?? '');
        $incomingSource = (string)($incoming['source_format'] ?? '');
        $incomingIsRicher = $this->isTelemetrySource($existingSource) && !$this->isTelemetrySource($incomingSource);

        $merged = $existing;
        foreach ($this->columns as $column) {
            if ($column === 'trip_hash' || $column === 'canonical_key') {
                continue;
            }
            $incomingValue = $incoming[$column] ?? null;
            $existingValue = $existing[$column] ?? null;
            if ($incomingIsRicher) {
                if ($this->hasValue($incomingValue)) {
                    $merged[$column] = $incomingValue;
                }
            } elseif (!$this->hasValue($existingValue) && $this->hasValue($incomingValue)) {
                $merged[$column] = $incomingValue;
            }
        }

        if ($incomingIsRicher && $incomingSource !== '') {
            $merged['source_format'] = $incomingSource;
        }
        if (!$this->hasValue($merged['canonical_key'] ?? null)) {
            $merged['canonical_key'] = $this->canonicalKey($incoming);
        }

        $set = [];
        $values = [];
        foreach ($this->columns as $column) {
            if ($column === 'trip_hash') {
                continue;
            }
            $set[] = $column . '=?';
            $values[] = $merged[$column] ?? null;
        }
        $values[] = (int)$existing['id'];
        $values[] = $vehicleId;

        try {
            $query = $this->pdo->prepare('UPDATE trips SET ' . implode(',', $set) . ' WHERE id=? AND vehicle_id=?');
            $query->execute($values);
        } catch (\PDOException $e) {
            // Canonical key může při souběžném importu již patřit správnému řádku.
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function findByHash(int $vehicleId, string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }
        $query = $this->pdo->prepare('SELECT * FROM trips WHERE vehicle_id=? AND trip_hash=? LIMIT 1');
        $query->execute([$vehicleId, $hash]);
        $row = $query->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findByCanonicalKey(int $vehicleId, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $query = $this->pdo->prepare('SELECT * FROM trips WHERE vehicle_id=? AND canonical_key=? LIMIT 1');
        $query->execute([$vehicleId, $key]);
        $row = $query->fetch();
        return $row ?: null;
    }

    private function isTelemetrySource(string $source): bool
    {
        return strpos($source, 'telemetry_') === 0;
    }

    /** @param mixed $value */
    private function hasValue($value): bool
    {
        return $value !== null && $value !== '';
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * @param array<string,mixed> $trip
     * @return array<int,mixed>
     */
    private function valuesForTrip(int $vehicleId, array $trip): array
    {
        $values = [$vehicleId];
        foreach ($this->columns as $column) {
            $values[] = $trip[$column] ?? null;
        }

        return $values;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function periodWhere(int $vehicleId, ?string $from, ?string $to): array
    {
        $where = 'vehicle_id=?';
        $parameters = [$vehicleId];

        if ($from !== null && $to !== null) {
            $where .= ' AND started_at>=? AND started_at<?';
            $parameters[] = $from;
            $parameters[] = $to;
        }

        return [$where, $parameters];
    }
}

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
        'trip_state',
        'telemetry_connection_id',
        'telemetry_start_snapshot_id',
        'telemetry_last_snapshot_id',
        'telemetry_last_movement_at',
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
        if (!array_key_exists('canonical_key', $trip)) {
            $trip['canonical_key'] = $this->canonicalKey($trip);
        }

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
        if (!array_key_exists('canonical_key', $trip)) {
            $trip['canonical_key'] = $this->canonicalKey($trip);
        }
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
                return !in_array($column, [
                    'trip_hash',
                    'canonical_key',
                    'trip_state',
                    'telemetry_connection_id',
                    'telemetry_start_snapshot_id',
                    'telemetry_last_snapshot_id',
                    'telemetry_last_movement_at',
                    'source_format',
                ], true);
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

    /** @return array<string,mixed>|null */
    public function findActiveTelemetryTrip(int $vehicleId, int $connectionId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM trips
             WHERE vehicle_id=? AND telemetry_connection_id=? AND trip_state='active'
             ORDER BY started_at DESC,id DESC LIMIT 1"
        );
        $statement->execute([$vehicleId, $connectionId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findLatestTelemetryTrip(int $vehicleId, int $connectionId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM trips
             WHERE vehicle_id=? AND telemetry_connection_id=?
             ORDER BY COALESCE(telemetry_last_snapshot_id,0) DESC,id DESC LIMIT 1"
        );
        $statement->execute([$vehicleId, $connectionId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function deleteTelemetryTripsForConnection(int $vehicleId, int $connectionId): int
    {
        // Čistě telemetry řádky lze bezpečně přestavět. Pokud byla telemetry
        // dříve sloučena do CSV/importované jízdy, samotnou jízdu zachováme a
        // pouze odpojíme projekční metadata; rebuild je následně doplní znovu.
        $clear = $this->pdo->prepare(
            "UPDATE trips
             SET telemetry_connection_id=NULL,
                 telemetry_start_snapshot_id=NULL,
                 telemetry_last_snapshot_id=NULL,
                 telemetry_last_movement_at=NULL
             WHERE vehicle_id=? AND telemetry_connection_id=?
               AND source_format NOT LIKE 'telemetry_%'"
        );
        $clear->execute([$vehicleId, $connectionId]);

        $statement = $this->pdo->prepare(
            "DELETE FROM trips
             WHERE vehicle_id=? AND telemetry_connection_id=?
               AND source_format LIKE 'telemetry_%'"
        );
        $statement->execute([$vehicleId, $connectionId]);

        return $statement->rowCount();
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{id:int,created:bool,completed:bool}
     */
    public function createActiveTelemetryTrip(int $vehicleId, array $trip): array
    {
        $trip['canonical_key'] = null;
        $trip['trip_state'] = 'active';

        $existing = $this->findByHash($vehicleId, (string)($trip['trip_hash'] ?? ''));
        if ($existing !== null) {
            if ((string)($existing['trip_state'] ?? 'completed') === 'completed') {
                return ['id' => (int)$existing['id'], 'created' => false, 'completed' => true];
            }
            $this->updateActiveTelemetryTrip($vehicleId, (int)$existing['id'], $trip);
            return ['id' => (int)$existing['id'], 'created' => false, 'completed' => false];
        }

        $columns = array_merge(['vehicle_id'], $this->columns);
        $sql = 'INSERT INTO trips (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->valuesForTrip($vehicleId, $trip));

        return ['id' => (int)$this->pdo->lastInsertId(), 'created' => true, 'completed' => false];
    }

    /** @param array<string,mixed> $trip */
    public function updateActiveTelemetryTrip(int $vehicleId, int $tripId, array $trip): void
    {
        $this->updateTelemetryFields($vehicleId, $tripId, $trip);
        $statement = $this->pdo->prepare(
            "UPDATE trips SET trip_state='active',canonical_key=NULL WHERE id=? AND vehicle_id=?"
        );
        $statement->execute([$tripId, $vehicleId]);
    }

    /**
     * Dokončí živou telemetry jízdu a před nastavením canonical_key ji ještě
     * sloučí s případným přesnějším CSV/importovaným záznamem.
     *
     * @param array<string,mixed> $trip
     * @return array{id:int,merged:bool}
     */
    public function finalizeTelemetryTrip(int $vehicleId, int $tripId, array $trip): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->updateTelemetryFields($vehicleId, $tripId, $trip);
            $current = $this->findByIdForVehicle($vehicleId, $tripId);
            if ($current === null) {
                throw new RuntimeException('Živá telemetry jízda nebyla nalezena.');
            }

            $candidate = array_merge($current, $trip);
            $candidate['trip_state'] = 'completed';
            $candidate['canonical_key'] = $this->canonicalKey($candidate);

            $duplicate = $this->findDuplicate($vehicleId, $candidate, $tripId);
            if ($duplicate === null) {
                $duplicate = $this->findByCanonicalKey(
                    $vehicleId,
                    (string)$candidate['canonical_key'],
                    $tripId
                );
            }

            if ($duplicate !== null) {
                $duplicateSource = (string)($duplicate['source_format'] ?? '');
                if (!$this->isTelemetrySource($duplicateSource)) {
                    $this->mergeDuplicate($vehicleId, $duplicate, $candidate);
                    $this->deleteById($vehicleId, $tripId);
                    if ($ownsTransaction) {
                        $this->pdo->commit();
                    }
                    return ['id' => (int)$duplicate['id'], 'merged' => true];
                }

                $this->mergeDuplicate($vehicleId, $current, $duplicate);
                $this->deleteById($vehicleId, (int)$duplicate['id']);
            }

            $statement = $this->pdo->prepare(
                "UPDATE trips
                 SET trip_state='completed',canonical_key=?,trip_note=?
                 WHERE id=? AND vehicle_id=?"
            );
            $statement->execute([
                (string)$candidate['canonical_key'],
                $trip['trip_note'] ?? $current['trip_note'] ?? null,
                $tripId,
                $vehicleId,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return ['id' => $tripId, 'merged' => $duplicate !== null];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $trip */
    private function updateTelemetryFields(int $vehicleId, int $tripId, array $trip): void
    {
        $columns = [
            'started_at',
            'ended_at',
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
            'telemetry_connection_id',
            'telemetry_start_snapshot_id',
            'telemetry_last_snapshot_id',
            'telemetry_last_movement_at',
        ];

        $set = [];
        $values = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $trip)) {
                continue;
            }
            $set[] = $column . '=?';
            $values[] = $trip[$column];
        }
        if ($set === []) {
            return;
        }
        $values[] = $tripId;
        $values[] = $vehicleId;

        $statement = $this->pdo->prepare(
            'UPDATE trips SET ' . implode(',', $set) . ' WHERE id=? AND vehicle_id=?'
        );
        $statement->execute($values);
    }

    private function deleteById(int $vehicleId, int $tripId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM trips WHERE id=? AND vehicle_id=?');
        $statement->execute([$tripId, $vehicleId]);
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
    private function findDuplicate(int $vehicleId, array $trip, int $excludeId = 0): ?array
    {
        $started = strtotime((string)($trip['started_at'] ?? ''));
        $ended = strtotime((string)($trip['ended_at'] ?? ''));
        if ($started === false || $ended === false) {
            return null;
        }

        $query = $this->pdo->prepare(
            'SELECT * FROM trips WHERE vehicle_id=? AND id<>? AND started_at>=? AND started_at<=? AND ended_at>=? AND ended_at<=? '
            . 'ORDER BY ABS(TIMESTAMPDIFF(SECOND, started_at, ?)) + ABS(TIMESTAMPDIFF(SECOND, ended_at, ?)) LIMIT 20'
        );
        $query->execute([
            $vehicleId,
            $excludeId,
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
    private function findByCanonicalKey(int $vehicleId, string $key, int $excludeId = 0): ?array
    {
        if ($key === '') {
            return null;
        }
        $query = $this->pdo->prepare('SELECT * FROM trips WHERE vehicle_id=? AND id<>? AND canonical_key=? LIMIT 1');
        $query->execute([$vehicleId, $excludeId, $key]);
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
            if ($column === 'trip_state') {
                $values[] = $trip[$column] ?? 'completed';
                continue;
            }
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

<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use RuntimeException;

/**
 * Perzistence jízd.
 *
 * Dynamické názvy sloupců vznikají výhradně z interního allowlistu níže;
 * hodnoty uživatele se do SQL vždy předávají přes prepared statements.
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
        $columns = array_merge(['vehicle_id'], $this->columns);
        $sql = 'INSERT IGNORE INTO trips (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->valuesForTrip($vehicleId, $trip));

        return $statement->rowCount() > 0;
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
                return !in_array($column, ['trip_hash', 'source_format'], true);
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

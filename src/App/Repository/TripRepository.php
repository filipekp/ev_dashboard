<?php

declare(strict_types=1);
namespace App\Repository;

use PDO;

/**
 * Třída TripRepository.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class TripRepository
{
    /** @var PDO */ private $pdo;
    /** @var string[] */
    private $columns = ['trip_hash','started_at','ended_at','classification','start_address','end_address','start_lat','start_lng','end_lat','end_lng','distance_km','start_odometer_km','end_odometer_km','driving_minutes','travel_minutes','avg_speed_kmh','consumed_kwh','avg_consumption_kwh_100','start_soc','end_soc','public_charging_stops','public_charge_soc_gained','short_trip','source_format','total_cost','total_cost_currency','electricity_cost','electricity_cost_currency','electricity_price_per_kwh','avg_aux_consumption_kwh_100','avg_recuperation_kwh_100'];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @param array<string,mixed> $trip */
    public function insertIgnore(int $vehicleId, array $trip): bool
    {
        $cols = array_merge(['vehicle_id'], $this->columns);
        $sql = 'INSERT IGNORE INTO trips (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $stmt = $this->pdo->prepare($sql);
        $values = [$vehicleId];
        foreach ($this->columns as $column) $values[] = $trip[$column] ?? null;
        $stmt->execute($values);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function findForExport(int $vehicleId, ?string $from, ?string $to): array
    {
        $where = 'vehicle_id=?'; $params = [$vehicleId];
        if ($from !== null && $to !== null) { $where .= ' AND started_at>=? AND started_at<?'; $params[]=$from; $params[]=$to; }
        $q = $this->pdo->prepare('SELECT * FROM trips WHERE ' . $where . ' ORDER BY started_at ASC');
        $q->execute($params); return $q->fetchAll();
    }

    public function dominantSourceFormat(int $vehicleId, ?string $from, ?string $to): string
    {
        $where = 'vehicle_id=?'; $params = [$vehicleId];
        if ($from !== null && $to !== null) { $where .= ' AND started_at>=? AND started_at<?'; $params[]=$from; $params[]=$to; }
        $q = $this->pdo->prepare('SELECT source_format,COUNT(*) c FROM trips WHERE ' . $where . ' GROUP BY source_format ORDER BY c DESC LIMIT 1');
        $q->execute($params); return (string)($q->fetchColumn() ?: '');
    }

    public function countForVehicle(int $vehicleId): int
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM trips WHERE vehicle_id=?'); $q->execute([$vehicleId]); return (int)$q->fetchColumn();
    }
}

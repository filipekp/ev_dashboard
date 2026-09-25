<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/** Sestavuje chronologii událostí vozidla pouze v oprávněném časovém období. */
final class VehicleTimelineService
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @param array<string,mixed> $scope @return array<int,array<string,mixed>> */
    public function build(int $vehicleId, int $limit = 20, array $scope = [], int $offset = 0): array
    {
        $events = [];
        $from = !empty($scope['from']) ? (string)$scope['from'] : null;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $fetchLimit = $limit + $offset;
        $sources = $this->sources();

        foreach ($sources as $source) {
            $sql = $source[2];
            $params = [$vehicleId];
            if ($from !== null) {
                $sql .= ' AND ' . $source[1] . '>=?';
                $params[] = substr($from, 0, $source[1] === 'serviced_at' ? 10 : 19);
            }
            $sql .= ' ORDER BY ' . $source[1] . ' DESC LIMIT ' . $fetchLimit;
            $q = $this->pdo->prepare($sql);
            $q->execute($params);
            foreach ($q->fetchAll() as $row) {
                $row['type'] = $source[0];
                $events[] = $row;
            }
        }
        usort($events, static function (array $a, array $b): int {
            $timeCompare = strcmp((string)$b['event_at'], (string)$a['event_at']);
            if ($timeCompare !== 0) {
                return $timeCompare;
            }
            return (int)$b['id'] <=> (int)$a['id'];
        });

        return array_slice($events, $offset, $limit);
    }

    /** @param array<string,mixed> $scope */
    public function count(int $vehicleId, array $scope = []): int
    {
        $from = !empty($scope['from']) ? (string)$scope['from'] : null;
        $total = 0;
        foreach ($this->sources() as $source) {
            $table = $source[3];
            $dateColumn = $source[1];
            $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE vehicle_id=?';
            $params = [$vehicleId];
            if ($from !== null) {
                $sql .= ' AND ' . $dateColumn . '>=?';
                $params[] = substr($from, 0, $dateColumn === 'serviced_at' ? 10 : 19);
            }
            $q = $this->pdo->prepare($sql);
            $q->execute($params);
            $total += (int)$q->fetchColumn();
        }

        return $total;
    }

    /** @return array<int,array<int,string>> */
    private function sources(): array
    {
        return [
            ['trip', 'started_at', 'SELECT id, started_at event_at, distance_km value, start_address label, end_address detail, trip_state FROM trips WHERE vehicle_id=?', 'trips'],
            ['energy', 'occurred_at', 'SELECT id, occurred_at event_at, total_price value, station label, CONCAT(ROUND(quantity,2), " ", unit, " · ", energy_type) detail FROM vehicle_energy_entries WHERE vehicle_id=?', 'vehicle_energy_entries'],
            ['service', 'serviced_at', 'SELECT id, serviced_at event_at, cost value, title label, CONCAT(category, IF(provider IS NULL,"",CONCAT(" · ",provider))) detail FROM vehicle_service_records WHERE vehicle_id=?', 'vehicle_service_records'],
            ['expense', 'occurred_at', 'SELECT id, occurred_at event_at, amount value, title label, category detail FROM vehicle_expenses WHERE vehicle_id=?', 'vehicle_expenses'],
            ['connected', 'occurred_at', 'SELECT id, occurred_at event_at, NULL value, title label, event_type detail FROM vehicle_events WHERE vehicle_id=?', 'vehicle_events'],
        ];
    }
}

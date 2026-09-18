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
    public function build(int $vehicleId, int $limit = 80, array $scope = []): array
    {
        $events = [];
        $from = !empty($scope['from']) ? (string)$scope['from'] : null;
        $sources = [
            ['trip', 'started_at', 'SELECT id, started_at event_at, distance_km value, start_address label, end_address detail FROM trips WHERE vehicle_id=?'],
            ['energy', 'occurred_at', 'SELECT id, occurred_at event_at, total_price value, station label, CONCAT(ROUND(quantity,2), " ", unit, " · ", energy_type) detail FROM vehicle_energy_entries WHERE vehicle_id=?'],
            ['service', 'serviced_at', 'SELECT id, serviced_at event_at, cost value, title label, CONCAT(category, IF(provider IS NULL,"",CONCAT(" · ",provider))) detail FROM vehicle_service_records WHERE vehicle_id=?'],
            ['expense', 'occurred_at', 'SELECT id, occurred_at event_at, amount value, title label, category detail FROM vehicle_expenses WHERE vehicle_id=?'],
            ['connected', 'occurred_at', 'SELECT id, occurred_at event_at, NULL value, title label, event_type detail FROM vehicle_events WHERE vehicle_id=?'],
        ];
        foreach ($sources as $source) {
            $sql = $source[2];
            $params = [$vehicleId];
            if ($from !== null) {
                $sql .= ' AND ' . $source[1] . '>=?';
                $params[] = substr($from, 0, $source[1] === 'serviced_at' ? 10 : 19);
            }
            $sql .= ' ORDER BY ' . $source[1] . ' DESC LIMIT 80';
            $q = $this->pdo->prepare($sql);
            $q->execute($params);
            foreach ($q->fetchAll() as $row) {
                $row['type'] = $source[0];
                $events[] = $row;
            }
        }
        usort($events, static function (array $a, array $b): int {
            return strcmp((string)$b['event_at'], (string)$a['event_at']);
        });
        return array_slice($events, 0, $limit);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/** Sestavuje jednotnou chronologii všech událostí vozidla. */
final class VehicleTimelineService
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @return array<int,array<string,mixed>> */
    public function build(int $vehicleId, int $limit = 80): array
    {
        $events = [];
        $sources = [
            ['trip', 'SELECT id, started_at event_at, distance_km value, start_address label, end_address detail FROM trips WHERE vehicle_id=? ORDER BY started_at DESC LIMIT 80'],
            ['energy', 'SELECT id, occurred_at event_at, total_price value, station label, CONCAT(ROUND(quantity,2), " ", unit, " · ", energy_type) detail FROM vehicle_energy_entries WHERE vehicle_id=? ORDER BY occurred_at DESC LIMIT 80'],
            ['service', 'SELECT id, serviced_at event_at, cost value, title label, CONCAT(category, IF(provider IS NULL,"",CONCAT(" · ",provider))) detail FROM vehicle_service_records WHERE vehicle_id=? ORDER BY serviced_at DESC LIMIT 80'],
            ['expense', 'SELECT id, occurred_at event_at, amount value, title label, category detail FROM vehicle_expenses WHERE vehicle_id=? ORDER BY occurred_at DESC LIMIT 80'],
        ];
        foreach ($sources as $source) {
            $q = $this->pdo->prepare($source[1]); $q->execute([$vehicleId]);
            foreach ($q->fetchAll() as $row) { $row['type'] = $source[0]; $events[] = $row; }
        }
        usort($events, static function (array $a, array $b): int { return strcmp((string)$b['event_at'], (string)$a['event_at']); });
        return array_slice($events, 0, $limit);
    }
}

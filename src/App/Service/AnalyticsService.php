<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Analytická služba pro Garage dashboard.
 *
 * Skládá agregace napříč vozidly, meziroční statistiky, TCO,
 * vývoj ceny za kilometr a sezónní spotřebu. Veškeré dotazy jsou omezené
 * pouze na ID vozidel, která předá controller po kontrole oprávnění.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class AnalyticsService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<int,array<string,mixed>> $vehicles
     * @param array<string,mixed>            $query
     * @return array<string,mixed>
     */
    public function build(array $vehicles, array $query): array
    {
        $vehicleIds = array_values(array_map(static function (array $vehicle): int {
            return (int)$vehicle['id'];
        }, $vehicles));

        $years = $this->availableYears($vehicleIds);
        $selectedYear = (int)($query['year'] ?? 0);
        if ($selectedYear <= 0 || !in_array($selectedYear, $years, true)) {
            $selectedYear = $years ? (int)end($years) : (int)date('Y');
        }

        $comparison = [];
        foreach ($vehicles as $vehicle) {
            $comparison[] = $this->vehicleSummary($vehicle, $selectedYear);
        }

        usort($comparison, static function (array $a, array $b): int {
            return $b['distance_km'] <=> $a['distance_km'];
        });

        $totals = [
            'distance_km' => 0.0,
            'trip_count' => 0,
            'operating_cost' => 0.0,
            'depreciation' => 0.0,
            'full_tco' => 0.0,
        ];
        foreach ($comparison as $row) {
            $totals['distance_km'] += $row['distance_km'];
            $totals['trip_count'] += $row['trip_count'];
            $totals['operating_cost'] += $row['operating_cost'];
            $totals['depreciation'] += $row['depreciation'];
            $totals['full_tco'] += $row['full_tco'];
        }

        return [
            'years' => $years,
            'selectedYear' => $selectedYear,
            'comparison' => $comparison,
            'totals' => $totals,
            'yearOverYear' => $this->yearOverYear($vehicleIds),
            'monthlyCostPerKm' => $this->monthlyCostPerKm($vehicleIds, $selectedYear),
            'seasonalConsumption' => $this->seasonalConsumption($vehicleIds, $selectedYear),
        ];
    }

    /** @param array<int,int> $vehicleIds @return array<int,int> */
    private function availableYears(array $vehicleIds): array
    {
        if (!$vehicleIds) {
            return [];
        }
        $sql = 'SELECT DISTINCT YEAR(started_at) y FROM trips WHERE vehicle_id IN (' . $this->placeholders($vehicleIds) . ') ORDER BY y';
        $query = $this->pdo->prepare($sql);
        $query->execute($vehicleIds);
        return array_values(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array<string,mixed> $vehicle @return array<string,mixed> */
    private function vehicleSummary(array $vehicle, int $year): array
    {
        $vehicleId = (int)$vehicle['id'];
        $from = sprintf('%04d-01-01 00:00:00', $year);
        $to = sprintf('%04d-01-01 00:00:00', $year + 1);

        $query = $this->pdo->prepare(
            'SELECT COUNT(*) trip_count,
                    COALESCE(SUM(distance_km),0) distance_km,
                    COALESCE(SUM(consumed_kwh),0) consumed_kwh,
                    COALESCE(SUM(electricity_cost),0) csv_energy_cost
             FROM trips
             WHERE vehicle_id=? AND started_at>=? AND started_at<?'
        );
        $query->execute([$vehicleId, $from, $to]);
        $trip = $query->fetch() ?: [];

        $energyCost = $this->sumCost(
            'SELECT COALESCE(SUM(total_price),0) FROM vehicle_energy_entries WHERE vehicle_id=? AND occurred_at>=? AND occurred_at<?',
            [$vehicleId, $from, $to]
        );
        $serviceCost = $this->sumCost(
            'SELECT COALESCE(SUM(cost),0) FROM vehicle_service_records WHERE vehicle_id=? AND serviced_at>=? AND serviced_at<?',
            [$vehicleId, substr($from, 0, 10), substr($to, 0, 10)]
        );
        $otherCost = $this->sumCost(
            'SELECT COALESCE(SUM(amount),0) FROM vehicle_expenses WHERE vehicle_id=? AND occurred_at>=? AND occurred_at<?',
            [$vehicleId, substr($from, 0, 10), substr($to, 0, 10)]
        );

        // Když není ruční evidence energie, použijeme náklady z CSV jako fallback.
        if ($energyCost <= 0) {
            $energyCost = (float)($trip['csv_energy_cost'] ?? 0);
        }

        $distance = (float)($trip['distance_km'] ?? 0);
        $consumed = (float)($trip['consumed_kwh'] ?? 0);
        $operatingCost = $energyCost + $serviceCost + $otherCost;
        $depreciation = $this->depreciationForYear($vehicle, $year);
        $fullTco = $operatingCost + $depreciation;

        return [
            'id' => $vehicleId,
            'name' => (string)$vehicle['name'],
            'vin' => (string)$vehicle['vin'],
            'powertrain_type' => (string)($vehicle['powertrain_type'] ?? 'BEV'),
            'trip_count' => (int)($trip['trip_count'] ?? 0),
            'distance_km' => $distance,
            'consumed_kwh' => $consumed,
            'avg_consumption' => $distance > 0 ? ($consumed / $distance) * 100 : 0.0,
            'energy_cost' => $energyCost,
            'service_cost' => $serviceCost,
            'other_cost' => $otherCost,
            'operating_cost' => $operatingCost,
            'depreciation' => $depreciation,
            'full_tco' => $fullTco,
            'operating_cost_per_km' => $distance > 0 ? $operatingCost / $distance : 0.0,
            'tco_per_km' => $distance > 0 ? $fullTco / $distance : 0.0,
            'tco_complete' => $this->hasTcoInputs($vehicle),
        ];
    }

    /** @param array<int,int> $vehicleIds @return array<int,array<string,mixed>> */
    private function yearOverYear(array $vehicleIds): array
    {
        if (!$vehicleIds) {
            return [];
        }
        $sql = 'SELECT YEAR(started_at) y, COUNT(*) trip_count,
                       COALESCE(SUM(distance_km),0) distance_km,
                       COALESCE(SUM(consumed_kwh),0) consumed_kwh
                FROM trips
                WHERE vehicle_id IN (' . $this->placeholders($vehicleIds) . ')
                GROUP BY YEAR(started_at)
                ORDER BY y';
        $query = $this->pdo->prepare($sql);
        $query->execute($vehicleIds);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            $km = (float)$row['distance_km'];
            $rows[] = [
                'year' => (int)$row['y'],
                'trip_count' => (int)$row['trip_count'],
                'distance_km' => $km,
                'consumed_kwh' => (float)$row['consumed_kwh'],
                'avg_consumption' => $km > 0 ? ((float)$row['consumed_kwh'] / $km) * 100 : 0.0,
            ];
        }
        return $rows;
    }

    /** @param array<int,int> $vehicleIds @return array<int,array<string,mixed>> */
    private function monthlyCostPerKm(array $vehicleIds, int $year): array
    {
        if (!$vehicleIds) {
            return [];
        }
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-01-01', $year + 1);
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $months[$key] = ['month' => $key, 'distance_km' => 0.0, 'cost' => 0.0, 'cost_per_km' => 0.0];
        }

        $sql = 'SELECT DATE_FORMAT(started_at,\'%Y-%m\') m, COALESCE(SUM(distance_km),0) km,
                       COALESCE(SUM(electricity_cost),0) csv_cost
                FROM trips
                WHERE vehicle_id IN (' . $this->placeholders($vehicleIds) . ')
                  AND started_at>=? AND started_at<?
                GROUP BY m';
        $query = $this->pdo->prepare($sql);
        $query->execute(array_merge($vehicleIds, [$from, $to]));
        foreach ($query->fetchAll() as $row) {
            $key = (string)$row['m'];
            if (isset($months[$key])) {
                $months[$key]['distance_km'] = (float)$row['km'];
                $months[$key]['cost'] += (float)$row['csv_cost'];
            }
        }

        $this->addMonthlyCosts($months, 'vehicle_energy_entries', 'occurred_at', 'total_price', $vehicleIds, $from, $to, true);
        $this->addMonthlyCosts($months, 'vehicle_service_records', 'serviced_at', 'cost', $vehicleIds, $from, $to, false);
        $this->addMonthlyCosts($months, 'vehicle_expenses', 'occurred_at', 'amount', $vehicleIds, $from, $to, false);

        foreach ($months as &$row) {
            $row['cost_per_km'] = $row['distance_km'] > 0 ? $row['cost'] / $row['distance_km'] : 0.0;
        }
        unset($row);
        return array_values($months);
    }

    /** @param array<string,array<string,mixed>> $months @param array<int,int> $vehicleIds */
    private function addMonthlyCosts(array &$months, string $table, string $dateColumn, string $amountColumn, array $vehicleIds, string $from, string $to, bool $replaceCsvEnergy): void
    {
        $sql = 'SELECT DATE_FORMAT(' . $dateColumn . ',\'%Y-%m\') m, COALESCE(SUM(' . $amountColumn . '),0) amount
                FROM ' . $table . '
                WHERE vehicle_id IN (' . $this->placeholders($vehicleIds) . ')
                  AND ' . $dateColumn . '>=? AND ' . $dateColumn . '<?
                GROUP BY m';
        $query = $this->pdo->prepare($sql);
        $query->execute(array_merge($vehicleIds, [$from, $to]));
        foreach ($query->fetchAll() as $row) {
            $key = (string)$row['m'];
            if (!isset($months[$key])) {
                continue;
            }
            if ($replaceCsvEnergy && (float)$row['amount'] > 0) {
                // Ruční provozní evidence je autoritativnější než náklad importovaný z CSV.
                $months[$key]['cost'] = (float)$row['amount'];
            } else {
                $months[$key]['cost'] += (float)$row['amount'];
            }
        }
    }

    /** @param array<int,int> $vehicleIds @return array<int,array<string,mixed>> */
    private function seasonalConsumption(array $vehicleIds, int $year): array
    {
        $seasons = [
            1 => ['label' => 'Zima', 'km' => 0.0, 'kwh' => 0.0],
            2 => ['label' => 'Jaro', 'km' => 0.0, 'kwh' => 0.0],
            3 => ['label' => 'Léto', 'km' => 0.0, 'kwh' => 0.0],
            4 => ['label' => 'Podzim', 'km' => 0.0, 'kwh' => 0.0],
        ];
        if (!$vehicleIds) {
            return array_values($seasons);
        }

        $sql = 'SELECT MONTH(started_at) m, COALESCE(SUM(distance_km),0) km, COALESCE(SUM(consumed_kwh),0) kwh
                FROM trips
                WHERE vehicle_id IN (' . $this->placeholders($vehicleIds) . ')
                  AND YEAR(started_at)=?
                GROUP BY MONTH(started_at)';
        $query = $this->pdo->prepare($sql);
        $query->execute(array_merge($vehicleIds, [$year]));
        foreach ($query->fetchAll() as $row) {
            $month = (int)$row['m'];
            $season = in_array($month, [12, 1, 2], true) ? 1 : (in_array($month, [3, 4, 5], true) ? 2 : (in_array($month, [6, 7, 8], true) ? 3 : 4));
            $seasons[$season]['km'] += (float)$row['km'];
            $seasons[$season]['kwh'] += (float)$row['kwh'];
        }
        foreach ($seasons as &$season) {
            $season['avg_consumption'] = $season['km'] > 0 ? ($season['kwh'] / $season['km']) * 100 : 0.0;
        }
        unset($season);
        return array_values($seasons);
    }

    /** @param array<string,mixed> $vehicle */
    private function depreciationForYear(array $vehicle, int $year): float
    {
        if (!$this->hasTcoInputs($vehicle)) {
            return 0.0;
        }
        $purchase = (float)$vehicle['acquisition_price'];
        $current = (float)$vehicle['current_value'];
        $date = (string)$vehicle['acquisition_date'];
        $purchaseYear = (int)substr($date, 0, 4);
        $endYear = (int)date('Y');
        $years = max(1, $endYear - $purchaseYear + 1);
        if ($year < $purchaseYear || $year > $endYear) {
            return 0.0;
        }
        return max(0.0, ($purchase - $current) / $years);
    }

    /** @param array<string,mixed> $vehicle */
    private function hasTcoInputs(array $vehicle): bool
    {
        return !empty($vehicle['acquisition_date'])
            && $vehicle['acquisition_price'] !== null
            && $vehicle['current_value'] !== null
            && (float)$vehicle['acquisition_price'] >= (float)$vehicle['current_value'];
    }

    /** @param array<int,mixed> $params */
    private function sumCost(string $sql, array $params): float
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        return (float)$query->fetchColumn();
    }

    /** @param array<int,mixed> $values */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}

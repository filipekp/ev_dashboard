<?php

    declare(strict_types=1);

    namespace App\Service;

    use App\Repository\VehicleOperationRepository;
    use DateTime;
    use PDO;

    /**
     * Třída DashboardService.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
    final class DashboardService
    {
        /** @var PDO */
        private $pdo;

        /** @var VehicleOperationRepository|null */
        private $vehicleOperations;

        public function __construct(PDO $pdo, ?VehicleOperationRepository $vehicleOperations = null) {
            $this->pdo = $pdo;
            $this->vehicleOperations = $vehicleOperations;
        }

        /** @param array<string,mixed> $vehicle @param array<string,mixed> $query @return array<string,mixed> */
        public function build(array $vehicle, array $query, array $detailScope = []): array {
            $vehicleId = (int)$vehicle['id'];
            $powertrain = strtoupper((string)($vehicle['powertrain_type'] ?? 'BEV'));
            $hasTractionBattery = in_array($powertrain, ['BEV', 'PHEV'], TRUE);
            $hasFuelSystem = in_array($powertrain, ['PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'], TRUE);
            $scopeWhere = '';
            $scopeParams = [];
            if (!empty($detailScope['from'])) {
                $scopeWhere .= ' AND started_at >= ?';
                $scopeParams[] = (string)$detailScope['from'];
            }
            if (!empty($detailScope['to'])) {
                $scopeWhere .= ' AND started_at < ?';
                $scopeParams[] = (string)$detailScope['to'];
            }
            $months = $this->pdo->prepare(
                'SELECT DISTINCT DATE_FORMAT(started_at, "%Y-%m") m FROM trips WHERE vehicle_id=?' . $scopeWhere . ' ORDER BY m'
            );
            $months->execute(array_merge([$vehicleId], $scopeParams));
            $monthOptions = $months->fetchAll(PDO::FETCH_COLUMN);
            $years        = [];
            foreach ($monthOptions as $m) {
                $y = substr((string)$m, 0, 4);
                if (!in_array($y, $years, TRUE)) {
                    $years[] = $y;
                }
            }
            $period       = (string)($query['period'] ?? 'all');
            $selectedYear = (string)($query['year'] ?? '');
            if (preg_match('/^\d{4}-\d{2}$/', $period)) {
                $selectedYear = substr($period, 0, 4);
            }
            if (!in_array($selectedYear, $years, TRUE)) {
                $selectedYear = $years ? (string)end($years) : date('Y');
            }
            $params = array_merge([$vehicleId], $scopeParams);
            $where  = 'vehicle_id = ?' . $scopeWhere;
            if (preg_match('/^\d{4}-\d{2}$/', $period)) {
                $from = $period . '-01 00:00:00';
                $dt   = new DateTime($from);
                $dt->modify('+1 month');
                $where    .= ' AND started_at >= ? AND started_at < ?';
                $params[] = $from;
                $params[] = $dt->format('Y-m-d H:i:s');
            } elseif ($period === 'year') {
                $where    .= ' AND started_at >= ? AND started_at < ?';
                $params[] = $selectedYear . '-01-01 00:00:00';
                $params[] = ((int)$selectedYear + 1) . '-01-01 00:00:00';
            } else {
                $period = 'all';
            }

            $summaryQ = $this->pdo->prepare("SELECT COUNT(*) trip_count,COALESCE(SUM(distance_km),0) total_km,COALESCE(SUM(consumed_kwh),0) total_kwh,COALESCE(SUM(fuel_consumed_l),0) total_fuel,COALESCE(SUM(CASE WHEN avg_recuperation_kwh_100 IS NOT NULL THEN avg_recuperation_kwh_100 * distance_km / 100 ELSE 0 END),0) total_recuperated_kwh,COALESCE(SUM(driving_minutes),0) drive_min,COALESCE(SUM(travel_minutes),0) travel_min,COALESCE(SUM(short_trip),0) short_trips,COALESCE(SUM(public_charging_stops),0) public_stops,COALESCE(SUM(public_charge_soc_gained),0) public_soc,MIN(start_odometer_km) odo_min,MAX(end_odometer_km) odo_max,MIN(end_soc) min_soc,SUM(CASE WHEN start_soc IS NOT NULL OR end_soc IS NOT NULL OR public_charging_stops>0 OR public_charge_soc_gained>0 THEN 1 ELSE 0 END) charge_rows,SUM(CASE WHEN start_address<>'' OR end_address<>'' THEN 1 ELSE 0 END) location_rows,SUM(CASE WHEN electricity_cost IS NOT NULL OR total_cost IS NOT NULL THEN 1 ELSE 0 END) cost_rows,COALESCE(SUM(electricity_cost),0) electricity_cost_total FROM trips WHERE $where");
            $summaryQ->execute($params);
            $summary               = $summaryQ->fetch() ?: [];
            $tripCount             = (int)($summary['trip_count'] ?? 0);
            $totalKm               = (float)($summary['total_km'] ?? 0);
            $totalKwh              = (float)($summary['total_kwh'] ?? 0);
            $totalFuel             = (float)($summary['total_fuel'] ?? 0);
            $totalRecuperatedKwh   = (float)($summary['total_recuperated_kwh'] ?? 0);
            $avgCons               = $totalKm > 0 ? $totalKwh / $totalKm * 100 : 0;
            $avgFuelCons           = $totalKm > 0 ? $totalFuel / $totalKm * 100 : 0;
            $driveMin              = (int)($summary['drive_min'] ?? 0);
            $travelMin             = (int)($summary['travel_min'] ?? 0);
            $avgSpeed              = $driveMin > 0 ? $totalKm / ($driveMin / 60) : 0;
            $range                 = $hasTractionBattery && $avgCons > 0 ? (float)$vehicle['battery_kwh'] / $avgCons * 100 : 0;
            $shortTrips            = (int)($summary['short_trips'] ?? 0);
            $chargeDataAvailable   = $hasTractionBattery && (int)($summary['charge_rows'] ?? 0) > 0;
            $locationDataAvailable = (int)($summary['location_rows'] ?? 0) > 0;
            $costDataAvailable     = (int)($summary['cost_rows'] ?? 0) > 0;
            $costTotal             = (float)($summary['electricity_cost_total'] ?? 0);
            $publicStops           = (int)($summary['public_stops'] ?? 0);
            $publicSoc             = (float)($summary['public_soc'] ?? 0);
            $publicKwh             = $publicSoc / 100 * (float)$vehicle['battery_kwh'];
            $homeKwh               = max(0, $totalKwh - $publicKwh);
            $chargeTotal           = max(.001, $homeKwh + $publicKwh);
            $homePct               = $homeKwh / $chargeTotal * 100;
            $publicPct             = $publicKwh / $chargeTotal * 100;
            $odoMin                = $summary['odo_min'] !== NULL ? (float)$summary['odo_min'] : 0;
            $odoMax                = $summary['odo_max'] !== NULL ? (float)$summary['odo_max'] : 0;
            $minSoc                = $summary['min_soc'] !== NULL ? (float)$summary['min_soc'] : NULL;
            $monthLabels           = [];
            $monthKm               = [];
            $monthCons             = [];
            $q                     = $this->pdo->prepare("SELECT DATE_FORMAT(started_at,'%Y-%m') m,SUM(distance_km) km,SUM(consumed_kwh) kwh FROM trips WHERE $where GROUP BY m ORDER BY m");
            $q->execute($params);
            foreach ($q->fetchAll() as $r) {
                $monthLabels[] = substr($r['m'], 5, 2) . '/' . substr($r['m'], 0, 4);
                $monthKm[]     = round((float)$r['km'], 1);
                $monthCons[]   = round((float)$r['km'] > 0 ? (float)$r['kwh'] / (float)$r['km'] * 100 : 0, 1);
            }
            $hourData = array_fill(0, 24, 0);
            $q        = $this->pdo->prepare("SELECT HOUR(started_at) h,COUNT(*) c FROM trips WHERE $where GROUP BY h");
            $q->execute($params);
            foreach ($q->fetchAll() as $r) {
                $hourData[(int)$r['h']] = (int)$r['c'];
            }
            $bandLabels = [
                'Město (<40 km/h)',
                'Okresky (40–65 km/h)',
                'Rychlé okresky (65–85 km/h)',
                'Dálnice (>85 km/h)'
            ];
            $bandValues = array_fill(0, 4, 0.0);
            $bandKm     = array_fill(0, 4, 0.0);
            $q          = $this->pdo->prepare("SELECT CASE WHEN avg_speed_kmh<40 THEN 0 WHEN avg_speed_kmh<65 THEN 1 WHEN avg_speed_kmh<=85 THEN 2 ELSE 3 END band,SUM(distance_km) km,SUM(consumed_kwh) kwh FROM trips WHERE $where GROUP BY band");
            $q->execute($params);
            foreach ($q->fetchAll() as $r) {
                $idx              = (int)$r['band'];
                $km               = (float)$r['km'];
                $bandValues[$idx] = round($km > 0 ? (float)$r['kwh'] / $km * 100 : 0, 1);
                $bandKm[$idx]     = round($km, 1);
            }
            $routes = [];
            $q      = $this->pdo->prepare("SELECT start_address,end_address,COUNT(*) c,SUM(distance_km) km,SUM(consumed_kwh) kwh FROM trips WHERE $where AND (start_address<>'' OR end_address<>'') GROUP BY start_address,end_address ORDER BY c DESC,km DESC LIMIT 10");
            $q->execute($params);
            foreach ($q->fetchAll() as $r) {
                $key          = \App\View::displayRoute($r['start_address'], $r['end_address']);
                $routes[$key] = [
                    'count' => (int)$r['c'],
                    'km'    => (float)$r['km'],
                    'kwh'   => (float)$r['kwh']
                ];
            }
            $q = $this->pdo->prepare("SELECT COUNT(*) FROM trips WHERE $where AND distance_km>=80");
            $q->execute($params);
            $longTripCount = (int)$q->fetchColumn();
            $longTripPerPage = 20;
            $longTripPage = max(1, (int)($query['long_page'] ?? 1));
            $longTripPages = max(1, (int)ceil($longTripCount / $longTripPerPage));
            $longTripPage = min($longTripPage, $longTripPages);
            $longTripOffset = ($longTripPage - 1) * $longTripPerPage;
            $q = $this->pdo->prepare(
                "SELECT * FROM trips WHERE $where AND distance_km>=80 ORDER BY started_at DESC LIMIT "
                . $longTripPerPage . " OFFSET " . $longTripOffset
            );
            $q->execute($params);
            $longTrips = $q->fetchAll();
            $monthsForYear = array_values(array_filter($monthOptions, static function ($m) use ($selectedYear) {
                return substr((string)$m, 0, 4) === $selectedYear;
            }));
            $homeLabel     = (string)($vehicle['home_label'] ?? '');
            $perPage       = 20;
            $historyPage   = max(1, (int)($query['page'] ?? 1));
            $historyPages  = max(1, (int)ceil($tripCount / $perPage));
            if ($historyPage > $historyPages) {
                $historyPage = $historyPages;
            }
            $historyOffset = ($historyPage - 1) * $perPage;
            $q             = $this->pdo->prepare("SELECT * FROM trips WHERE $where ORDER BY started_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$historyOffset);
            $q->execute($params);
            $historyTrips = $q->fetchAll();
            $nominalKwh = $hasTractionBattery ? (float)($vehicle['battery_nominal_kwh'] ?: $vehicle['battery_kwh']) : 0.0;
            $sohManual  = $hasTractionBattery && $vehicle['soh_manual_pct'] !== NULL ? (float)$vehicle['soh_manual_pct'] : NULL;
            $sohResult  = $hasTractionBattery
                ? $this->estimateStateOfHealth($vehicleId, $nominalKwh)
                : [
                    'samples' => [],
                    'sample_count' => 0,
                    'raw_pct' => NULL,
                    'display_pct' => NULL,
                    'spread_pct' => NULL,
                    'soc_coverage_pct' => 0.0,
                ];

            // Zachováváme původní proměnné kvůli kompatibilitě se šablonami.
            $sohSamples      = $sohResult['samples'];
            $sohSampleCount  = $sohResult['sample_count'];
            $sohRawEstimated = $sohResult['raw_pct'];
            $sohEstimated    = $sohResult['display_pct'];
            $sohSpreadPct    = $sohResult['spread_pct'];
            $sohSocCoverage  = $sohResult['soc_coverage_pct'];

            $soh       = $sohManual ?? $sohEstimated;
            $sohSource = $sohManual !== NULL
                ? 'BMS / diagnostika'
                : ($sohEstimated !== NULL ? 'orientační odhad z energetické bilance jízd' : 'nedostatek dat');
            $sohClass = $soh === NULL ? '' : ($soh >= 90 ? 'green' : ($soh >= 80 ? 'orange' : 'pink'));
            $operationSummary = $this->vehicleOperations !== null
                ? $this->vehicleOperations->costSummary($vehicleId)
                : ['energy_cost' => 0.0, 'service_cost' => 0.0, 'other_cost' => 0.0, 'total_cost' => 0.0, 'distance_km' => 0.0, 'cost_per_km' => 0.0];
            if (!empty($detailScope['from'])) {
                $scopeFrom = (string)$detailScope['from'];
                $energyQ = $this->pdo->prepare('SELECT COALESCE(SUM(total_price),0) FROM vehicle_energy_entries WHERE vehicle_id=? AND occurred_at>=?');
                $energyQ->execute([$vehicleId, $scopeFrom]);
                $serviceQ = $this->pdo->prepare('SELECT COALESCE(SUM(cost),0) FROM vehicle_service_records WHERE vehicle_id=? AND serviced_at>=?');
                $serviceQ->execute([$vehicleId, substr($scopeFrom, 0, 10)]);
                $expenseQ = $this->pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM vehicle_expenses WHERE vehicle_id=? AND occurred_at>=?');
                $expenseQ->execute([$vehicleId, substr($scopeFrom, 0, 10)]);
                $energyCost = (float)$energyQ->fetchColumn();
                $serviceCost = (float)$serviceQ->fetchColumn();
                $otherCost = (float)$expenseQ->fetchColumn();
                $scopedCost = $energyCost + $serviceCost + $otherCost;
                $operationSummary = [
                    'energy_cost' => $energyCost,
                    'service_cost' => $serviceCost,
                    'other_cost' => $otherCost,
                    'total_cost' => $scopedCost,
                    'distance_km' => $totalKm,
                    'cost_per_km' => $totalKm > 0 ? $scopedCost / $totalKm : 0.0,
                ];
            }

            // Sanitizované celoživotní statistiky neobsahují trasy, časy ani jiné osobní údaje.
            $lifetimeQuery = $this->pdo->prepare(
                'SELECT COALESCE(SUM(distance_km),0) distance_km, MAX(end_odometer_km) odometer_km, '
                . 'COALESCE(SUM(consumed_kwh),0) consumed_kwh, COALESCE(SUM(fuel_consumed_l),0) fuel_consumed_l '
                . 'FROM trips WHERE vehicle_id=?'
            );
            $lifetimeQuery->execute([$vehicleId]);
            $lifetimeRow = $lifetimeQuery->fetch() ?: [];
            $lifetimeKm = (float)($lifetimeRow['distance_km'] ?? 0);
            $lifetimeStats = [
                'distance_km' => $lifetimeKm,
                'odometer_km' => $lifetimeRow['odometer_km'] !== null ? (float)$lifetimeRow['odometer_km'] : (float)($vehicle['odometer_km'] ?? 0),
                'avg_consumption_kwh_100' => $lifetimeKm > 0 ? (float)($lifetimeRow['consumed_kwh'] ?? 0) / $lifetimeKm * 100 : 0.0,
                'avg_fuel_consumption_l_100' => $lifetimeKm > 0 ? (float)($lifetimeRow['fuel_consumed_l'] ?? 0) / $lifetimeKm * 100 : 0.0,
            ];
            $privacyRestricted = !empty($detailScope['restricted']);

            return get_defined_vars();
        }

        /**
         * Odhadne State of Health trakční baterie z energetické bilance jízd.
         *
         * Jednotlivé jízdy nejprve převede na odhad využitelné kapacity,
         * následně odstraní odlehlé vzorky pomocí MAD a z ponechaných jízd
         * vypočítá kapacitu jako poměr součtu spotřebované energie a součtu
         * poklesu SoC. Delší SoC intervaly tak mají přirozeně vyšší váhu.
         *
         * Jde stále o orientační výpočet. Přesnost je omezená přesností SoC
         * a významem hodnoty consumed_kwh v importovaných datech.
         *
         * @param int   $vehicleId
         * @param float $nominalKwh
         *
         * @return array<string,mixed>
         */
        private function estimateStateOfHealth(int $vehicleId, float $nominalKwh): array {
            $result = [
                'samples'          => [],
                'sample_count'     => 0,
                'raw_pct'          => NULL,
                'display_pct'      => NULL,
                'spread_pct'       => NULL,
                'soc_coverage_pct' => 0.0,
            ];

            if ($nominalKwh <= 0) {
                return $result;
            }

            $q = $this->pdo->prepare(
                'SELECT consumed_kwh, start_soc, end_soc
                 FROM trips
                 WHERE vehicle_id = ?
                   AND consumed_kwh > 0
                   AND start_soc IS NOT NULL
                   AND end_soc IS NOT NULL
                   AND (start_soc - end_soc) >= 10
                 ORDER BY started_at DESC
                 LIMIT 100'
            );
            $q->execute([$vehicleId]);

            $candidates = [];

            foreach ($q->fetchAll() as $row) {
                $startSoc    = (float)$row['start_soc'];
                $endSoc      = (float)$row['end_soc'];
                $consumedKwh = (float)$row['consumed_kwh'];
                $socDrop     = $startSoc - $endSoc;

                // Ochrana proti nekonzistentním nebo neplatným importovaným datům.
                if (
                    $startSoc < 0 || $startSoc > 100 ||
                    $endSoc < 0 || $endSoc > 100 ||
                    $socDrop < 10 || $socDrop > 100 ||
                    $consumedKwh <= 0
                ) {
                    continue;
                }

                $estimatedCapacityKwh = $consumedKwh / ($socDrop / 100);
                $samplePct            = ($estimatedCapacityKwh / $nominalKwh) * 100;

                // Široký vstupní filtr. Jemnější odstranění odlehlých hodnot
                // proběhne až statisticky pomocí MAD.
                if ($samplePct < 60 || $samplePct > 115) {
                    continue;
                }

                $candidates[] = [
                    'pct'          => $samplePct,
                    'soc_drop_pct' => $socDrop,
                    'consumed_kwh' => $consumedKwh,
                ];
            }

            // Pro robustní statistický odhad požadujeme alespoň pět jízd.
            if (count($candidates) < 5) {
                $result['samples']      = array_column($candidates, 'pct');
                $result['sample_count'] = count($candidates);

                return $result;
            }

            $values = array_column($candidates, 'pct');
            $median = $this->median($values);

            $deviations = [];
            foreach ($values as $value) {
                $deviations[] = abs($value - $median);
            }

            $mad = $this->median($deviations);

            // 1.4826 převádí MAD na robustní ekvivalent směrodatné odchylky
            // při přibližně normálním rozdělení. Minimální pásmo 3 p. b. brání
            // příliš agresivnímu filtrování u velmi kompaktních dat.
            $outlierBand = max(3.0, 3.0 * 1.4826 * $mad);
            $filtered    = [];

            foreach ($candidates as $candidate) {
                if (abs($candidate['pct'] - $median) <= $outlierBand) {
                    $filtered[] = $candidate;
                }
            }

            if (count($filtered) < 5) {
                $result['samples']      = array_column($filtered, 'pct');
                $result['sample_count'] = count($filtered);

                return $result;
            }

            $energySum      = 0.0;
            $socFractionSum = 0.0;
            $filteredValues = [];
            $socCoverage    = 0.0;

            foreach ($filtered as $candidate) {
                $energySum      += $candidate['consumed_kwh'];
                $socFractionSum += $candidate['soc_drop_pct'] / 100;
                $socCoverage    += $candidate['soc_drop_pct'];
                $filteredValues[] = $candidate['pct'];
            }

            if ($socFractionSum <= 0) {
                return $result;
            }

            // Robustní agregovaný odhad: součet energie / součet změny SoC.
            // Oproti prostému průměru dostávají delší jízdy s větším poklesem
            // SoC větší váhu a méně se projeví zaokrouhlení SoC o 1–2 %.
            $estimatedCapacityKwh = $energySum / $socFractionSum;
            $rawPct               = ($estimatedCapacityKwh / $nominalKwh) * 100;

            sort($filteredValues, SORT_NUMERIC);
            $q1 = $this->percentile($filteredValues, 25);
            $q3 = $this->percentile($filteredValues, 75);

            $result['samples']          = $filteredValues;
            $result['sample_count']     = count($filteredValues);
            $result['raw_pct']          = round($rawPct, 2);
            $result['display_pct']      = round(min(100.0, max(0.0, $rawPct)), 1);
            $result['spread_pct']       = round($q3 - $q1, 2);
            $result['soc_coverage_pct'] = round($socCoverage, 1);

            return $result;
        }

        /**
         * Vrátí medián číselného pole.
         *
         * @param array<int,float> $values
         *
         * @return float
         */
        private function median(array $values): float {
            sort($values, SORT_NUMERIC);
            $count = count($values);

            if ($count === 0) {
                return 0.0;
            }

            $middle = intdiv($count, 2);

            if ($count % 2 === 1) {
                return (float)$values[$middle];
            }

            return ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
        }

        /**
         * Vrátí percentil z již seřazeného číselného pole.
         *
         * @param array<int,float> $sortedValues
         * @param float            $percentile
         *
         * @return float
         */
        private function percentile(array $sortedValues, float $percentile): float {
            $count = count($sortedValues);

            if ($count === 0) {
                return 0.0;
            }

            if ($count === 1) {
                return (float)$sortedValues[0];
            }

            $position = ($percentile / 100) * ($count - 1);
            $lower    = (int)floor($position);
            $upper    = (int)ceil($position);

            if ($lower === $upper) {
                return (float)$sortedValues[$lower];
            }

            $weight = $position - $lower;

            return (float)$sortedValues[$lower] * (1 - $weight)
                + (float)$sortedValues[$upper] * $weight;
        }
    }

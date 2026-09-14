<?php
    declare(strict_types=1);
    
    
    function csvVinFromFilename(?string $originalName): ?string {
        if (!$originalName) {
            return NULL;
        }
        if (!preg_match('/([A-HJ-NPR-Z0-9]{17})/i', $originalName, $m)) {
            return NULL;
        }
        
        return strtoupper($m[1]);
    }
    
    function inspectTripCsv(string $path, ?string $originalName = NULL): array {
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
            $map    = array_flip($header);
            $format = detectTripCsvFormat($map);
        } finally {
            fclose($fh);
        }
        
        $vin           = csvVinFromFilename($originalName);
        $suggestedName = 'Škoda EV';
        $batteryKwh    = 77.0;
        $nominalKwh    = 77.0;
        if ($format === 'citigo_iv') {
            $suggestedName = 'Škoda Citigo iV';
            $batteryKwh    = 32.3;
            $nominalKwh    = 32.3;
        } elseif ($vin !== NULL && strpos($vin, 'TMBNH') === 0) {
            $suggestedName = 'Škoda Elroq';
        }
        
        return [
            'vin'                 => $vin,
            'format'              => $format,
            'suggested_name'      => $suggestedName,
            'battery_kwh'         => $batteryKwh,
            'battery_nominal_kwh' => $nominalKwh,
        ];
    }
    
    /**
     * Import podporuje dva formáty exportu MyŠkoda:
     *  - "full" export (Elroq / novější vozy) s adresami, SoC a nabíjením
     *  - "citigo_iv" export se sloupci End of trip, Mileage in km, náklady atd.
     */
    function importCsv(PDO $pdo, int $vehicleId, string $path, ?string $originalName = NULL): array {
        if ($originalName) {
            validateCsvVin($pdo, $vehicleId, $originalName);
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
        
        // Odstranění případného UTF-8 BOM z prvního názvu sloupce.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        $map    = array_flip($header);
        $format = detectTripCsvFormat($map);
        
        $sql      = 'INSERT IGNORE INTO trips (
        vehicle_id, trip_hash, started_at, ended_at, classification, start_address, end_address,
        start_lat,start_lng,end_lat,end_lng,distance_km,start_odometer_km,end_odometer_km,
        driving_minutes,travel_minutes,avg_speed_kmh,consumed_kwh,avg_consumption_kwh_100,
        start_soc,end_soc,public_charging_stops,public_charge_soc_gained,short_trip,
        source_format,total_cost,total_cost_currency,electricity_cost,electricity_cost_currency,
        electricity_price_per_kwh,avg_aux_consumption_kwh_100,avg_recuperation_kwh_100
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $ins      = $pdo->prepare($sql);
        $inserted = $skipped = 0;
        
        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($fh)) !== FALSE) {
                if (!$row || count($row) < count($header)) {
                    $skipped++;
                    continue;
                }
                $v = function (string $c) use ($row, $map) {
                    return isset($map[$c]) ? ($row[$map[$c]] ?? NULL) : NULL;
                };
                
                if ($format === 'full') {
                    $record = parseFullTripRow($v);
                } else {
                    $record = parseCitigoTripRow($v);
                }
                
                if ($record === NULL) {
                    $skipped++;
                    continue;
                }
                $ins->execute(array_merge([$vehicleId], $record));
                $inserted += $ins->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            fclose($fh);
        }
        
        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'format'   => $format
        ];
    }
    
    function detectTripCsvFormat(array $map): string {
        $full   = [
            'Start of trip',
            'End of trip',
            'Start address',
            'End address',
            'Distance in km'
        ];
        $citigo = [
            'End of trip',
            'Start mileage in km',
            'End mileage in km',
            'Mileage in km',
            'Travel time in minutes',
            'Average electric consumption in kWh/100km'
        ];
        
        $hasFull = TRUE;
        foreach ($full as $c) {
            if (!isset($map[$c])) {
                $hasFull = FALSE;
                break;
            }
        }
        if ($hasFull) {
            return 'full';
        }
        
        $hasCitigo = TRUE;
        foreach ($citigo as $c) {
            if (!isset($map[$c])) {
                $hasCitigo = FALSE;
                break;
            }
        }
        if ($hasCitigo) {
            return 'citigo_iv';
        }
        
        throw new RuntimeException('Nepodporovaný formát CSV. Očekávám export jízd z MyŠkoda (Elroq/novější vozy nebo Citigo iV).');
    }
    
    function parseFullTripRow(callable $v): ?array {
        $start = DateTime::createFromFormat('d.m.Y H:i', trim((string)$v('Start of trip')));
        $end   = DateTime::createFromFormat('d.m.Y H:i', trim((string)$v('End of trip')));
        if (!$start || !$end) {
            return NULL;
        }
        
        [
            $slat,
            $slng
        ] = parseCoord($v('Start coordinates'));
        [
            $elat,
            $elng
        ] = parseCoord($v('End coordinates'));
        $distance = numOrNull($v('Distance in km'));
        if ($distance === NULL) {
            return NULL;
        }
        
        $hash = hash('sha256', implode('|', [
            'full',
            $start->format('c'),
            $end->format('c'),
            (string)$v('Start address'),
            (string)$v('End address'),
            (string)$distance
        ]));
        $bool = filter_var($v('Short trip'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        
        return [
            $hash,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            valueOrNull($v('Classification')),
            (string)$v('Start address'),
            (string)$v('End address'),
            $slat,
            $slng,
            $elat,
            $elng,
            $distance,
            numOrNull($v('Start odometer in km')),
            numOrNull($v('End odometer in km')),
            (int)($v('Driving time in minutes') ?: 0),
            (int)($v('Travel time in minutes') ?: 0),
            numOrNull($v('Average speed in km/h')),
            numOrNull($v('Electricity consumption in kWh')),
            numOrNull($v('Average electricity consumption in kWh/100km')),
            numOrNull($v('Start SoC in %')),
            numOrNull($v('End SoC in %')),
            (int)($v('Public charging stops') ?: 0),
            (float)($v('Public charge SoC gained in %') ?: 0),
            $bool,
            'full',
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL
        ];
    }
    
    function parseCitigoTripRow(callable $v): ?array {
        $end = DateTime::createFromFormat('d.m.Y H:i', trim((string)$v('End of trip')));
        if (!$end) {
            return NULL;
        }
        
        $travelMinutes = max(0, (int)($v('Travel time in minutes') ?: 0));
        $start         = clone $end;
        if ($travelMinutes > 0) {
            $start->modify('-' . $travelMinutes . ' minutes');
        }
        
        $distance = numOrNull($v('Mileage in km'));
        if ($distance === NULL) {
            return NULL;
        }
        $avgConsumption = numOrNull($v('Average electric consumption in kWh/100km'));
        $consumed       = ($avgConsumption !== NULL && $distance > 0) ? max(0.0, $distance * $avgConsumption / 100) : 0.0;
        
        $startOdo = numOrNull($v('Start mileage in km'));
        $endOdo   = numOrNull($v('End mileage in km'));
        $hash     = hash('sha256', implode('|', [
            'citigo_iv',
            $end->format('c'),
            (string)$startOdo,
            (string)$endOdo,
            (string)$distance,
            (string)$travelMinutes
        ]));
        
        return [
            $hash,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            NULL,
            '',
            '',
            NULL,
            NULL,
            NULL,
            NULL,
            $distance,
            $startOdo,
            $endOdo,
            $travelMinutes,
            $travelMinutes,
            numOrNull($v('Average speed in km/h')),
            $consumed,
            $avgConsumption,
            NULL,
            NULL,
            0,
            0,
            ($distance <= 5 ? 1 : 0),
            'citigo_iv',
            numOrNull($v('Total cost')),
            valueOrNull($v('Total cost currency')),
            numOrNull($v('Electricity cost')),
            valueOrNull($v('Electricity cost currency')),
            numOrNull($v('Electricity price per kWh')),
            numOrNull($v('Average auxiliary consumption in kWh/100km')),
            numOrNull($v('Average recuperation in kWh/100km'))
        ];
    }
    
    function numOrNull($value): ?float {
        if ($value === NULL) {
            return NULL;
        }
        $s = trim((string)$value);
        if ($s === '') {
            return NULL;
        }
        $s = str_replace(',', '.', $s);
        
        return is_numeric($s) ? (float)$s : NULL;
    }
    
    function valueOrNull($value): ?string {
        if ($value === NULL) {
            return NULL;
        }
        $s = trim((string)$value);
        
        return $s === '' ? NULL : $s;
    }
    
    function validateCsvVin(PDO $pdo, int $vehicleId, string $originalName): void {
        $csvVin = csvVinFromFilename($originalName);
        if ($csvVin === NULL) {
            return;
        }
        $q = $pdo->prepare('SELECT vin FROM vehicles WHERE id=?');
        $q->execute([$vehicleId]);
        $vehicleVin = strtoupper((string)$q->fetchColumn());
        if ($vehicleVin !== '' && $csvVin !== $vehicleVin) {
            throw new RuntimeException('CSV podle názvu patří vozidlu VIN ' . $csvVin . ', ale vybrané vozidlo má VIN ' . $vehicleVin . '.');
        }
    }

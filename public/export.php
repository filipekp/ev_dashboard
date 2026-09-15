<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  $user = $app->auth()->requireLogin();

  $vehicleId = (int)($_GET['vehicle_id'] ?? 0);
  if (!$vehicleId || !$app->auth()->canAccessVehicle($user, $vehicleId)) {
    http_response_code(403);
    exit('K tomuto vozidlu nemáte přístup.');
  }

  $vq = $pdo->prepare('SELECT * FROM vehicles WHERE id=?');
  $vq->execute([$vehicleId]);
  $vehicle = $vq->fetch();
  if (!$vehicle) {
    http_response_code(404);
    exit('Vozidlo nebylo nalezeno.');
  }

  $period = (string)($_GET['period'] ?? 'all');
  $year = (string)($_GET['year'] ?? '');
  $params = [$vehicleId];
  $where  = 'vehicle_id=?';
  if (preg_match('/^\d{4}-\d{2}$/', $period)) {
    $from = $period . '-01 00:00:00';
    $dt = new DateTime($from);
    $dt->modify('+1 month');
    $where .= ' AND started_at>=? AND started_at<?';
    $params[] = $from;
    $params[] = $dt->format('Y-m-d H:i:s');
  } elseif ($period === 'year' && preg_match('/^\d{4}$/', $year)) {
    $from = $year . '-01-01 00:00:00';
    $to = ((int)$year + 1) . '-01-01 00:00:00';
    $where .= ' AND started_at>=? AND started_at<?';
    $params[] = $from;
    $params[] = $to;
  } else {
    $period = 'all';
  }

  // Zachováme formát, ze kterého pochází většina jízd ve vybraném výřezu.
  $fq = $pdo->prepare("SELECT source_format,COUNT(*) c FROM trips WHERE $where GROUP BY source_format ORDER BY c DESC LIMIT 1");
  $fq->execute($params);
  $format = (string)($fq->fetchColumn() ?: 'full');
  if (!in_array($format, ['full', 'citigo_iv'], TRUE)) {
    $format = 'full';
  }

  $safeVin = preg_replace('/[^A-Z0-9_-]/i', '', (string)$vehicle['vin']);
  $suffix = $period === 'all' ? '' : ($period === 'year' ? '_' . $year : '_' . $period);
  $filename = 'tripStatistics_' . ($safeVin ?: 'vehicle') . $suffix . '.csv';

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $out = fopen('php://output', 'wb');
  if (!$out) {
    exit;
  }
  // UTF-8 BOM pomůže Excelu správně otevřít české znaky.
  fwrite($out, "\xEF\xBB\xBF");

  if ($format === 'citigo_iv') {
    $headers = [
      'End of trip',
      'Start mileage in km',
      'End mileage in km',
      'Mileage in km',
      'Travel time in minutes',
      'Average speed in km/h',
      'Average electric consumption in kWh/100km',
      'Total cost',
      'Total cost currency',
      'Electricity cost',
      'Electricity cost currency',
      'Electricity price per kWh',
      'Average auxiliary consumption in kWh/100km',
      'Average recuperation in kWh/100km'
    ];
  } else {
    $headers = [
      'Start of trip',
      'End of trip',
      'Classification',
      'Start address',
      'Start coordinates',
      'End address',
      'End coordinates',
      'Distance in km',
      'Start odometer in km',
      'End odometer in km',
      'Driving time in minutes',
      'Travel time in minutes',
      'Average speed in km/h',
      'Electricity consumption in kWh',
      'Average electricity consumption in kWh/100km',
      'Start SoC in %',
      'End SoC in %',
      'Public charging stops',
      'Public charge SoC gained in %',
      'Short trip'
    ];
  }
  fputcsv($out, $headers);

  $q = $pdo->prepare("SELECT * FROM trips WHERE $where ORDER BY started_at ASC");
  $q->execute($params);

  $value = function ($v) {
    return $v === NULL ? '' : (string)$v;
  };
  $coords = function ($lat, $lng) {
    return ($lat === NULL || $lng === NULL) ? '' : $lat . ', ' . $lng;
  };

  while ($t = $q->fetch()) {
    if ($format === 'citigo_iv') {
      fputcsv($out, [
        date('d.m.Y H:i', strtotime($t['ended_at'])),
        $value($t['start_odometer_km']),
        $value($t['end_odometer_km']),
        $value($t['distance_km']),
        $value($t['travel_minutes']),
        $value($t['avg_speed_kmh']),
        $value($t['avg_consumption_kwh_100']),
        $value($t['total_cost']),
        $value($t['total_cost_currency']),
        $value($t['electricity_cost']),
        $value($t['electricity_cost_currency']),
        $value($t['electricity_price_per_kwh']),
        $value($t['avg_aux_consumption_kwh_100']),
        $value($t['avg_recuperation_kwh_100'])
      ]);
    } else {
      fputcsv($out, [
        date('d.m.Y H:i', strtotime($t['started_at'])),
        date('d.m.Y H:i', strtotime($t['ended_at'])),
        $value($t['classification']),
        $value($t['start_address']),
        $coords($t['start_lat'], $t['start_lng']),
        $value($t['end_address']),
        $coords($t['end_lat'], $t['end_lng']),
        $value($t['distance_km']),
        $value($t['start_odometer_km']),
        $value($t['end_odometer_km']),
        $value($t['driving_minutes']),
        $value($t['travel_minutes']),
        $value($t['avg_speed_kmh']),
        $value($t['consumed_kwh']),
        $value($t['avg_consumption_kwh_100']),
        $value($t['start_soc']),
        $value($t['end_soc']),
        $value($t['public_charging_stops']),
        $value($t['public_charge_soc_gained']),
        (int)$t['short_trip'] === 1 ? 'true' : 'false'
      ]);
    }
  }
  fclose($out);
  exit;

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$user = $app->auth()->requireLogin();

// Dokončení údajů nově automaticky vytvořeného vozidla. Tuto akci smí
// provést i běžný uživatel, ale pouze pro vozidlo, které mu právě vzniklo importem.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'complete_new_vehicle') {
  try {
    verifyCsrf();
    $vehicleId        = (int)($_POST['vehicle_id'] ?? 0);
    $sessionVehicleId = (int)($_SESSION['new_vehicle_id'] ?? 0);
    if (!$vehicleId || $vehicleId !== $sessionVehicleId || !$app->auth()->canAccessVehicle($user, $vehicleId)) {
      throw new RuntimeException('Údaje tohoto vozidla nelze upravit.');
    }
    $name    = trim((string)($_POST['name'] ?? ''));
    $battery = (float)str_replace(',', '.', (string)($_POST['battery_kwh'] ?? '0'));
    $nominal = (float)str_replace(',', '.', (string)($_POST['battery_nominal_kwh'] ?? '0'));
    $sohRaw  = trim((string)($_POST['soh_manual_pct'] ?? ''));
    $soh     = $sohRaw === '' ? NULL : (float)str_replace(',', '.', $sohRaw);
    $home    = trim((string)($_POST['home_label'] ?? ''));
    if ($name === '' || $battery <= 0 || $nominal <= 0) {
      throw new RuntimeException('Vyplňte název a kapacitu baterie.');
    }
    if ($soh !== NULL && ($soh < 50 || $soh > 110)) {
      throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
    }
    $q = $pdo->prepare('UPDATE vehicles SET name=?,battery_kwh=?,battery_nominal_kwh=?,soh_manual_pct=?,soh_manual_at=IF(? IS NULL,NULL,NOW()),home_label=? WHERE id=?');
    $q->execute([
      $name,
      $battery,
      $nominal,
      $soh,
      $soh,
      $home ?: NULL,
      $vehicleId
    ]);
    unset($_SESSION['new_vehicle_id']);
    flash('Údaje nového vozidla byly uloženy.');
    redirect('index.php?vehicle_id=' . $vehicleId);
  } catch (Throwable $e) {
    flash($e->getMessage(), 'error');
    $vid = (int)($_POST['vehicle_id'] ?? 0);
    redirect('index.php' . ($vid > 0 ? '?vehicle_id=' . $vid . '&new_vehicle=1' : ''));
  }
}

$vehicles = $app->auth()->allowedVehicles($user);
$vehicle = $app->auth()->selectVehicle($user);
if (!$vehicle) {
$flash = getFlash();
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=20260914-3">
</head>
<body>
<header class="topbar">
  <div class="brand">⚡</div>
  <b>EV Stats</b>
  <div class="spacer"></div>
  <?php if ($app->auth()->canManageVehicles($user)): ?><a class="toplink"
                                                          href="vehicles.php">Vozidla</a><?php endif; ?><?php if ($app->auth()->isAdmin($user)): ?>
  <a
    class="toplink" href="users.php">Uživatelé</a><?php endif; ?><a class="toplink" href="profile.php">👤 Profil</a><span
  class="user-chip"><?= h($user['name']) ?></span><a class="toplink" href="logout.php">Odhlásit</a></header>
<main class="wrap narrow"><?php if ($flash): ?>
  <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <section class="card empty-state"><h1>🚙 Žádné dostupné vozidlo</h1>
    <p>Nahrajte originální CSV export z MyŠkoda. Pokud název obsahuje VIN, vozidlo se automaticky rozpozná; pokud ještě neexistuje, aplikace ho
      založí a přiřadí vašemu účtu.</p>
    <form action="upload.php" method="post" enctype="multipart/form-data" class="empty-upload"><input type="hidden" name="csrf"
                                                                                                      value="<?= h(csrfToken()) ?>"><input
      type="hidden" name="vehicle_id" value="0"><label class="btn primary">⬆ Nahrát CSV<input type="file" name="csv" accept=".csv,text/csv"
                                                                                              onchange="this.form.submit()"></label><?php if ($app->auth()->canManageVehicles($user)): ?>
      <a class="btn" href="vehicles.php">Přidat ručně</a><?php endif; ?></form>
  </section>
</main>
<footer class="site-footer">created by: &copy; 2026 Pavel Filípek (<a href="https://www.filipek-czech.cz" target="_blank" rel="noopener noreferrer">www.filipek-czech.cz</a>) · verze <?= h($app->version()->label()) ?>
</footer>
</body>
</html><?php exit;
}

$newVehicleModal = NULL;
if ((string)($_GET['new_vehicle'] ?? '') === '1' && (int)($_SESSION['new_vehicle_id'] ?? 0) === (int)$vehicle['id']) {
  $q = $pdo->prepare('SELECT * FROM vehicles WHERE id=?');
  $q->execute([(int)$vehicle['id']]);
  $newVehicleModal = $q->fetch() ?: NULL;
}

$flash = getFlash();

// Dostupné měsíce/roky potřebujeme znát ještě před sestavením filtru období,
// protože režim "Celý rok" filtruje podle samostatného parametru year.
$months = $pdo->prepare('SELECT DISTINCT DATE_FORMAT(started_at, "%Y-%m") m FROM trips WHERE vehicle_id=? ORDER BY m');
$months->execute([(int)$vehicle['id']]);
$monthOptions = $months->fetchAll(PDO::FETCH_COLUMN);
$years = [];
foreach ($monthOptions as $m) {
  $y = substr((string)$m, 0, 4);
  if (!in_array($y, $years, TRUE)) {
    $years[] = $y;
  }
}

$period = (string)($_GET['period'] ?? 'all');
$selectedYear = (string)($_GET['year'] ?? '');
if (preg_match('/^\d{4}-\d{2}$/', $period)) {
  $selectedYear = substr($period, 0, 4);
}
if (!in_array($selectedYear, $years, TRUE)) {
  $selectedYear = $years ? (string)end($years) : date('Y');
}

$params = [(int)$vehicle['id']];
$where = 'vehicle_id = ?';
if (preg_match('/^\d{4}-\d{2}$/', $period)) {
  $from = $period . '-01 00:00:00';
  $dt   = new DateTime($from);
  $dt->modify('+1 month');
  $to       = $dt->format('Y-m-d H:i:s');
  $where    .= ' AND started_at >= ? AND started_at < ?';
  $params[] = $from;
  $params[] = $to;
} elseif ($period === 'year') {
  $from = $selectedYear . '-01-01 00:00:00';
  $to   = ((int)$selectedYear + 1) . '-01-01 00:00:00';
  $where    .= ' AND started_at >= ? AND started_at < ?';
  $params[] = $from;
  $params[] = $to;
} else {
  $period = 'all';
}

// Souhrnné hodnoty počítáme přímo v DB. Do PHP tak není nutné načítat celou historii jízd.
$summaryQ = $pdo->prepare("SELECT
      COUNT(*) trip_count,
      COALESCE(SUM(distance_km),0) total_km,
      COALESCE(SUM(consumed_kwh),0) total_kwh,
      COALESCE(SUM(driving_minutes),0) drive_min,
      COALESCE(SUM(travel_minutes),0) travel_min,
      COALESCE(SUM(short_trip),0) short_trips,
      COALESCE(SUM(public_charging_stops),0) public_stops,
      COALESCE(SUM(public_charge_soc_gained),0) public_soc,
      MIN(start_odometer_km) odo_min,
      MAX(end_odometer_km) odo_max,
      MIN(end_soc) min_soc,
      SUM(CASE WHEN start_soc IS NOT NULL OR end_soc IS NOT NULL OR public_charging_stops>0 OR public_charge_soc_gained>0 THEN 1 ELSE 0 END) charge_rows,
      SUM(CASE WHEN start_address<>'' OR end_address<>'' THEN 1 ELSE 0 END) location_rows,
      SUM(CASE WHEN electricity_cost IS NOT NULL OR total_cost IS NOT NULL THEN 1 ELSE 0 END) cost_rows,
      COALESCE(SUM(electricity_cost),0) electricity_cost_total
    FROM trips WHERE $where");
$summaryQ->execute($params);
$summary = $summaryQ->fetch() ?: [];

$tripCount = (int)($summary['trip_count'] ?? 0);
$totalKm = (float)($summary['total_km'] ?? 0);
$totalKwh = (float)($summary['total_kwh'] ?? 0);
$avgCons = $totalKm > 0 ? $totalKwh / $totalKm * 100 : 0;
$driveMin = (int)($summary['drive_min'] ?? 0);
$travelMin = (int)($summary['travel_min'] ?? 0);
$avgSpeed = $driveMin > 0 ? $totalKm / ($driveMin / 60) : 0;
$range = $avgCons > 0 ? (float)$vehicle['battery_kwh'] / $avgCons * 100 : 0;
$shortTrips = (int)($summary['short_trips'] ?? 0);
$chargeDataAvailable = (int)($summary['charge_rows'] ?? 0) > 0;
$locationDataAvailable = (int)($summary['location_rows'] ?? 0) > 0;
$costDataAvailable = (int)($summary['cost_rows'] ?? 0) > 0;
$costTotal = (float)($summary['electricity_cost_total'] ?? 0);
$publicStops = (int)($summary['public_stops'] ?? 0);
$publicSoc = (float)($summary['public_soc'] ?? 0);
$publicKwh = $publicSoc / 100 * (float)$vehicle['battery_kwh'];
$homeKwh = max(0, $totalKwh - $publicKwh);
$chargeTotal = max(.001, $homeKwh + $publicKwh);
$homePct = $homeKwh / $chargeTotal * 100;
$publicPct = $publicKwh / $chargeTotal * 100;
$odoMin = $summary['odo_min'] !== NULL ? (float)$summary['odo_min'] : 0;
$odoMax = $summary['odo_max'] !== NULL ? (float)$summary['odo_max'] : 0;
$minSoc = $summary['min_soc'] !== NULL ? (float)$summary['min_soc'] : NULL;

$monthLabels = [];
$monthKm = [];
$monthCons = [];
$monthQ = $pdo->prepare("SELECT DATE_FORMAT(started_at,'%Y-%m') m, SUM(distance_km) km, SUM(consumed_kwh) kwh FROM trips WHERE $where GROUP BY m ORDER BY m");
$monthQ->execute($params);
foreach ($monthQ->fetchAll() as $r) {
  $monthLabels[] = substr($r['m'], 5, 2) . '/' . substr($r['m'], 0, 4);
  $monthKm[]     = round((float)$r['km'], 1);
  $monthCons[]   = round((float)$r['km'] > 0 ? (float)$r['kwh'] / (float)$r['km'] * 100 : 0, 1);
}

$hourData = array_fill(0, 24, 0);
$hourQ = $pdo->prepare("SELECT HOUR(started_at) h, COUNT(*) c FROM trips WHERE $where GROUP BY h");
$hourQ->execute($params);
foreach ($hourQ->fetchAll() as $r) {
  $hourData[(int)$r['h']] = (int)$r['c'];
}

$bandLabels = [
  'Město (<40 km/h)',
  'Okresky (40–65 km/h)',
  'Rychlé okresky (65–85 km/h)',
  'Dálnice (>85 km/h)'
];
$bandValues = array_fill(0, 4, 0.0);
$speedQ = $pdo->prepare("SELECT
      CASE WHEN avg_speed_kmh<40 THEN 0 WHEN avg_speed_kmh<65 THEN 1 WHEN avg_speed_kmh<=85 THEN 2 ELSE 3 END band,
      SUM(distance_km) km, SUM(consumed_kwh) kwh
    FROM trips WHERE $where GROUP BY band");
$speedQ->execute($params);
foreach ($speedQ->fetchAll() as $r) {
  $idx              = (int)$r['band'];
  $km               = (float)$r['km'];
  $bandValues[$idx] = round($km > 0 ? (float)$r['kwh'] / $km * 100 : 0, 1);
}

$routes = [];
$routeQ = $pdo->prepare("SELECT start_address,end_address,COUNT(*) c,SUM(distance_km) km,SUM(consumed_kwh) kwh
    FROM trips WHERE $where AND (start_address<>'' OR end_address<>'')
    GROUP BY start_address,end_address ORDER BY c DESC, km DESC LIMIT 10");
$routeQ->execute($params);
foreach ($routeQ->fetchAll() as $r) {
  $routes[displayRoute($r['start_address'], $r['end_address'])] = [
    'count' => (int)$r['c'],
    'km'    => (float)$r['km'],
    'kwh'   => (float)$r['kwh']
  ];
}

$longCountQ = $pdo->prepare("SELECT COUNT(*) FROM trips WHERE $where AND distance_km>=80");
$longCountQ->execute($params);
$longTripCount = (int)$longCountQ->fetchColumn();
$longQ = $pdo->prepare("SELECT * FROM trips WHERE $where AND distance_km>=80 ORDER BY started_at DESC LIMIT 100");
$longQ->execute($params);
$longTrips = $longQ->fetchAll();

$monthsForYear = array_values(array_filter($monthOptions, function ($m) use ($selectedYear) {
  return substr((string)$m, 0, 4) === $selectedYear;
}));
$homeLabel = (string)($vehicle['home_label'] ?? '');

// Stránkovaná historie jízd.
$perPage = 25;
$historyPage = max(1, (int)($_GET['page'] ?? 1));
$historyPages = max(1, (int)ceil($tripCount / $perPage));
if ($historyPage > $historyPages) {
  $historyPage = $historyPages;
}
$historyOffset = ($historyPage - 1) * $perPage;
$historyQ = $pdo->prepare("SELECT * FROM trips WHERE $where ORDER BY started_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$historyOffset);
$historyQ->execute($params);
$historyTrips = $historyQ->fetchAll();

// State of Health: preferujeme hodnotu z BMS/diagnostiky. Pokud chybí, používáme
// robustní orientační odhad z jízd bez veřejného nabíjení a s poklesem SoC alespoň 10 p. b.
$nominalKwh = (float)($vehicle['battery_nominal_kwh'] ?: $vehicle['battery_kwh']);
$sohManual = $vehicle['soh_manual_pct'] !== NULL ? (float)$vehicle['soh_manual_pct'] : NULL;
$sohSamples = [];
$sohQ = $pdo->prepare('SELECT consumed_kwh,start_soc,end_soc FROM trips WHERE vehicle_id=? AND public_charging_stops=0 AND consumed_kwh>0 AND start_soc IS NOT NULL AND end_soc IS NOT NULL AND (start_soc-end_soc)>=10 ORDER BY started_at DESC LIMIT 30');
$sohQ->execute([(int)$vehicle['id']]);
foreach ($sohQ->fetchAll() as $r) {
  $drop = (float)$r['start_soc'] - (float)$r['end_soc'];
  if ($drop <= 0) {
    continue;
  }
  $cap = (float)$r['consumed_kwh'] / ($drop / 100);
  $pct = $nominalKwh > 0 ? $cap / $nominalKwh * 100 : 0;
  if ($pct >= 60 && $pct <= 110) {
    $sohSamples[] = $pct;
  }
}
sort($sohSamples);
$sohEstimated = NULL;
if (count($sohSamples) >= 3) {
  $n            = count($sohSamples);
  $sohEstimated = $n % 2 ? $sohSamples[intdiv($n, 2)] : ($sohSamples[$n / 2 - 1] + $sohSamples[$n / 2]) / 2;
}
$soh = $sohManual ?? $sohEstimated;
$sohSource = $sohManual !== NULL ? 'BMS / diagnostika' : ($sohEstimated !== NULL ? 'orientační odhad z jízd' : 'nedostatek dat');
$sohClass = $soh === NULL ? '' : ($soh >= 90 ? 'green' : ($soh >= 80 ? 'orange' : 'pink'));
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=20260914-3">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>
<header class="topbar">
  <div class="brand">⚡</div>
  <form class="vehicle-switch" method="get"><select name="vehicle_id" onchange="this.form.submit()"
                                                    aria-label="Vybrat vozidlo"><?php foreach ($vehicles as $v): ?>
    <option
      value="<?= $v['id'] ?>" <?= (int)$v['id'] === (int)$vehicle['id'] ? 'selected' : '' ?>><?= (int)($user['default_vehicle_id'] ?? 0) === (int)$v['id'] ? '★ ' : '' ?><?= h($v['name']) ?>
      · <?= h($v['vin']) ?></option>
    <?php endforeach; ?></select></form>
  <div class="vehicle">
    <b><?= h($vehicle['name']) ?></b><span><?= cz((float)$vehicle['battery_kwh'], 0) ?> kWh</span><small>VIN: <?= h($vehicle['vin']) ?></small></div>
  <div class="spacer"></div>
  <?php if ($app->auth()->canManageVehicles($user)): ?><a class="toplink" href="vehicles.php">🚙
  Vozidla</a><?php endif; ?><?php if ($app->auth()->isAdmin($user)): ?><a
  class="toplink" href="users.php">👥 Uživatelé</a><?php endif; ?><?php if ($app->auth()->isAdmin($user)): ?><a class="toplink" href="update.php">🔄
  Aktualizace</a><?php endif; ?>
  <form action="upload.php" method="post" enctype="multipart/form-data" class="upload"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input
    type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>"><label>⬆ Nahrát CSV<input type="file" name="csv" accept=".csv,text/csv"
                                                                                            onchange="this.form.submit()"></label></form>
  <a class="toplink" href="profile.php">👤 Profil</a><span class="user-chip"><?= h($user['name']) ?></span><a class="toplink" href="logout.php">Odhlásit</a>
  <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Otevřít menu" aria-controls="mobileMenuOverlay"
          aria-expanded="false">☰
  </button>
</header>
<div class="mobile-menu-overlay" id="mobileMenuOverlay" hidden>
  <div class="mobile-menu-header">
    <strong>EV Stats</strong>
    <button type="button" class="mobile-menu-close" id="mobileMenuClose" aria-label="Zavřít menu">×</button>
  </div>
  <div class="mobile-menu-account">Přihlášen: <strong><?= h($user['name']) ?></strong></div>
  <nav class="mobile-menu-links" aria-label="Mobilní navigace">
    <?php if ($app->auth()->canManageVehicles($user)): ?><a href="vehicles.php">🚙 <span>Vozidla</span></a><?php endif; ?>
    <?php if ($app->auth()->isAdmin($user)): ?><a href="users.php">👥 <span>Uživatelé</span></a><a href="update.php">🔄
    <span>Aktualizace</span></a><?php endif; ?>
    <form action="upload.php" method="post" enctype="multipart/form-data" class="mobile-menu-upload">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
      <label>⬆ <span>Nahrát CSV</span><input type="file" name="csv" accept=".csv,text/csv" onchange="this.form.submit()"></label>
    </form>
    <a href="profile.php">👤 <span>Profil</span></a>
    <a href="logout.php" class="mobile-menu-logout">↪ <span>Odhlásit</span></a>
  </nav>
</div>
<main class="wrap">
  <?php if ($flash): ?>
  <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($newVehicleModal): ?>
  <div class="modal-backdrop" id="newVehicleModal">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="newVehicleTitle">
      <div class="modal-head">
        <div><small>NOVÉ VOZIDLO ROZPOZNÁNO</small>
          <h2 id="newVehicleTitle">🚙 Doplňte údaje o vozidle</h2></div>
        <button type="button" class="modal-close" onclick="document.getElementById('newVehicleModal').remove()" aria-label="Zavřít">×</button>
      </div>
      <p>VIN <b><?= h($newVehicleModal['vin']) ?></b> byl nalezen v názvu CSV. Vozidlo už bylo založeno, přiřazeno vašemu účtu a data byla
        importována. Zkontrolujte předvyplněné hodnoty.</p>
      <form method="post" class="modal-form">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="complete_new_vehicle"><input
        type="hidden" name="vehicle_id" value="<?= $newVehicleModal['id'] ?>">
        <label>Název vozidla<input name="name" value="<?= h($newVehicleModal['name']) ?>" required autofocus></label>
        <label>VIN<input value="<?= h($newVehicleModal['vin']) ?>" disabled></label>
        <label>Aktuálně využitelná kapacita (kWh)<input name="battery_kwh" type="number" step="0.1" min="1"
                                                        value="<?= h((string)$newVehicleModal['battery_kwh']) ?>" required></label>
        <label>Nominální kapacita nového vozu (kWh)<input name="battery_nominal_kwh" type="number" step="0.1" min="1"
                                                          value="<?= h((string)($newVehicleModal['battery_nominal_kwh'] ?: $newVehicleModal['battery_kwh'])) ?>"
                                                          required></label>
        <label>SoH z diagnostiky (%) <span>volitelné</span><input name="soh_manual_pct" type="number" step="0.1" min="50" max="110"
                                                                  value="<?= h((string)($newVehicleModal['soh_manual_pct'] ?? '')) ?>"
                                                                  placeholder="např. 96,5"></label>
        <label>Domácí lokalita <span>volitelné</span><input name="home_label" value="<?= h((string)($newVehicleModal['home_label'] ?? '')) ?>"
                                                            placeholder="např. Vilémov 173"></label>
        <div class="modal-actions">
          <button type="button" class="btn" onclick="document.getElementById('newVehicleModal').remove()">Doplnit později</button>
          <button class="btn primary">Uložit vozidlo</button>
        </div>
      </form>
    </section>
  </div>
  <?php endif; ?>
  <section class="period-filter">
    <div class="period-main">
      <b>🗓 Období:</b>
      <div class="period-actions">
        <a class="<?= $period === 'all' ? 'active' : '' ?>"
           href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=all&amp;year=<?= h($selectedYear) ?>">Celá historie</a>
        <a class="<?= $period === 'year' ? 'active' : '' ?>"
           href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=year&amp;year=<?= h($selectedYear) ?>">Celý rok</a>
      </div>
      <form method="get" class="year-filter">
        <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
        <input type="hidden" name="period" value="<?= $period === 'all' ? 'all' : 'year' ?>">
        <label for="periodYear">Rok</label>
        <select id="periodYear" name="year" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
          <option value="<?= h($y) ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="period-months">
      <?php foreach ($monthsForYear as $m): ?><a class="<?= $period === $m ? 'active' : '' ?>"
                                                 href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($m) ?>&amp;year=<?= h($selectedYear) ?>"><?= h(substr($m, 5, 2) . '/' . substr($m, 0, 4)) ?></a><?php endforeach; ?>
    </div>
  </section>
  <section class="kpis">
    <div class="card kpi"><small>CELKOVÝ NÁJEZD</small><strong><?= cz($totalKm, 0) ?>
      <em>km</em></strong><span>Tachometr: <?= cz($odoMin, 0) ?> → <?= cz($odoMax, 0) ?> km</span></div>
    <div class="card kpi"><small>PRŮM. SPOTŘEBA</small><strong class="green"><?= cz($avgCons, 2) ?> <em>kWh/100
      km</em></strong><span>Spotřebováno: <?= cz($totalKwh, 1) ?> kWh</span></div>
    <div class="card kpi"><small>ODHAD DOJEZDU</small><strong>~<?= cz($range, 0) ?> <em>km</em></strong><span>na 100 % baterie</span></div>
    <div class="card kpi"><small>DOBA JÍZDY</small><strong><?= cz($driveMin / 60, 1) ?> <em>hod</em></strong><span>Průměr: <?= cz($avgSpeed, 1) ?> km/h</span>
    </div>
    <div class="card kpi"><small>POMALÉ / DC</small><?php if ($chargeDataAvailable): ?><strong><?= cz($homePct, 0) ?>% <em>/ <?= cz($publicPct, 0) ?>
      %</em></strong><span>Veřejné DC: <?= cz($publicStops, 0) ?>× · <?= cz($publicKwh, 1) ?> kWh*</span><?php else: ?><strong>—</strong><span>CSV neobsahuje SoC ani nabíjení</span><?php endif; ?>
    </div>
    <div class="card kpi"><small>JÍZDY CELKEM</small><strong><?= $tripCount ?></strong><span>Z toho krátkých: <?= $shortTrips ?></span></div>
    <div class="card kpi soh-kpi"><small>🔋 STATE OF HEALTH</small><?php if ($soh !== NULL): ?><strong class="<?= $sohClass ?>"><?= cz($soh, 1) ?> <em>%</em>
    </strong><span><?= $sohSource ?><?= count($sohSamples) ? ' · ' . count($sohSamples) . ' vzorků' : '' ?></span><?php else: ?><strong>—</strong>
      <span>Nedostatek dat pro spolehlivý odhad</span><?php endif; ?></div>
  </section>
  <section class="grid2">
    <div class="card chart-card"><h2>📈 Měsíční nájezd a průměrná spotřeba</h2>
      <p>Kilometry (sloupce) vs. spotřeba v kWh/100 km (křivka)</p>
      <canvas id="monthly"></canvas>
    </div>
    <div class="card chart-card"><h2>⏰ Denní rytmus (v kolik hodin vyjíždíte)</h2>
      <p>Četnost výjezdů podle hodin dne</p>
      <canvas id="hours"></canvas>
    </div>
    <div class="card energy"><h2>⚡ Energetická bilance a nabíjecí lokality</h2><?php if ($chargeDataAvailable): ?><p>Odhad energie podle veřejného
      nabíjení zaznamenaného v CSV</p>
      <div class="energy-layout">
        <div class="energy-chart-wrap">
          <canvas id="energy" width="120" height="120"></canvas>
        </div>
        <div class="energy-list">
          <div><b class="green">🏠 Domácí / ostatní AC</b><strong><?= cz($homeKwh, 0) ?> kWh</strong><small><?= cz($homePct, 0) ?>%</small></div>
          <div><b class="orange">⚡ Veřejné DC</b><strong><?= cz($publicKwh, 0) ?> kWh</strong><small><?= cz($publicPct, 0) ?>%</small></div>
        </div>
      </div>
      <footer>Celkem v bilanci: <b><?= cz($chargeTotal, 0) ?> kWh</b><span>* veřejná energie je odhad z přírůstku SoC</span></footer>
      <?php else: ?>
      <p>Tento typ CSV neobsahuje SoC ani události nabíjení.</p>
      <div class="energy-list">
        <div><b class="green">🔋 Energie spotřebovaná jízdami</b><strong><?= cz($totalKwh, 1) ?> kWh</strong><small>vypočteno z průměrné spotřeby a
          vzdálenosti</small></div>
        <?php if ($costDataAvailable): ?>
        <div><b class="orange">💰 Náklady na elektřinu</b><strong><?= cz($costTotal, 0) ?> CZK</strong><small>součet hodnot z CSV</small>
        </div>
        <?php endif; ?></div>
      <?php endif; ?></div>
    <div class="card chart-card"><h2>🏎 Spotřeba podle rychlostních pásem</h2>
      <p>Jízdy jsou seskupené podle průměrné rychlosti</p>
      <canvas id="speed"></canvas>
    </div>
    <div class="card"><h2>🔄 Pravidelné dojíždění</h2>
      <p>Nejčastější směry v importovaných datech</p><?php if ($routes): ?>
      <div class="route-cards"><?php $i = 0;
      foreach ($routes as $name => $r) {
      if ($i++ >= 2) {
        break;
      }
      $c = $r['km'] ? $r['kwh'] / $r['km'] * 100 : 0; ?>
        <div><b><?= h($name) ?></b><span><?= $r['count'] ?>×</span><strong><?= cz($c, 1) ?> <em>kWh/100 km</em></strong><small>Průměrná
          délka: <?= cz($r['km'] / $r['count'], 1) ?> km</small></div>
        <?php } ?></div>
      <?php else: ?><p><b>Trasy nejsou v tomto CSV k dispozici.</b>
        Citigo iV export neobsahuje adresy začátku a konce jízdy.</p><?php endif; ?>
      <div class="battery">🛡️ <b>Šetrné nabíjení
        baterie</b><span><?php if ($minSoc !== NULL): ?>Nejnižší zaznamenané SoC: <?= cz($minSoc, 0) ?> %.<?php else: ?>SoC není v tomto CSV k dispozici.<?php endif; ?></span>
        <mark>Stav: informativní</mark>
      </div>
    </div>
    <div class="card"><h2>📍 Nejčastější pravidelné trasy</h2>
      <p>Statistika tras s nejvyšším počtem opakování</p><?php if ($routes): ?>
      <div class="route-list"><?php $i = 0;
      foreach ($routes as $name => $r) {
      if ($i++ >= 3) {
        break;
      }
      $c = $r['km'] ? $r['kwh'] / $r['km'] * 100 : 0; ?>
        <div><span><b><?= h($name) ?></b><small><?= cz($r['km'] / $r['count'], 1) ?> km průměr</small></span>
          <mark><?= cz($c, 1) ?> kWh</mark>
          <small><?= $r['count'] ?>× jízda</small></div>
        <?php } ?></div>
      <?php else: ?><p><b>Trasy nejsou dostupné.</b> Export Citigo iV neobsahuje GPS
        ani adresy.</p><?php endif; ?></div>
  </section>
  <section class="card table-card"><h2>🗺 Cesty &gt; 80 km <span class="pill"><?= $longTripCount ?> tras</span></h2>
    <p>Přehled delších tras včetně nabíjení na cestách</p>
    <div class="table-wrap">
      <table>
        <thead>
        <tr>
          <th>DATUM</th>
          <th>ODKUD → KAM</th>
          <th>VZDÁLENOST</th>
          <th>ČAS JÍZDY</th>
          <th>PRŮM. RYCHLOST</th>
          <th>PRŮM. SPOTŘEBA</th>
          <th>SPOTŘEBOVÁNO</th>
          <th>NABÍJENÍ</th>
        </tr>
        </thead>
        <tbody><?php foreach ($longTrips as $t): ?>
        <tr>
          <td><?= date('d.m.Y H:i', strtotime($t['started_at'])) ?></td>
          <td><b><?= h(displayRoute($t['start_address'], $t['end_address'])) ?></b></td>
          <td><b><?= cz($t['distance_km'], 1) ?> km</b></td>
          <td><?= intdiv((int)$t['driving_minutes'], 60) ?> h <?= ((int)$t['driving_minutes']) % 60 ?> m</td>
          <td><?= cz($t['avg_speed_kmh'], 0) ?> km/h</td>
          <td>
            <mark><?= cz($t['avg_consumption_kwh_100'], 1) ?> kWh/100km</mark>
          </td>
          <td><b><?= cz($t['consumed_kwh'], 1) ?> kWh</b></td>
          <td><?= (float)$t['public_charge_soc_gained'] > 0 ? '<mark>+' . cz($t['public_charge_soc_gained'], 0) . '%</mark>' : '—' ?></td>
        </tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
  </section>
  <section class="card table-card history-card">
    <div class="table-card-head">
      <div><h2>📋 Seznam a historie jízd</h2>
        <p>Jízdy v aktuálním filtru · stránka <?= $historyPage ?> z <?= $historyPages ?></p></div>
      <a class="btn export-btn" href="export.php?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>">⬇ Export CSV</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
        <tr>
          <th>DATUM & ČAS</th>
          <th>ODKUD → KAM</th>
          <th>KM</th>
          <th>ČAS</th>
          <th>RYCHLOST</th>
          <th>PRŮM. SPOTŘEBA</th>
          <th>SPOTŘEBOVÁNO</th>
          <th>SOC</th>
          <th>NABÍJENÍ</th>
        </tr>
        </thead>
        <tbody><?php foreach ($historyTrips as $t): ?>
        <tr>
          <td><?= date('d.m.Y H:i', strtotime($t['started_at'])) ?></td>
          <td><b><?= h(displayRoute($t['start_address'], $t['end_address'])) ?></b></td>
          <td><b><?= cz($t['distance_km'], 1) ?></b></td>
          <td><?= cz($t['driving_minutes'], 0) ?>m</td>
          <td><?= cz($t['avg_speed_kmh'], 0) ?> km/h</td>
          <td><b><?= cz($t['avg_consumption_kwh_100'], 1) ?></b></td>
          <td><b><?= cz($t['consumed_kwh'], 1) ?></b> kWh</td>
          <td><?php if ($t['start_soc'] !== NULL || $t['end_soc'] !== NULL): ?><?= cz($t['start_soc'], 0) ?>% → <b><?= cz($t['end_soc'], 0) ?>
            %</b><?php else: ?>—<?php endif; ?></td>
          <td><?= (float)$t['public_charge_soc_gained'] > 0 ? '<mark>+' . cz($t['public_charge_soc_gained'], 0) . '%</mark>' : '•' ?></td>
        </tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
    <?php if ($historyPages > 1): ?>
    <nav class="pagination" aria-label="Stránkování historie jízd">
      <?php if ($historyPage > 1): ?><a
      href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>&amp;page=<?= $historyPage - 1 ?>">←
      Předchozí</a><?php endif; ?>
      <span>Stránka <b><?= $historyPage ?></b> / <?= $historyPages ?> · <?= $tripCount ?> jízd</span>
      <?php if ($historyPage < $historyPages): ?><a
      href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>&amp;page=<?= $historyPage + 1 ?>">
      Další →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>
</main>
<footer class="site-footer">created by: &copy; 2026 Pavel Filípek (<a href="https://www.filipek-czech.cz" target="_blank" rel="noopener noreferrer">www.filipek-czech.cz</a>) · verze <?= h($app->version()->label()) ?>
</footer>
<script>
  const mobileMenuToggle = document.getElementById('mobileMenuToggle');
  const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
  const mobileMenuClose = document.getElementById('mobileMenuClose');
  const openMobileMenu = () => {
    mobileMenuOverlay.hidden = false;
    document.body.classList.add('mobile-menu-open');
    mobileMenuToggle.setAttribute('aria-expanded', 'true');
    mobileMenuClose.focus();
  };
  const closeMobileMenu = () => {
    mobileMenuOverlay.hidden = true;
    document.body.classList.remove('mobile-menu-open');
    mobileMenuToggle.setAttribute('aria-expanded', 'false');
    mobileMenuToggle.focus();
  };
  mobileMenuToggle.addEventListener('click', openMobileMenu);
  mobileMenuClose.addEventListener('click', closeMobileMenu);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !mobileMenuOverlay.hidden) {
      closeMobileMenu();
    }
  });
  const monthlyLabels = <?=json_encode($monthLabels, JSON_UNESCAPED_UNICODE)?>, monthlyKm = <?=json_encode($monthKm)?>,
    monthlyCons = <?=json_encode($monthCons)?>;
  const hours = <?=json_encode(array_values($hourData))?>, speedLabels = <?=json_encode($bandLabels, JSON_UNESCAPED_UNICODE)?>,
    speedValues = <?=json_encode($bandValues)?>;
  const grid = 'rgba(148,163,184,.12)', tick = '#9fb4d0';
  Chart.defaults.color = tick;
  Chart.defaults.font.family = 'Inter,system-ui,sans-serif';
  new Chart(document.getElementById('monthly'), {
    data   : {
      labels  : monthlyLabels,
      datasets: [{
        type           : 'bar',
        label          : 'Ujeto km',
        data           : monthlyKm,
        borderWidth    : 1,
        borderRadius   : 7,
        backgroundColor: 'rgba(16,185,129,.35)',
        borderColor    : '#10b981',
        yAxisID        : 'y'
      }, {
        type           : 'line',
        label          : 'Spotřeba (kWh/100km)',
        data           : monthlyCons,
        borderColor    : '#22d3ee',
        backgroundColor: '#22d3ee',
        tension        : .3,
        yAxisID        : 'y1'
      }]
    },
    options: {
      responsive: true,
      scales    : {
        x : { grid: { color: grid } },
        y : { grid: { color: grid }, beginAtZero: true },
        y1: { position: 'right', grid: { drawOnChartArea: false } }
      }
    }
  });
  new Chart(document.getElementById('hours'), {
    type   : 'bar',
    data   : {
      labels  : Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0') + ':00'),
      datasets: [{
        data           : hours,
        backgroundColor: hours.map((v, i) => [7, 8, 16, 17].includes(i) ? '#10b981' : 'rgba(56,189,248,.45)'),
        borderRadius   : 6
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales : { x: { grid: { color: grid } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid } } }
    }
  });
  <?php if($chargeDataAvailable): ?>
  const energyCanvas = document.getElementById('energy');
  new Chart(energyCanvas, {
    type   : 'doughnut',
    data   : {
      labels  : ['AC / ostatní', 'Veřejné DC'],
      datasets: [{
        data           : [
          <?=round($homeKwh, 2)?>,
          <?=round($publicKwh, 2)?>
        ],
        backgroundColor: ['#10b981', '#f59e0b'],
        borderWidth    : 0
      }]
    },
    options: {
      events    : [],
      cutout    : '72%',
      plugins   : {
        legend : {
          display: false
        },
        tooltip: {
          enabled: false
        }
      }
    }
  });
  <?php endif; ?>
  new Chart(document.getElementById('speed'), {
    type   : 'bar',
    data   : {
      labels  : speedLabels,
      datasets: [{ data: speedValues, backgroundColor: ['#22d3ee', '#10b981', '#f59e0b', '#f43f5e'], borderRadius: 7 }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales : { x: { grid: { color: grid } }, y: { grid: { color: grid }, suggestedMin: 10 } }
    }
  });
</script>
</body>
</html>

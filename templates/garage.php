<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Garage – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=20260915-garage">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">⚡</a>
  <div class="vehicle"><b>Garage dashboard</b><small><?= count($vehicles) ?> vozidel v přehledu</small></div>
  <div class="spacer"></div>
  <a class="toplink" href="index.php">📊 Dashboard</a>
  <?php if ($app->auth()->canManageVehicles($user)): ?><a class="toplink" href="vehicles.php">🚙 Vozidla</a><?php endif; ?>
  <a class="toplink" href="profile.php">👤 Profil</a>
  <span class="user-chip"><?= h($user['name']) ?></span>
  <a class="toplink" href="logout.php">Odhlásit</a>
</header>

<main class="wrap garage-wrap">
  <?php if ($flash): ?><div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <section class="garage-heading">
    <div>
      <h1>🚘 Garage dashboard</h1>
      <p>Porovnání celé garáže, TCO, meziroční trendy a sezónní efektivita.</p>
    </div>
    <form method="get" class="garage-year-filter">
      <label>Rok
        <select name="year" onchange="this.form.submit()">
          <?php foreach ($years as $year): ?><option value="<?= (int)$year ?>" <?= (int)$year === $selectedYear ? 'selected' : '' ?>><?= (int)$year ?></option><?php endforeach; ?>
        </select>
      </label>
    </form>
  </section>

  <?php if (!$vehicles): ?>
    <section class="card"><h2>Žádná vozidla</h2><p>Pro Garage dashboard zatím nemáte dostupné žádné vozidlo.</p></section>
  <?php else: ?>
    <section class="kpis garage-kpis">
      <div class="card kpi"><small>CELKOVÝ NÁJEZD <?= $selectedYear ?></small><strong><?= cz($totals['distance_km'], 0) ?> <em>km</em></strong><span><?= (int)$totals['trip_count'] ?> jízd napříč garáží</span></div>
      <div class="card kpi"><small>PROVOZNÍ NÁKLADY</small><strong><?= cz($totals['operating_cost'], 0) ?> <em>Kč</em></strong><span>energie + servis + ostatní výdaje</span></div>
      <div class="card kpi"><small>ODHAD ODPISU</small><strong><?= cz($totals['depreciation'], 0) ?> <em>Kč</em></strong><span>pouze auta s vyplněnou pořizovací a aktuální hodnotou</span></div>
      <div class="card kpi"><small>TCO <?= $selectedYear ?></small><strong><?= cz($totals['full_tco'], 0) ?> <em>Kč</em></strong><span>provozní náklady + odhad odpisu</span></div>
    </section>

    <section class="card table-card garage-comparison">
      <div class="table-card-head"><div><h2>🏁 Porovnání vozidel</h2><p>Stejné období a stejná metodika pro všechna dostupná vozidla.</p></div></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>VOZIDLO</th><th>POHON</th><th>NÁJEZD</th><th>JÍZDY</th><th>SPOTŘEBA</th><th>ENERGIE/PALIVO</th><th>SERVIS</th><th>OSTATNÍ</th><th>PROVOZ Kč/km</th><th>TCO Kč/km</th></tr></thead>
          <tbody>
          <?php foreach ($comparison as $row): ?>
            <tr>
              <td><a href="index.php?vehicle_id=<?= (int)$row['id'] ?>"><b><?= h($row['name']) ?></b></a><small class="table-subline"><?= h($row['vin']) ?></small></td>
              <td><span class="pill"><?= h($row['powertrain_type']) ?></span></td>
              <td><b><?= cz($row['distance_km'], 0) ?> km</b></td>
              <td><?= (int)$row['trip_count'] ?></td>
              <td><?= $row['consumed_kwh'] > 0 ? cz($row['avg_consumption'], 1) . ' kWh/100 km' : '—' ?></td>
              <td><?= cz($row['energy_cost'], 0) ?> Kč</td>
              <td><?= cz($row['service_cost'], 0) ?> Kč</td>
              <td><?= cz($row['other_cost'], 0) ?> Kč</td>
              <td><mark><?= cz($row['operating_cost_per_km'], 2) ?> Kč</mark></td>
              <td><?php if ($row['tco_complete']): ?><mark><?= cz($row['tco_per_km'], 2) ?> Kč</mark><?php else: ?><span title="Doplňte pořizovací cenu, datum pořízení a aktuální hodnotu">neúplné</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="garage-note">TCO používá lineární odhad odpisu mezi pořizovací cenou a aktuální hodnotou. Jde o analytický odhad, nikoli účetní odpis.</p>
    </section>

    <section class="grid2 garage-grid">
      <div class="card chart-card"><h2>📆 Meziroční nájezd</h2><p>Vývoj kilometrů napříč celou garáží.</p><canvas id="yearMileage"></canvas></div>
      <div class="card chart-card"><h2>⚡ Meziroční spotřeba</h2><p>Vážený průměr kWh/100 km podle skutečného nájezdu.</p><canvas id="yearConsumption"></canvas></div>
      <div class="card chart-card"><h2>💸 Vývoj ceny za kilometr</h2><p>Měsíční provozní náklady / měsíční nájezd v roce <?= $selectedYear ?>.</p><canvas id="costPerKm"></canvas></div>
      <div class="card chart-card"><h2>🌦 Sezónní spotřeba</h2><p>Porovnání zimy, jara, léta a podzimu v roce <?= $selectedYear ?>.</p><canvas id="seasonal"></canvas></div>
    </section>

    <section class="card garage-tco-help">
      <h2>🧮 Co je zahrnuto v TCO</h2>
      <div class="garage-help-grid">
        <div><b>Provoz</b><span>Nabíjení/tankování, servis a ostatní evidované výdaje.</span></div>
        <div><b>Odpis</b><span>Rozdíl pořizovací a aktuální hodnoty rozpočítaný do doby vlastnictví.</span></div>
        <div><b>Fallback CSV</b><span>Pokud není ruční evidence energie, použije se cena elektřiny importovaná z jízd.</span></div>
        <div><b>Plné TCO</b><span>Je dostupné až po vyplnění pořizovací ceny, data pořízení a aktuální hodnoty u vozidla.</span></div>
      </div>
      <?php if ($app->auth()->canManageVehicles($user)): ?><a class="btn" href="vehicles.php">Doplnit ekonomické údaje vozidel</a><?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<?php if ($vehicles): ?>
<script>
const chartText = '#8fb0d7';
const chartGrid = '#22314a';
Chart.defaults.color = chartText;
Chart.defaults.borderColor = chartGrid;

const yoy = <?= json_encode($yearOverYear, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const monthly = <?= json_encode($monthlyCostPerKm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const seasonal = <?= json_encode($seasonalConsumption, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

new Chart(document.getElementById('yearMileage'), {
  type: 'bar',
  data: {labels: yoy.map(r => r.year), datasets: [{label: 'km', data: yoy.map(r => Number(r.distance_km).toFixed(0))}]},
  options: {responsive: true, maintainAspectRatio: false}
});
new Chart(document.getElementById('yearConsumption'), {
  type: 'line',
  data: {labels: yoy.map(r => r.year), datasets: [{label: 'kWh/100 km', data: yoy.map(r => Number(r.avg_consumption).toFixed(2)), tension: .25}]},
  options: {responsive: true, maintainAspectRatio: false}
});
new Chart(document.getElementById('costPerKm'), {
  type: 'line',
  data: {labels: monthly.map(r => r.month.substring(5) + '/' + r.month.substring(0,4)), datasets: [{label: 'Kč/km', data: monthly.map(r => Number(r.cost_per_km).toFixed(2)), tension: .25}]},
  options: {responsive: true, maintainAspectRatio: false}
});
new Chart(document.getElementById('seasonal'), {
  type: 'bar',
  data: {labels: seasonal.map(r => r.label), datasets: [{label: 'kWh/100 km', data: seasonal.map(r => Number(r.avg_consumption).toFixed(2))}]},
  options: {responsive: true, maintainAspectRatio: false}
});
</script>
<?php endif; ?>
</body>
</html>

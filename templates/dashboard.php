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
<?php $powertrain  = (string)($vehicle['powertrain_type'] ?? 'BEV');
    $electricDrive = in_array($powertrain, [
        'BEV',
        'PHEV'
    ], TRUE); ?>
<header class="topbar">
    <div class="brand">⚡</div>
    <form class="vehicle-switch" method="get"><select name="vehicle_id" onchange="this.form.submit()"
                                                      aria-label="Vybrat vozidlo"><?php foreach ($vehicles as $v): ?>
                <option
                    value="<?= $v['id'] ?>" <?= (int)$v['id'] === (int)$vehicle['id'] ? 'selected' : '' ?>><?= (int)($user['default_vehicle_id'] ?? 0) === (int)$v['id'] ? '★ ' : '' ?><?= h($v['name']) ?>
                    · <?= h($v['vin']) ?></option>
            <?php endforeach; ?></select></form>
    <div class="vehicle">
        <b><?= h($vehicle['name']) ?></b><span><?= h($powertrain) ?><?php if ($electricDrive && (float)$vehicle['battery_kwh'] > 0): ?> · <?= cz((float)$vehicle['battery_kwh'], 0) ?> kWh<?php elseif ((float)($vehicle['fuel_tank_l'] ?? 0) > 0): ?> · <?= cz((float)$vehicle['fuel_tank_l'], 0) ?> l<?php endif; ?></span><small>VIN: <?= h($vehicle['vin']) ?></small>
    </div>
    <div class="spacer"></div>
    <a class="toplink" href="garage.php">🚘 Garage</a>
    <a class="toplink" href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">🧾 Provoz</a><?php if ($app->auth()->canManageVehicles($user)): ?><a
        class="toplink" href="vehicles.php">🚙
        Vozidla</a><?php endif; ?><?php if ($app->auth()->isAdmin($user)): ?><a
        class="toplink" href="users.php">👥 Uživatelé</a><?php endif; ?><?php if ($app->auth()->isAdmin($user)): ?><a class="toplink"
                                                                                                                     href="update.php">🔄
        Aktualizace</a><?php endif; ?>
    <form action="upload.php" method="post" enctype="multipart/form-data" class="upload"><input type="hidden" name="csrf"
                                                                                                value="<?= h(csrfToken()) ?>"><input
            type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>"><label>⬆ Nahrát data<input type="file" name="csv"
                                                                                                     accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
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
        <a href="garage.php">🚘 <span>Garage</span></a>
        <a href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">🧾 <span>Provoz</span></a>
        <?php if ($app->auth()->canManageVehicles($user)): ?><a href="vehicles.php">🚙 <span>Vozidla</span></a><?php endif; ?>
        <?php if ($app->auth()->isAdmin($user)): ?><a href="users.php">👥 <span>Uživatelé</span></a><a href="update.php">🔄
            <span>Aktualizace</span></a><?php endif; ?>
        <form action="upload.php" method="post" enctype="multipart/form-data" class="mobile-menu-upload">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
            <label>⬆ <span>Nahrát data</span><input type="file" name="csv"
                                                    accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                                                    onchange="this.form.submit()"></label>
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
                    <button type="button" class="modal-close" onclick="document.getElementById('newVehicleModal').remove()" aria-label="Zavřít">×
                    </button>
                </div>
                <p>VIN <b><?= h($newVehicleModal['vin']) ?></b> byl nalezen v názvu importovaného souboru. Vozidlo už bylo založeno, přiřazeno vašemu
                    účtu a data byla
                    importována. Zkontrolujte předvyplněné hodnoty.</p>
                <form method="post" class="modal-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                          value="complete_new_vehicle"><input
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
                    <label>Domácí lokalita <span>volitelné</span><input name="home_label"
                                                                        value="<?= h((string)($newVehicleModal['home_label'] ?? '')) ?>"
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
        <?php if ($electricDrive): ?>
            <div class="card kpi"><small>PRŮM. SPOTŘEBA</small><strong class="green"><?= cz($avgCons, 2) ?> <em>kWh/100 km</em></strong><span>Z baterie: <?= cz($totalKwh, 1) ?> kWh<?php if ($totalRecuperatedKwh > 0): ?> · Rekuperováno: <?= cz($totalRecuperatedKwh, 1) ?> kWh<?php endif; ?></span>
            </div>
            <div class="card kpi"><small>ODHAD DOJEZDU</small><strong>~<?= cz($range, 0) ?> <em>km</em></strong><span>na 100 % baterie</span></div>
        <?php else: ?>
            <div class="card kpi"><small>ENERGIE / PALIVO</small><strong class="green"><?= cz((float)$operationSummary['energy_cost'], 0) ?>
                    <em>Kč</em></strong><span>evidované tankování</span></div>
            <div class="card kpi"><small>PROVOZNÍ NÁKLADY</small><strong><?= cz((float)$operationSummary['cost_per_km'], 2) ?> <em>Kč/km</em></strong><span>servis + palivo + ostatní</span>
            </div>
        <?php endif; ?>
        <div class="card kpi"><small>DOBA JÍZDY</small><strong><?= cz($driveMin / 60, 1) ?> <em>hod</em></strong><span>Průměr: <?= cz($avgSpeed, 1) ?> km/h</span>
        </div>
        <div class="card kpi"><small>POMALÉ / DC</small><?php if ($chargeDataAvailable): ?><strong><?= cz($homePct, 0) ?>%
                <em>/ <?= cz($publicPct, 0) ?>
                    %</em></strong><span>Veřejné DC: <?= cz($publicStops, 0) ?>× · <?= cz($publicKwh, 1) ?> kWh*</span><?php else: ?>
                <strong>—</strong><span>CSV neobsahuje SoC ani nabíjení</span><?php endif; ?>
        </div>
        <div class="card kpi"><small>JÍZDY CELKEM</small><strong><?= $tripCount ?></strong><span>Z toho krátkých: <?= $shortTrips ?></span></div>
        <?php if ($electricDrive): ?>
            <div class="card kpi soh-kpi"><small>🔋 STATE OF HEALTH</small><?php if ($soh !== NULL): ?><strong
                    class="<?= $sohClass ?>"><?= cz($soh, 1) ?> <em>%</em></strong>
                    <span><?= $sohSource ?><?= count($sohSamples) ? ' · ' . count($sohSamples) . ' vzorků' : '' ?></span><?php else: ?>
                    <strong>—</strong><span>Nedostatek dat pro spolehlivý odhad</span><?php endif; ?></div>
        <?php else: ?>
            <div class="card kpi"><small>CELKOVÉ NÁKLADY</small><strong><?= cz((float)$operationSummary['total_cost'], 0) ?><em>Kč</em></strong><span><a
                        href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">otevřít provozní evidenci</a></span></div>
        <?php endif; ?>
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
                        <div><b class="green">🏠 Domácí / ostatní AC</b><strong><?= cz($homeKwh, 0) ?> kWh</strong><small><?= cz($homePct, 0) ?>
                                %</small></div>
                        <div><b class="orange">⚡ Veřejné DC</b><strong><?= cz($publicKwh, 0) ?> kWh</strong><small><?= cz($publicPct, 0) ?>%</small>
                        </div>
                    </div>
                </div>
                <footer>Celkem v bilanci: <b><?= cz($chargeTotal, 0) ?> kWh</b><span>* veřejná energie je odhad z přírůstku SoC</span></footer>
            <?php else: ?>
                <p>Tento typ CSV neobsahuje SoC ani události nabíjení.</p>
                <div class="energy-list">
                    <div><b class="green">🔋 Energie spotřebovaná jízdami</b><strong><?= cz($totalKwh, 1) ?> kWh</strong><small>vypočteno z průměrné
                            spotřeby a
                            vzdálenosti</small></div>
                    <?php if ($costDataAvailable): ?>
                        <div><b class="orange">💰 Náklady na elektřinu</b><strong><?= cz($costTotal, 0) ?> CZK</strong><small>součet hodnot z
                                CSV</small>
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
                Export neobsahuje adresy začátku a konce jízdy.</p><?php endif; ?>
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
            <?php else: ?><p><b>Trasy nejsou dostupné.</b> Export neobsahuje GPS
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
            <a class="btn export-btn"
               href="export.php?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>">⬇
                Export CSV</a>
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
                        <td><?php if ($t['start_soc'] !== NULL || $t['end_soc'] !== NULL): ?><?= cz($t['start_soc'], 0) ?>% →
                                <b><?= cz($t['end_soc'], 0) ?>
                                %</b><?php else: ?>—<?php endif; ?></td>
                        <td><?= (float)$t['public_charge_soc_gained'] > 0 ? '<mark>+' . cz($t['public_charge_soc_gained'], 0) . '%</mark>' : '•' ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
        <?php if ($historyPages > 1): ?>
            <nav class="pagination" aria-label="Stránkování historie jízd">
                <?php if ($historyPage > 1): ?><a
                    href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>&amp;page=<?= $historyPage - 1 ?>">
                        ←
                        Předchozí</a><?php endif; ?>
                <span>Stránka <b><?= $historyPage ?></b> / <?= $historyPages ?> · <?= $tripCount ?> jízd</span>
                <?php if ($historyPage < $historyPages): ?><a
                    href="?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>&amp;page=<?= $historyPage + 1 ?>">
                        Další →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<footer class="site-footer">created by: &copy; 2026 Pavel Filípek (<a href="https://www.filipek-czech.cz" target="_blank" rel="noopener noreferrer">www.filipek-czech.cz</a>)
    · verze <?= h($app->version()->label()) ?>
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
            events : [],
            cutout : '72%',
            plugins: {
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

<?php
$pageTitle = 'Dashboard';
$showNavigation = true;
$pageHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>';
require __DIR__ . '/partials/header.php';
?>
<?php
    $powertrain = strtoupper((string)($vehicle['powertrain_type'] ?? 'BEV'));
    $hasTractionBattery = in_array($powertrain, ['BEV', 'PHEV'], TRUE);
    $hasFuelSystem = in_array($powertrain, ['PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'], TRUE);
    $combustionOnly = !$hasTractionBattery;
?>
<main class="wrap dashboard-wrap">
    <section class="vehicle-hero">
        <div class="vehicle-hero-copy">
            <span class="eyebrow">DIGITAL GARAGE / <?= h(powertrainLabel($powertrain)) ?></span>
            <h1><?= h($vehicle['name']) ?></h1>
            <p><?= h((string)($vehicle['manufacturer'] ?? '')) ?> · <?= h((string)($vehicle['registration_plate'] ?? $vehicle['vin'])) ?></p>
            <div class="hero-chips">
                <span><?= h(powertrainLabel($powertrain)) ?></span>
                <?php if ($hasTractionBattery && (float)($vehicle['battery_kwh'] ?? 0) > 0): ?><span>🔋 <?= cz((float)$vehicle['battery_kwh'], 0) ?> kWh</span><?php endif; ?>
                <?php if ($hasFuelSystem && (float)($vehicle['fuel_tank_l'] ?? 0) > 0): ?><span>⛽ <?= cz((float)$vehicle['fuel_tank_l'], 0) ?> l</span><?php endif; ?>
            </div>
        </div>
        <div class="vehicle-hero-visual">
            <?php if (!empty($vehiclePhoto)): ?><img src="media.php?view=<?= (int)$vehiclePhoto['id'] ?>" alt="<?= h($vehicle['name']) ?>"><?php else: ?><div class="vehicle-silhouette">🚙</div><?php endif; ?>
        </div>
        <div class="hero-actions">
            <a class="hero-action" href="documents.php?vehicle_id=<?= (int)$vehicle['id'] ?>"><b>✦ Import Hub</b><small>Faktury, účtenky, CSV & AI</small></a>
            <a class="hero-action" href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>"><b>＋ Přidat záznam</b><small>Nabíjení, tankování, servis</small></a>
        </div>
    </section>
    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php require __DIR__ . '/partials/add-vehicle-modal.php'; ?>
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
    <?php if (!empty($privacyRestricted)): ?>
        <section class="card privacy-summary">
            <div>
                <small>🔒 SOUKROMÍ HISTORIE</small>
                <h2>Detailní data vidíte až od svého přiřazení vozidla</h2>
                <p>Starší trasy, adresy, dokumenty a provozní záznamy jsou skryté. Z minulosti se sdílí pouze anonymní technický souhrn vozidla.</p>
            </div>
            <div class="privacy-summary-stats">
                <span><b><?= cz((float)$lifetimeStats['odometer_km'], 0) ?> km</b><small>poslední známý tachometr</small></span>
                <?php if ($hasTractionBattery): ?><span><b><?= cz((float)$lifetimeStats['avg_consumption_kwh_100'], 1) ?> kWh/100 km</b><small>dlouhodobá spotřeba</small></span><?php elseif ($hasFuelSystem): ?><span><b><?= cz((float)$lifetimeStats['avg_fuel_consumption_l_100'], 1) ?> <?= h(fuelUnit($powertrain)) ?>/100 km</b><small>dlouhodobá spotřeba</small></span><?php endif; ?>
            </div>
        </section>
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
                    <?php if (!$years): ?>
                        <option value="<?= h($selectedYear) ?>"><?= h($selectedYear) ?></option>
                    <?php endif; ?>
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
        <?php if ($hasTractionBattery): ?>
            <div class="card kpi"><small>PRŮM. SPOTŘEBA</small><strong class="green"><?= cz($avgCons, 2) ?> <em>kWh/100 km</em></strong><span>Z baterie: <?= cz($totalKwh, 1) ?> kWh<?php if ($totalRecuperatedKwh > 0): ?> · Rekuperováno: <?= cz($totalRecuperatedKwh, 1) ?> kWh<?php endif; ?></span></div>
            <div class="card kpi"><small>ODHAD DOJEZDU</small><strong>~<?= cz($range, 0) ?> <em>km</em></strong><span>na 100 % baterie</span></div>
        <?php else: ?>
            <div class="card kpi"><small>PRŮM. SPOTŘEBA</small><strong class="green"><?= $avgFuelCons > 0 ? cz($avgFuelCons, 2) : '—' ?> <em><?= h(fuelUnit($powertrain)) ?>/100 km</em></strong><span><?= $totalFuel > 0 ? 'Spotřebováno: ' . cz($totalFuel, 1) . ' ' . h(fuelUnit($powertrain)) : 'Spotřebu lze zadat u jednotlivých jízd' ?></span></div>
            <div class="card kpi"><small>PROVOZNÍ NÁKLADY</small><strong><?= cz((float)$operationSummary['cost_per_km'], 2) ?> <em>Kč/km</em></strong><span>palivo + servis + ostatní</span></div>
        <?php endif; ?>
        <div class="card kpi"><small>DOBA JÍZDY</small><strong><?= cz($driveMin / 60, 1) ?> <em>hod</em></strong><span>Průměr: <?= cz($avgSpeed, 1) ?> km/h</span></div>
        <?php if ($hasTractionBattery): ?>
            <div class="card kpi"><small>POMALÉ / DC</small><?php if ($chargeDataAvailable): ?><strong><?= cz($homePct, 0) ?>%
                    <em>/ <?= cz($publicPct, 0) ?>%</em></strong><span>Veřejné DC: <?= cz($publicStops, 0) ?>× · <?= cz($publicKwh, 1) ?> kWh*</span><?php else: ?>
                    <strong>—</strong><span>Import neobsahuje SoC ani nabíjení</span><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card kpi"><small>SERVIS</small><strong><?= cz((float)$operationSummary['service_cost'], 0) ?> <em>Kč</em></strong><span>evidované servisní náklady</span></div>
        <?php endif; ?>
        <div class="card kpi"><small>JÍZDY CELKEM</small><strong><?= $tripCount ?></strong><span>Z toho krátkých: <?= $shortTrips ?></span></div>
        <?php if ($hasTractionBattery): ?>
            <div class="card kpi soh-kpi"><small>🔋 STATE OF HEALTH</small><?php if ($soh !== NULL): ?><strong class="<?= $sohClass ?>"><?= cz($soh, 1) ?> <em>%</em></strong>
                    <span><?= $sohSource ?><?= count($sohSamples) ? ' · ' . count($sohSamples) . ' vzorků' : '' ?></span><?php else: ?>
                    <strong>—</strong><span>Nedostatek dat pro spolehlivý odhad</span><?php endif; ?></div>
        <?php else: ?>
            <div class="card kpi"><small>CELKOVÉ NÁKLADY</small><strong><?= cz((float)$operationSummary['total_cost'], 0) ?> <em>Kč</em></strong><span><a href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">otevřít provozní evidenci</a></span></div>
        <?php endif; ?>
    </section>
    <section class="insight-strip">
        <div><span class="insight-icon">✦</span><p><small>EFFICIENCY INSIGHT</small><b><?php if ($hasTractionBattery): ?><?= cz($avgCons, 1) ?> kWh/100 km<?php else: ?>Provozní přehled<?php endif; ?></b><em><?= $tripCount ?> jízd v aktuálním období</em></p></div>
        <div><span class="insight-icon">◉</span><p><small>COST INSIGHT</small><b><?= cz((float)$operationSummary['cost_per_km'], 2) ?> Kč/km</b><em><?= cz((float)$operationSummary['total_cost'], 0) ?> Kč evidovaných nákladů</em></p></div>
        <div><span class="insight-icon">↗</span><p><small>USAGE INSIGHT</small><b><?= cz($totalKm, 0) ?> km</b><em><?= cz($driveMin / 60, 1) ?> h za volantem</em></p></div>
    </section>

    <section class="grid2">
        <div class="card chart-card"><h2>📈 <?= $hasTractionBattery ? 'Měsíční nájezd a průměrná spotřeba' : 'Měsíční nájezd' ?></h2>
            <p><?= $hasTractionBattery ? 'Kilometry (sloupce) vs. spotřeba v kWh/100 km (křivka)' : 'Ujeté kilometry v jednotlivých měsících' ?></p>
            <canvas id="monthly"></canvas>
        </div>
        <div class="card chart-card"><h2>⏰ Denní rytmus (v kolik hodin vyjíždíte)</h2>
            <p>Četnost výjezdů podle hodin dne</p>
            <canvas id="hours"></canvas>
        </div>
        <?php if ($hasTractionBattery): ?>
            <div class="card energy"><h2>⚡ Energetická bilance a nabíjecí lokality</h2><?php if ($chargeDataAvailable): ?><p>Odhad energie podle veřejného nabíjení zaznamenaného v importu</p>
                    <div class="energy-layout">
                        <div class="energy-chart-wrap"><canvas id="energy" width="120" height="120"></canvas></div>
                        <div class="energy-list">
                            <div><b class="green">🏠 Domácí / ostatní AC</b><strong><?= cz($homeKwh, 0) ?> kWh</strong><small><?= cz($homePct, 0) ?> %</small></div>
                            <div><b class="orange">⚡ Veřejné DC</b><strong><?= cz($publicKwh, 0) ?> kWh</strong><small><?= cz($publicPct, 0) ?>%</small></div>
                        </div>
                    </div>
                    <footer>Celkem v bilanci: <b><?= cz($chargeTotal, 0) ?> kWh</b><span>* veřejná energie je odhad z přírůstku SoC</span></footer>
                <?php else: ?>
                    <p>Tento zdroj dat neobsahuje SoC ani události nabíjení.</p>
                    <div class="energy-list">
                        <div><b class="green">🔋 Energie spotřebovaná jízdami</b><strong><?= cz($totalKwh, 1) ?> kWh</strong><small>podle importovaných jízd</small></div>
                        <?php if ($costDataAvailable): ?><div><b class="orange">💰 Náklady na elektřinu</b><strong><?= cz($costTotal, 0) ?> CZK</strong><small>součet hodnot z importu</small></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card energy"><h2>⛽ Provozní náklady</h2>
                <p>Souhrn ručně evidovaného provozu vozidla</p>
                <div class="energy-list">
                    <div><b class="green">⛽ Palivo</b><strong><?= cz((float)$operationSummary['energy_cost'], 0) ?> Kč</strong><small>tankování</small></div>
                    <div><b>🔧 Servis</b><strong><?= cz((float)$operationSummary['service_cost'], 0) ?> Kč</strong><small>servisní záznamy</small></div>
                    <div><b class="orange">💸 Ostatní</b><strong><?= cz((float)$operationSummary['other_cost'], 0) ?> Kč</strong><small>pojištění, parkování a další</small></div>
                </div>
                <footer>Celkem: <b><?= cz((float)$operationSummary['total_cost'], 0) ?> Kč</b><span><a href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">doplnit provozní evidenci</a></span></footer>
            </div>
        <?php endif; ?>
        <div class="card chart-card"><h2>🏎 <?= $hasTractionBattery ? 'Spotřeba podle rychlostních pásem' : 'Nájezd podle rychlostních pásem' ?></h2>
            <p><?= $hasTractionBattery ? 'Jízdy jsou seskupené podle průměrné rychlosti' : 'Ujeté kilometry podle průměrné rychlosti jízd' ?></p>
            <canvas id="speed"></canvas>
        </div>
        <div class="card"><h2>🔄 Pravidelné dojíždění</h2>
            <p>Nejčastější směry v importovaných datech</p><?php if ($routes): ?>
                <div class="route-cards"><?php $i = 0; foreach ($routes as $name => $r) { if ($i++ >= 2) { break; } $c = $r['km'] ? $r['kwh'] / $r['km'] * 100 : 0; ?>
                        <div><b><?= h($name) ?></b><span><?= $r['count'] ?>×</span><?php if ($hasTractionBattery): ?><strong><?= cz($c, 1) ?> <em>kWh/100 km</em></strong><?php endif; ?><small>Průměrná délka: <?= cz($r['km'] / $r['count'], 1) ?> km</small></div>
                    <?php } ?></div>
            <?php else: ?><p><b>Trasy nejsou v tomto importu k dispozici.</b> Zdroj neobsahuje adresy začátku a konce jízdy.</p><?php endif; ?>
            <?php if ($hasTractionBattery): ?>
                <div class="battery">🛡️ <b>Šetrné nabíjení baterie</b><span><?php if ($minSoc !== NULL): ?>Nejnižší zaznamenané SoC: <?= cz($minSoc, 0) ?> %.<?php else: ?>SoC není v tomto zdroji k dispozici.<?php endif; ?></span><mark>Stav: informativní</mark></div>
            <?php else: ?>
                <div class="battery">🧾 <b>Provozní evidence</b><span>Tankování, servis, náklady a připomínky jsou vedené odděleně od importovaných jízd.</span><mark><a href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">Otevřít</a></mark></div>
            <?php endif; ?>
        </div>
        <div class="card"><h2>📍 Nejčastější pravidelné trasy</h2>
            <p>Statistika tras s nejvyšším počtem opakování</p><?php if ($routes): ?>
                <div class="route-list"><?php $i = 0; foreach ($routes as $name => $r) { if ($i++ >= 3) { break; } $c = $r['km'] ? $r['kwh'] / $r['km'] * 100 : 0; ?>
                        <div><span><b><?= h($name) ?></b><small><?= cz($r['km'] / $r['count'], 1) ?> km průměr</small></span><?php if ($hasTractionBattery): ?><mark><?= cz($c, 1) ?> kWh/100 km</mark><?php endif; ?><small><?= $r['count'] ?>× jízda</small></div>
                    <?php } ?></div>
            <?php else: ?><p><b>Trasy nejsou dostupné.</b> Import neobsahuje GPS ani adresy.</p><?php endif; ?></div>
    </section>
    <section class="card table-card"><h2>🗺 Cesty &gt; 80 km <span class="pill"><?= $longTripCount ?> tras</span></h2>
        <p><?= $hasTractionBattery ? 'Přehled delších tras včetně nabíjení na cestách' : 'Přehled delších tras vozidla' ?></p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>DATUM</th>
                    <th>ODKUD → KAM</th>
                    <th>VZDÁLENOST</th>
                    <th>ČAS JÍZDY</th>
                    <th>PRŮM. RYCHLOST</th>
                    <?php if ($hasTractionBattery || $hasFuelSystem): ?>
                        <th>PRŮM. SPOTŘEBA</th>
                        <th>SPOTŘEBOVÁNO</th>
                        <th>NABÍJENÍ</th>
                    <?php endif; ?>

                </tr>
                </thead>
                <tbody><?php foreach ($longTrips as $t): ?>
                    <tr>
                        <td><?= date('d.m.Y H:i', strtotime($t['started_at'])) ?></td>
                        <td><b><?= h(displayRoute($t['start_address'], $t['end_address'])) ?></b></td>
                        <td><b><?= cz($t['distance_km'], 1) ?> km</b></td>
                        <td><?= intdiv((int)$t['driving_minutes'], 60) ?> h <?= ((int)$t['driving_minutes']) % 60 ?> m</td>
                        <td><?= cz($t['avg_speed_kmh'], 0) ?> km/h</td>
                        <?php if ($hasTractionBattery): ?>
                            <td><mark><?= cz($t['avg_consumption_kwh_100'], 1) ?> kWh/100km</mark></td>
                            <td><b><?= cz($t['consumed_kwh'], 1) ?> kWh</b></td>
                            <td><?= (float)$t['public_charge_soc_gained'] > 0 ? '<mark>+' . cz($t['public_charge_soc_gained'], 0) . '%</mark>' : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
    </section>
    <section class="card table-card history-card">
        <div class="table-card-head">
            <div><h2>📋 Seznam a historie jízd</h2>
                <p>Jízdy v aktuálním filtru · stránka <?= $historyPage ?> z <?= $historyPages ?></p></div>
            <div class="history-actions">
                <button class="btn btn-primary" type="button" data-trip-create>＋ Přidat jízdu</button>
                <a class="btn export-btn" href="export.php?vehicle_id=<?= $vehicle['id'] ?>&amp;period=<?= h($period) ?>&amp;year=<?= h($selectedYear) ?>">⬇ Export CSV</a>
            </div>
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
                    <?php if ($hasTractionBattery || $hasFuelSystem): ?>
                        <th>PRŮM. SPOTŘEBA</th>
                        <th>SPOTŘEBOVÁNO</th>
                        <?php if ($hasTractionBattery): ?><th>SOC</th><th>NABÍJENÍ</th><?php endif; ?>
                    <?php endif; ?>
                    <th class="trip-actions-col">AKCE</th>
                </tr>
                </thead>
                <tbody><?php foreach ($historyTrips as $t): ?>
                    <tr>
                        <td><?= date('d.m.Y H:i', strtotime($t['started_at'])) ?></td>
                        <td><b><?= h(displayRoute($t['start_address'], $t['end_address'])) ?></b></td>
                        <td><b><?= cz($t['distance_km'], 1) ?></b></td>
                        <td><?= cz($t['driving_minutes'], 0) ?>m</td>
                        <td><?= cz($t['avg_speed_kmh'], 0) ?> km/h</td>
                        <?php if ($hasTractionBattery): ?>
                            <td><b><?= cz($t['avg_consumption_kwh_100'], 1) ?></b> kWh/100 km</td>
                            <td><b><?= cz($t['consumed_kwh'], 1) ?></b> kWh</td>
                            <td><?php if ($t['start_soc'] !== NULL || $t['end_soc'] !== NULL): ?><?= cz($t['start_soc'], 0) ?>% → <b><?= cz($t['end_soc'], 0) ?>%</b><?php else: ?>—<?php endif; ?></td>
                            <td><?= (float)$t['public_charge_soc_gained'] > 0 ? '<mark>+' . cz($t['public_charge_soc_gained'], 0) . '%</mark>' : '•' ?></td>
                        <?php elseif ($hasFuelSystem): ?>
                            <td><b><?= $t['avg_fuel_consumption_l_100'] !== null ? cz($t['avg_fuel_consumption_l_100'], 1) : '—' ?></b> <?= h(fuelUnit($powertrain)) ?>/100 km</td>
                            <td><b><?= $t['fuel_consumed_l'] !== null ? cz($t['fuel_consumed_l'], 1) : '—' ?></b> <?= h(fuelUnit($powertrain)) ?></td>
                        <?php endif; ?>
                        <td class="trip-actions-col"><button type="button" class="icon-action" data-trip-edit="<?= (int)$t['id'] ?>" title="Upravit jízdu" aria-label="Upravit jízdu">✎</button></td>
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


<?php
$tripEditorData = [];
foreach ($historyTrips as $tripRow) {
    $tripEditorData[(string)$tripRow['id']] = [
        'id' => (int)$tripRow['id'],
        'started_at' => date('Y-m-d\\TH:i', strtotime((string)$tripRow['started_at'])),
        'ended_at' => date('Y-m-d\\TH:i', strtotime((string)$tripRow['ended_at'])),
        'start_address' => (string)$tripRow['start_address'],
        'end_address' => (string)$tripRow['end_address'],
        'distance_km' => $tripRow['distance_km'],
        'start_odometer_km' => $tripRow['start_odometer_km'],
        'end_odometer_km' => $tripRow['end_odometer_km'],
        'driving_minutes' => $tripRow['driving_minutes'],
        'travel_minutes' => $tripRow['travel_minutes'],
        'avg_speed_kmh' => $tripRow['avg_speed_kmh'],
        'consumed_kwh' => $tripRow['consumed_kwh'],
        'avg_consumption_kwh_100' => $tripRow['avg_consumption_kwh_100'],
        'fuel_consumed_l' => $tripRow['fuel_consumed_l'] ?? null,
        'avg_fuel_consumption_l_100' => $tripRow['avg_fuel_consumption_l_100'] ?? null,
        'start_soc' => $tripRow['start_soc'],
        'end_soc' => $tripRow['end_soc'],
        'public_charging_stops' => $tripRow['public_charging_stops'],
        'public_charge_soc_gained' => $tripRow['public_charge_soc_gained'],
        'classification' => (string)($tripRow['classification'] ?? ''),
        'trip_note' => (string)($tripRow['trip_note'] ?? ''),
        'source_format' => (string)($tripRow['source_format'] ?? ''),
    ];
}
?>
<div class="app-modal" id="tripEditorModal" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-trip-close></div>
    <section class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tripEditorTitle">
        <header class="app-modal-head">
            <div><span class="eyebrow">HISTORIE JÍZD</span><h2 id="tripEditorTitle">Přidat jízdu</h2><p id="tripEditorSubtitle">Zapište jízdu ručně bez CSV importu.</p></div>
            <button class="modal-close" type="button" data-trip-close aria-label="Zavřít">×</button>
        </header>
        <form method="post" class="app-modal-body" id="tripEditorForm">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_trip">
            <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
            <input type="hidden" name="trip_id" id="trip_id" value="">
            <div class="trip-form-grid">
                <label><span>Začátek</span><input required type="datetime-local" name="started_at" id="trip_started_at"></label>
                <label><span>Konec</span><input required type="datetime-local" name="ended_at" id="trip_ended_at"></label>
                <label class="span-2"><span>Odkud</span><input type="text" name="start_address" id="trip_start_address" maxlength="255" placeholder="Výchozí adresa"></label>
                <label class="span-2"><span>Kam</span><input type="text" name="end_address" id="trip_end_address" maxlength="255" placeholder="Cílová adresa"></label>
                <label><span>Vzdálenost (km)</span><input type="number" step="0.01" min="0" name="distance_km" id="trip_distance_km"></label>
                <label><span>Doba jízdy (min)</span><input type="number" min="0" name="driving_minutes" id="trip_driving_minutes"></label>
                <label><span>Tachometr start</span><input type="number" step="0.01" min="0" name="start_odometer_km" id="trip_start_odometer_km"></label>
                <label><span>Tachometr konec</span><input type="number" step="0.01" min="0" name="end_odometer_km" id="trip_end_odometer_km"></label>
                <label><span>Průměrná rychlost</span><input type="number" step="0.01" min="0" name="avg_speed_kmh" id="trip_avg_speed_kmh"></label>
                <label><span>Typ jízdy</span><select name="classification" id="trip_classification"><option value="">Bez klasifikace</option><option value="private">Soukromá</option><option value="business">Služební</option><option value="commute">Dojíždění</option><option value="other">Ostatní</option></select></label>
                <?php if ($hasTractionBattery): ?>
                <label><span>Spotřebováno (kWh)</span><input type="number" step="0.001" min="0" name="consumed_kwh" id="trip_consumed_kwh"></label>
                <label><span>Spotřeba (kWh/100 km)</span><input type="number" step="0.01" min="0" name="avg_consumption_kwh_100" id="trip_avg_consumption_kwh_100"></label>
                <label><span>SoC start (%)</span><input type="number" step="0.1" min="0" max="100" name="start_soc" id="trip_start_soc"></label>
                <label><span>SoC konec (%)</span><input type="number" step="0.1" min="0" max="100" name="end_soc" id="trip_end_soc"></label>
                <label><span>Nabíjecí zastávky</span><input type="number" min="0" name="public_charging_stops" id="trip_public_charging_stops"></label>
                <label><span>SoC dobito (%)</span><input type="number" step="0.1" min="0" name="public_charge_soc_gained" id="trip_public_charge_soc_gained"></label>
                <?php elseif ($hasFuelSystem): ?>
                <label><span>Spotřebováno (<?= h(fuelUnit($powertrain)) ?>)</span><input type="number" step="0.001" min="0" name="fuel_consumed_l" id="trip_fuel_consumed_l"></label>
                <label><span>Průměrná spotřeba (<?= h(fuelUnit($powertrain)) ?>/100 km)</span><input type="number" step="0.01" min="0" name="avg_fuel_consumption_l_100" id="trip_avg_fuel_consumption_l_100"></label>
                <?php endif; ?>
                <label class="span-2"><span>Poznámka</span><textarea name="trip_note" id="trip_trip_note" rows="3" maxlength="500" placeholder="Volitelná poznámka k jízdě"></textarea></label>
            </div>
            <div class="trip-editor-info" id="tripEditorInfo" hidden>Importovaná jízda. Uložením upravíte data této jízdy; původní CSV soubor se nemění.</div>
            <div class="modal-actions"><button type="button" class="btn" data-trip-close>Zrušit</button><button type="submit" class="btn btn-primary">Uložit jízdu</button></div>
        </form>
    </section>
</div>
<script type="application/json" id="tripEditorData"><?= json_encode($tripEditorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<section class="dashboard-revolution-grid">
  <div class="card insight-panel"><div class="section-head"><div><span class="eyebrow">SMART LAYER</span><h2>✦ Insights</h2></div></div><div class="insight-strip stacked"><?php foreach($insights as $i):?><article class="insight-card"><span><?=$i['icon']?></span><div><b><?=h($i['title'])?></b><p><?=h($i['text'])?></p></div></article><?php endforeach;?></div></div>
  <div class="card mini-timeline"><div class="section-head"><div><span class="eyebrow">POSLEDNÍ UDÁLOSTI</span><h2>◷ Timeline</h2></div><a href="timeline.php">Celá historie →</a></div><?php foreach($timelineEvents as $e):?><div class="mini-event"><span><?=['trip'=>'🚗','energy'=>'⚡','service'=>'🔧','expense'=>'💳'][$e['type']]?></span><div><b><?=h((string)($e['label']?:ucfirst($e['type'])))?></b><small><?=h(date('d.m.Y H:i',strtotime($e['event_at'])))?> · <?=h((string)($e['detail']??''))?></small></div></div><?php endforeach;?><?php if(!$timelineEvents):?><p>Zatím žádné provozní události.</p><?php endif;?></div>
</section>
</main>
<script>
    const monthlyLabels = <?=json_encode($monthLabels, JSON_UNESCAPED_UNICODE)?>, monthlyKm = <?=json_encode($monthKm)?>,
        monthlyCons = <?=json_encode($monthCons)?>;
    const hours = <?=json_encode(array_values($hourData))?>, speedLabels = <?=json_encode($bandLabels, JSON_UNESCAPED_UNICODE)?>,
        speedValues = <?=json_encode($hasTractionBattery ? $bandValues : $bandKm)?>;
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
            }<?php if ($hasTractionBattery): ?>, {
                type           : 'line',
                label          : 'Spotřeba (kWh/100km)',
                data           : monthlyCons,
                borderColor    : '#22d3ee',
                backgroundColor: '#22d3ee',
                tension        : .3,
                yAxisID        : 'y1'
            }<?php endif; ?>]
        },
        options: {
            responsive: true,
            scales    : {
                x : { grid: { color: grid } },
                y : { grid: { color: grid }, beginAtZero: true },
                <?php if ($hasTractionBattery): ?>y1: { position: 'right', grid: { drawOnChartArea: false } }<?php endif; ?>
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
    <?php if ($hasTractionBattery && $chargeDataAvailable): ?>
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
            datasets: [{ label: <?= json_encode($hasTractionBattery ? 'kWh/100 km' : 'km', JSON_UNESCAPED_UNICODE) ?>, data: speedValues, backgroundColor: ['#22d3ee', '#10b981', '#f59e0b', '#f43f5e'], borderRadius: 7 }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales : { x: { grid: { color: grid } }, y: { grid: { color: grid }, beginAtZero: true } }
        }
    });
</script>

<script>
(() => {
    const modal = document.getElementById('tripEditorModal');
    const form = document.getElementById('tripEditorForm');
    if (!modal || !form) return;
    const data = JSON.parse(document.getElementById('tripEditorData')?.textContent || '{}');
    const fields = ['started_at','ended_at','start_address','end_address','distance_km','start_odometer_km','end_odometer_km','driving_minutes','avg_speed_kmh','classification','consumed_kwh','avg_consumption_kwh_100','fuel_consumed_l','avg_fuel_consumption_l_100','start_soc','end_soc','public_charging_stops','public_charge_soc_gained','trip_note'];
    const set = (name, value) => { const el = document.getElementById('trip_' + name); if (el) el.value = value ?? ''; };
    const nowLocal = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0,16); };
    const open = (trip = null) => {
        form.reset();
        set('id', trip?.id || '');
        fields.forEach(name => set(name, trip ? trip[name] : ''));
        if (!trip) { const now = nowLocal(); set('started_at', now); set('ended_at', now); }
        document.getElementById('tripEditorTitle').textContent = trip ? 'Upravit jízdu' : 'Přidat jízdu';
        document.getElementById('tripEditorSubtitle').textContent = trip ? 'Upravujete záznam přímo v historii jízd.' : 'Zapište jízdu ručně bez CSV importu.';
        const info = document.getElementById('tripEditorInfo');
        if (info) info.hidden = !trip || trip.source_format === 'manual';
        modal.hidden = false; modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
        setTimeout(() => document.getElementById('trip_started_at')?.focus(), 30);
    };
    const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); };
    document.querySelector('[data-trip-create]')?.addEventListener('click', () => open());
    document.querySelectorAll('[data-trip-edit]').forEach(btn => btn.addEventListener('click', () => open(data[btn.dataset.tripEdit] || null)));
    modal.querySelectorAll('[data-trip-close]').forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

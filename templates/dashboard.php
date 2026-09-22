<?php
$pageTitle = 'Dashboard';
$showNavigation = true;
$pageHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>'
    . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css">'
    . '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>';
require __DIR__ . '/partials/header.php';
?>
<?php
    $powertrain = strtoupper((string)($vehicle['powertrain_type'] ?? 'BEV'));
    $hasTractionBattery = in_array($powertrain, ['BEV', 'PHEV'], TRUE);
    $hasFuelSystem = in_array($powertrain, ['PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'], TRUE);
    $combustionOnly = !$hasTractionBattery;
    $liveTelemetry = !empty($liveConnectedCar['telemetry']) && is_array($liveConnectedCar['telemetry'])
        ? $liveConnectedCar['telemetry']
        : NULL;
    $hasLiveTelemetry = $liveTelemetry !== NULL && (
        $liveTelemetry['soc_pct'] !== NULL
        || $liveTelemetry['range_km'] !== NULL
        || $liveTelemetry['odometer_km'] !== NULL
        || $liveTelemetry['is_charging'] !== NULL
        || $liveTelemetry['is_plugged_in'] !== NULL
        || $liveTelemetry['fuel_level_pct'] !== NULL
        || $liveTelemetry['battery_temperature_c'] !== NULL
        || ($liveTelemetry['air_conditioning_state'] ?? NULL) !== NULL
        || ($liveTelemetry['auxiliary_heating_state'] ?? NULL) !== NULL
        || ($liveTelemetry['active_ventilation_state'] ?? NULL) !== NULL
        || ($liveTelemetry['window_heating_front'] ?? NULL) !== NULL
        || ($liveTelemetry['window_heating_rear'] ?? NULL) !== NULL
    );
    $climateStateLabel = static function ($state): string {
        $value = strtoupper(trim((string)$state));
        $labels = [
            'HEATING' => 'Topení',
            'COOLING' => 'Chlazení',
            'VENTILATION' => 'Ventilace',
            'HEATING_AUXILIARY' => 'Nezávislé topení',
            'HEATING_VENTILATION' => 'Topení / ventilace',
            'ON' => 'Zapnuto',
            'OFF' => 'Vypnuto',
            'INACTIVE' => 'Neaktivní',
            'ACTIVE' => 'Aktivní',
        ];
        return $labels[$value] ?? ($value !== '' ? str_replace('_', ' ', $value) : '—');
    };
    $climateDuration = static function ($seconds): string {
        if (!is_numeric($seconds) || (int)$seconds <= 0) {
            return '';
        }
        $minutes = max(1, (int)round((int)$seconds / 60));
        return $minutes . ' min';
    };
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
                <?php if ($hasLiveTelemetry): ?>
                    <span class="hero-live-chip">● Connected</span>
                    <?php if ($liveTelemetry['soc_pct'] !== NULL): ?><span>🔋 <?= cz((float)$liveTelemetry['soc_pct'], 0) ?> %</span><?php endif; ?>
                    <?php if ($liveTelemetry['range_km'] !== NULL): ?><span>↗ <?= cz((float)$liveTelemetry['range_km'], 0) ?> km</span><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="vehicle-hero-visual">
            <?php if (!empty($vehiclePhoto)): ?><img src="media.php?view=<?= (int)$vehiclePhoto['id'] ?>" alt="<?= h($vehicle['name']) ?>"><?php else: ?><div class="vehicle-silhouette">🚙</div><?php endif; ?>
        </div>
        <div class="hero-actions">
            <a class="hero-action" href="import.php?vehicle_id=<?= (int)$vehicle['id'] ?>"><b>✦ Import Hub</b><small>Faktury, účtenky, CSV & AI</small></a>
            <a class="hero-action" href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>"><b>＋ Přidat záznam</b><small>Nabíjení, tankování, servis</small></a>
        </div>
    </section>
    <?php $vehicleWorkspaceTab = 'overview'; require __DIR__ . '/partials/vehicle-workspace.php'; ?>
    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php require __DIR__ . '/partials/add-vehicle-modal.php'; ?>
    <?php if ($hasLiveTelemetry): ?>
        <?php
            $liveCharging = $liveTelemetry['is_charging'] !== NULL ? (bool)$liveTelemetry['is_charging'] : NULL;
            $livePlugged = $liveTelemetry['is_plugged_in'] !== NULL ? (bool)$liveTelemetry['is_plugged_in'] : NULL;
            $liveChargingLabel = '—';
            $liveChargingDetail = 'stav není dostupný';
            if ($liveCharging === TRUE) {
                $liveChargingLabel = 'Nabíjí';
                $liveChargingDetail = $liveTelemetry['charging_power_kw'] !== NULL
                    ? cz((float)$liveTelemetry['charging_power_kw'], 1) . ' kW'
                    : 'aktivní nabíjení';
            } elseif ($livePlugged === TRUE) {
                $liveChargingLabel = 'Připojeno';
                $liveChargingDetail = 'kabel je připojen';
            } elseif ($livePlugged === FALSE) {
                $liveChargingLabel = 'Odpojeno';
                $liveChargingDetail = 'kabel není připojen';
            } elseif ($liveCharging === FALSE) {
                $liveChargingLabel = 'Nenabíjí';
                $liveChargingDetail = 'nabíjení není aktivní';
            }
            $liveUpdatedAt = !empty($liveConnectedCar['last_synced_at'])
                ? date('d.m.Y H:i', strtotime((string)$liveConnectedCar['last_synced_at']))
                : '—';
        ?>
        <section class="live-vehicle-state" aria-label="Aktuální stav vozidla">
            <div class="live-vehicle-state-head">
                <div class="live-vehicle-state-title">
                    <span class="live-vehicle-state-dot" aria-hidden="true"></span>
                    <div>
                        <small>CONNECTED CAR · AKTUÁLNÍ STAV</small>
                        <strong><?= h((string)$liveConnectedCar['label']) ?></strong>
                    </div>
                </div>
                <div class="live-vehicle-state-meta">
                    <span>Synchronizováno <?= h($liveUpdatedAt) ?></span>
                    <a href="vehicles.php?edit=<?= (int)$vehicle['id'] ?>">Spravovat připojení →</a>
                </div>
            </div>
            <div class="live-vehicle-metrics">
                <?php if ($liveTelemetry['soc_pct'] !== NULL): ?>
                    <div class="live-vehicle-metric"><small>STAV BATERIE</small><strong><?= cz((float)$liveTelemetry['soc_pct'], 0) ?> %</strong><span>aktuální SoC</span></div>
                <?php endif; ?>
                <?php if ($liveTelemetry['range_km'] !== NULL): ?>
                    <div class="live-vehicle-metric"><small>AKTUÁLNÍ DOJEZD</small><strong><?= cz((float)$liveTelemetry['range_km'], 0) ?> km</strong><span>hlášený vozidlem</span></div>
                <?php endif; ?>
                <?php if ($liveTelemetry['odometer_km'] !== NULL): ?>
                    <div class="live-vehicle-metric"><small>TACHOMETR</small><strong><?= cz((float)$liveTelemetry['odometer_km'], 0) ?> km</strong><span>online stav</span></div>
                <?php endif; ?>
                <?php if ($liveTelemetry['is_charging'] !== NULL || $liveTelemetry['is_plugged_in'] !== NULL): ?>
                    <div class="live-vehicle-metric"><small>NABÍJENÍ</small><strong><?= h($liveChargingLabel) ?></strong><span><?= h($liveChargingDetail) ?></span></div>
                <?php endif; ?>
                <?php if ($liveTelemetry['fuel_level_pct'] !== NULL): ?>
                    <div class="live-vehicle-metric"><small>PALIVO</small><strong><?= cz((float)$liveTelemetry['fuel_level_pct'], 0) ?> %</strong><span>aktuální hladina</span></div>
                <?php endif; ?>
                <?php if ($liveTelemetry['battery_temperature_c'] !== NULL): ?>
                    <div class="live-vehicle-metric"><small>TEPLOTA BATERIE</small><strong><?= cz((float)$liveTelemetry['battery_temperature_c'], 1) ?> °C</strong><span>hlášená vozidlem</span></div>
                <?php endif; ?>
                <?php if (($liveTelemetry['air_conditioning_state'] ?? NULL) !== NULL): ?>
                    <?php
                        $airDetails = [];
                        if (($liveTelemetry['air_conditioning_target_c'] ?? NULL) !== NULL) {
                            $airDetails[] = 'cíl ' . cz((float)$liveTelemetry['air_conditioning_target_c'], 1) . ' °C';
                        }
                        if (($liveTelemetry['air_conditioning_without_external_power'] ?? NULL) !== NULL) {
                            $airDetails[] = (bool)$liveTelemetry['air_conditioning_without_external_power'] ? 'z baterie vozu' : 's externím napájením';
                        }
                    ?>
                    <div class="live-vehicle-metric"><small>KLIMATIZACE</small><strong><?= h($climateStateLabel($liveTelemetry['air_conditioning_state'])) ?></strong><span><?= h($airDetails ? implode(' · ', $airDetails) : 'aktuální stav') ?></span></div>
                <?php endif; ?>
                <?php if (($liveTelemetry['auxiliary_heating_state'] ?? NULL) !== NULL): ?>
                    <?php
                        $auxDetails = [];
                        if (($liveTelemetry['auxiliary_heating_target_c'] ?? NULL) !== NULL) {
                            $auxDetails[] = 'cíl ' . cz((float)$liveTelemetry['auxiliary_heating_target_c'], 1) . ' °C';
                        }
                        $auxDuration = $climateDuration($liveTelemetry['auxiliary_heating_duration_seconds'] ?? NULL);
                        if ($auxDuration !== '') {
                            $auxDetails[] = $auxDuration;
                        }
                    ?>
                    <div class="live-vehicle-metric"><small>NEZÁVISLÉ TOPENÍ</small><strong><?= h($climateStateLabel($liveTelemetry['auxiliary_heating_state'])) ?></strong><span><?= h($auxDetails ? implode(' · ', $auxDetails) : $climateStateLabel($liveTelemetry['auxiliary_heating_start_mode'] ?? '')) ?></span></div>
                <?php endif; ?>
                <?php if (($liveTelemetry['active_ventilation_state'] ?? NULL) !== NULL): ?>
                    <?php $ventDuration = $climateDuration($liveTelemetry['active_ventilation_duration_seconds'] ?? NULL); ?>
                    <div class="live-vehicle-metric"><small>VENTILACE</small><strong><?= h($climateStateLabel($liveTelemetry['active_ventilation_state'])) ?></strong><span><?= h($ventDuration !== '' ? $ventDuration : 'aktuální stav') ?></span></div>
                <?php endif; ?>
                <?php if (($liveTelemetry['window_heating_front'] ?? NULL) !== NULL || ($liveTelemetry['window_heating_rear'] ?? NULL) !== NULL): ?>
                    <div class="live-vehicle-metric"><small>VYHŘÍVÁNÍ OKEN</small><strong><?= h($climateStateLabel(($liveTelemetry['window_heating_front'] ?? '') === 'ON' || ($liveTelemetry['window_heating_rear'] ?? '') === 'ON' ? 'ON' : 'OFF')) ?></strong><span>přední <?= h($climateStateLabel($liveTelemetry['window_heating_front'] ?? '')) ?> · zadní <?= h($climateStateLabel($liveTelemetry['window_heating_rear'] ?? '')) ?></span></div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($newVehicleModal): ?>
        <div class="legacy-modal-backdrop" id="newVehicleModal">
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
                <select id="periodYear" name="year" data-auto-submit>
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
        <div class="card chart-card"><h2><i class="bi bi-graph-up-arrow"></i> <?= $hasTractionBattery ? 'Měsíční nájezd a průměrná spotřeba' : 'Měsíční nájezd' ?></h2>
            <p><?= $hasTractionBattery ? 'Vývoj nájezdu jako plošný trend a spotřeba jako samostatná křivka' : 'Ujeté kilometry v jednotlivých měsících' ?></p>
            <div class="chart-visual"><canvas id="monthly"></canvas></div>
        </div>
        <div class="card chart-card"><h2><i class="bi bi-clock-history"></i> Denní rytmus (v kolik hodin vyjíždíte)</h2>
            <p>Četnost výjezdů podle hodin dne</p>
            <div class="chart-visual"><canvas id="hours"></canvas></div>
        </div>
        <?php if ($hasTractionBattery): ?>
            <div class="card energy"><h2><i class="bi bi-lightning-charge-fill"></i> Energetická bilance a nabíjecí lokality</h2><?php if ($chargeDataAvailable): ?><p>Odhad energie podle veřejného nabíjení zaznamenaného v importu</p>
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
            <div class="card energy"><h2><i class="bi bi-wallet2"></i> Provozní náklady</h2>
                <p>Souhrn ručně evidovaného provozu vozidla</p>
                <div class="energy-list">
                    <div><b class="green">⛽ Palivo</b><strong><?= cz((float)$operationSummary['energy_cost'], 0) ?> Kč</strong><small>tankování</small></div>
                    <div><b>🔧 Servis</b><strong><?= cz((float)$operationSummary['service_cost'], 0) ?> Kč</strong><small>servisní záznamy</small></div>
                    <div><b class="orange">💸 Ostatní</b><strong><?= cz((float)$operationSummary['other_cost'], 0) ?> Kč</strong><small>pojištění, parkování a další</small></div>
                </div>
                <footer>Celkem: <b><?= cz((float)$operationSummary['total_cost'], 0) ?> Kč</b><span><a href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>">doplnit provozní evidenci</a></span></footer>
            </div>
        <?php endif; ?>
        <div class="card chart-card speed-band-card"><h2><i class="bi bi-speedometer"></i> <?= $hasTractionBattery ? 'Spotřeba podle rychlostních pásem' : 'Nájezd podle rychlostních pásem' ?></h2>
            <p><?= $hasTractionBattery ? 'Vážená průměrná spotřeba jízd v jednotlivých rychlostních pásmech' : 'Ujeté kilometry podle průměrné rychlosti jízd' ?></p>
            <div class="chart-visual chart-visual-speed"><canvas id="speed"></canvas></div>
            <div class="speed-band-summary" aria-label="Hodnoty podle rychlostních pásem">
                <?php foreach ($bandLabels as $bandIndex => $bandLabel): ?>
                    <div class="speed-band-row speed-band-row-<?= (int)$bandIndex ?>">
                        <span><i aria-hidden="true"></i><b><?= h($bandLabel) ?></b><small><?= cz((float)$bandKm[$bandIndex], 1) ?> km jízd</small></span>
                        <?php if ($hasTractionBattery): ?>
                            <strong><?= $bandValues[$bandIndex] !== NULL ? cz((float)$bandValues[$bandIndex], 1) : '—' ?><em>kWh/100 km</em></strong>
                        <?php else: ?>
                            <strong><?= cz((float)$bandKm[$bandIndex], 1) ?><em>km</em></strong>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card"><h2><i class="bi bi-arrow-repeat"></i> Pravidelné dojíždění</h2>
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
        <div class="card"><h2><i class="bi bi-signpost-split"></i> Nejčastější pravidelné trasy</h2>
            <p>Statistika tras s nejvyšším počtem opakování</p><?php if ($routes): ?>
                <div class="route-list"><?php $i = 0; foreach ($routes as $name => $r) { if ($i++ >= 3) { break; } $c = $r['km'] ? $r['kwh'] / $r['km'] * 100 : 0; ?>
                        <div><span><b><?= h($name) ?></b><small><?= cz($r['km'] / $r['count'], 1) ?> km průměr</small></span><?php if ($hasTractionBattery): ?><mark><?= cz($c, 1) ?> kWh/100 km</mark><?php endif; ?><small><?= $r['count'] ?>× jízda</small></div>
                    <?php } ?></div>
            <?php else: ?><p><b>Trasy nejsou dostupné.</b> Import neobsahuje GPS ani adresy.</p><?php endif; ?></div>
    </section>
    <section class="card table-card"><h2><i class="bi bi-map"></i> Cesty &gt; 80 km <span class="pill"><?= $longTripCount ?> tras</span></h2>
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
                        <td>
                            <div class="route-cell">
                                <b><?= h(displayRoute($t['start_address'], $t['end_address'])) ?></b>
                                <?php if (($t['start_lat'] ?? null) !== null || ($t['end_lat'] ?? null) !== null || strpos((string)($t['source_format'] ?? ''), 'telemetry_') === 0): ?>
                                    <button type="button" class="route-map-trigger" data-trip-map="<?= (int)$t['id'] ?>" title="Zobrazit trasu na mapě"><i class="bi bi-map"></i> Mapa</button>
                                <?php endif; ?>
                            </div>
                        </td>
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
        <?php if ($longTripPages > 1): ?>
            <?php $pagination = \App\Pagination::meta((int)$longTripCount, (int)$longTripPage, 20, 'long_page'); require __DIR__ . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>
    <section class="card table-card history-card">
        <div class="table-card-head">
            <div><h2><i class="bi bi-list-ul"></i> Seznam a historie jízd</h2>
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
                        <td>
                            <div class="route-cell">
                                <b><?= h(displayRoute($t['start_address'], $t['end_address'])) ?></b>
                            </div>
                        </td>
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
                        <td class="trip-actions-col">
                            <div class="trip-row-actions">
                                <?php if (($t['start_lat'] ?? null) !== null || ($t['end_lat'] ?? null) !== null || strpos((string)($t['source_format'] ?? ''), 'telemetry_') === 0): ?>
                                    <button type="button" class="icon-action map-action" data-trip-map="<?= (int)$t['id'] ?>" title="Zobrazit trasu" aria-label="Zobrazit trasu na mapě"><i class="bi bi-map"></i></button>
                                <?php endif; ?>
                                <button type="button" class="icon-action" data-trip-edit="<?= (int)$t['id'] ?>" title="Upravit jízdu" aria-label="Upravit jízdu"><i class="bi bi-pencil"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
        <?php if ($historyPages > 1): ?>
            <?php $pagination = \App\Pagination::meta((int)$tripCount, (int)$historyPage, 20, 'page'); require __DIR__ . '/partials/pagination.php'; ?>
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
<div class="modal fade route-map-modal" id="tripMapModal" tabindex="-1" aria-labelledby="tripMapTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header route-map-modal-head">
                <div>
                    <span class="eyebrow">TRIP EXPLORER</span>
                    <h2 class="modal-title" id="tripMapTitle"><i class="bi bi-map"></i> Trasa jízdy</h2>
                    <p id="tripMapSubtitle">Načítám mapová data…</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body route-map-modal-body">
                <div class="route-map-stats" id="tripMapStats">
                    <div><small>VZDÁLENOST</small><strong>—</strong></div>
                    <div><small>ČAS JÍZDY</small><strong>—</strong></div>
                    <div><small>PRŮMĚRNÁ RYCHLOST</small><strong>—</strong></div>
                    <div><small>TRASA</small><strong>—</strong></div>
                </div>
                <div class="route-map-shell">
                    <div id="tripRouteMap" class="route-map-canvas" aria-label="Mapa trasy"></div>
                    <div class="route-map-loading" id="tripMapLoading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Načítám trasu…</div>
                </div>
                <div class="route-map-legend">
                    <span><i class="route-dot start"></i> Start</span>
                    <span><i class="route-dot stop"></i> Zastávka</span>
                    <span><i class="route-dot charge"></i> Nabíjení</span>
                    <span><i class="route-dot finish"></i> Cíl</span>
                </div>
                <div class="route-map-details" id="tripMapDetails"></div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="tripEditorModal" tabindex="-1" aria-labelledby="tripEditorTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <header class="modal-header">
            <div><span class="eyebrow">HISTORIE JÍZD</span><h2 id="tripEditorTitle">Přidat jízdu</h2><p id="tripEditorSubtitle">Zapište jízdu ručně bez CSV importu.</p></div>
            <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zavřít"></button>
        </header>
        <form method="post" id="tripEditorForm" class="modal-scroll-form">
            <div class="modal-body">
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
            <div class="modal-actions"><button type="button" class="btn" data-bs-dismiss="modal">Zrušit</button><button type="submit" class="btn btn-primary">Uložit jízdu</button></div>
            </div>
        </form>
      </div>
    </div>
</div>
<script nonce="<?= h(cspNonce()) ?>" type="application/json" id="tripEditorData"><?= json_encode($tripEditorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<section class="dashboard-revolution-grid">
  <div class="card insight-panel"><div class="section-head"><div><span class="eyebrow">SMART LAYER</span><h2>✦ Insights</h2></div></div><div class="insight-strip stacked"><?php foreach($insights as $i):?><article class="insight-card"><span><?=$i['icon']?></span><div><b><?=h($i['title'])?></b><p><?=h($i['text'])?></p></div></article><?php endforeach;?></div></div>
  <div class="card mini-timeline"><div class="section-head"><div><span class="eyebrow">POSLEDNÍ UDÁLOSTI</span><h2>◷ Timeline</h2></div><a href="timeline.php">Celá historie →</a></div><?php foreach($timelineEvents as $e):?><div class="mini-event"><span><?=['trip'=>'🚗','energy'=>'⚡','service'=>'🔧','expense'=>'💳'][$e['type']]?></span><div><b><?=h((string)($e['label']?:ucfirst($e['type'])))?></b><small><?=h(date('d.m.Y H:i',strtotime($e['event_at'])))?> · <?=h((string)($e['detail']??''))?></small></div></div><?php endforeach;?><?php if(!$timelineEvents):?><p>Zatím žádné provozní události.</p><?php endif;?></div>
</section>
</main>
<script nonce="<?= h(cspNonce()) ?>">
    const monthlyLabels = <?=json_encode($monthLabels, JSON_UNESCAPED_UNICODE)?>;
    const monthlyKm = <?=json_encode($monthKm)?>;
    const monthlyCons = <?=json_encode($monthCons)?>;
    const hours = <?=json_encode(array_values($hourData))?>;
    const speedLabels = <?=json_encode($bandLabels, JSON_UNESCAPED_UNICODE)?>;
    const speedChartLabels = [
        ['Město', '< 40 km/h'],
        ['Okresky', '40–65 km/h'],
        ['Rychlé okresky', '65–85 km/h'],
        ['Dálnice', '> 85 km/h']
    ];
    const speedValues = <?=json_encode($hasTractionBattery ? $bandValues : $bandKm)?>;
    const speedBandKm = <?=json_encode($bandKm)?>;

    const themeStyles = getComputedStyle(document.documentElement);
    const chartGrid = themeStyles.getPropertyValue('--chart-grid').trim() || 'rgba(148,163,184,.12)';
    const chartText = themeStyles.getPropertyValue('--chart-text').trim() || '#94a3b8';
    const chartPanel = themeStyles.getPropertyValue('--cockpit-panel').trim() || '#101225';
    const chartStrong = themeStyles.getPropertyValue('--cockpit-text').trim() || '#f8fafc';
    const chartMuted = themeStyles.getPropertyValue('--cockpit-muted').trim() || '#94a3b8';
    const palette = {
        teal: '#2dd4bf',
        emerald: '#22c55e',
        amber: '#f59e0b',
        coral: '#fb7185',
        sky: '#38bdf8',
        lime: '#a3e635'
    };

    Chart.defaults.color = chartText;
    Chart.defaults.font.family = 'Inter,system-ui,sans-serif';
    Chart.defaults.animation.duration = 520;

    const gradient = (canvas, color, alphaTop = .34) => {
        const ctx = canvas.getContext('2d');
        const fill = ctx.createLinearGradient(0, 0, 0, Math.max(canvas.parentElement?.clientHeight || 280, 220));
        const rgb = color.replace('#', '').match(/.{2}/g).map(hex => parseInt(hex, 16));
        fill.addColorStop(0, `rgba(${rgb[0]},${rgb[1]},${rgb[2]},${alphaTop})`);
        fill.addColorStop(1, `rgba(${rgb[0]},${rgb[1]},${rgb[2]},0)`);
        return fill;
    };

    const commonPlugins = {
        legend: {
            position: 'top',
            align: 'end',
            labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, padding: 18 }
        },
        tooltip: {
            backgroundColor: chartPanel,
            titleColor: chartStrong,
            bodyColor: chartMuted,
            borderColor: chartGrid,
            borderWidth: 1,
            padding: 11,
            cornerRadius: 10,
            displayColors: true
        }
    };

    const monthlyCanvas = document.getElementById('monthly');
    if (monthlyCanvas) {
        new Chart(monthlyCanvas, {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Ujeto km',
                    data: monthlyKm,
                    borderColor: palette.teal,
                    backgroundColor: gradient(monthlyCanvas, palette.teal, .32),
                    fill: true,
                    tension: .38,
                    borderWidth: 2.4,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: palette.teal,
                    pointBorderColor: chartPanel,
                    pointBorderWidth: 2,
                    yAxisID: 'y'
                }<?php if ($hasTractionBattery): ?>, {
                    label: 'Spotřeba kWh/100 km',
                    data: monthlyCons,
                    borderColor: palette.amber,
                    backgroundColor: palette.amber,
                    fill: false,
                    tension: .36,
                    borderWidth: 2.2,
                    pointRadius: 2.5,
                    pointHoverRadius: 6,
                    pointBackgroundColor: palette.amber,
                    pointBorderColor: chartPanel,
                    pointBorderWidth: 2,
                    yAxisID: 'y1'
                }<?php endif; ?>]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: commonPlugins,
                scales: {
                    x: { grid: { display: false }, ticks: { color: chartText } },
                    y: {
                        beginAtZero: true,
                        grid: { color: chartGrid, drawBorder: false },
                        ticks: { color: chartText },
                        title: { display: true, text: 'km', color: chartText }
                    }<?php if ($hasTractionBattery): ?>,
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { color: palette.amber },
                        title: { display: true, text: 'kWh/100 km', color: palette.amber }
                    }<?php endif; ?>
                }
            }
        });
    }

    const hoursCanvas = document.getElementById('hours');
    if (hoursCanvas) {
        new Chart(hoursCanvas, {
            type: 'line',
            data: {
                labels: Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0') + ':00'),
                datasets: [{
                    label: 'Počet výjezdů',
                    data: hours,
                    borderColor: palette.coral,
                    backgroundColor: gradient(hoursCanvas, palette.coral, .30),
                    fill: true,
                    tension: .42,
                    borderWidth: 2.4,
                    pointRadius: hours.map(value => value > 0 ? 2.5 : 0),
                    pointHoverRadius: 6,
                    pointBackgroundColor: palette.coral,
                    pointBorderColor: chartPanel,
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    ...commonPlugins,
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: chartText, maxRotation: 0, callback: function(value, index) { return index % 3 === 0 ? this.getLabelForValue(value) : ''; } }
                    },
                    y: { beginAtZero: true, ticks: { precision: 0, color: chartText }, grid: { color: chartGrid } }
                }
            }
        });
    }

    <?php if ($hasTractionBattery && $chargeDataAvailable): ?>
    const energyCanvas = document.getElementById('energy');
    if (energyCanvas) {
        new Chart(energyCanvas, {
            type: 'doughnut',
            data: {
                labels: ['AC / ostatní', 'Veřejné DC'],
                datasets: [{
                    data: [<?=round($homeKwh, 2)?>, <?=round($publicKwh, 2)?>],
                    backgroundColor: [palette.emerald, palette.amber],
                    hoverBackgroundColor: [palette.teal, '#fbbf24'],
                    borderColor: chartPanel,
                    borderWidth: 5,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: commonPlugins.tooltip
                }
            }
        });
    }
    <?php endif; ?>

    const speedCanvas = document.getElementById('speed');
    if (speedCanvas) {
        const speedNumberFormat = new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        const speedUnit = <?= json_encode($hasTractionBattery ? 'kWh/100 km' : 'km', JSON_UNESCAPED_UNICODE) ?>;
        const speedValueLabels = {
            id: 'speedValueLabels',
            afterDatasetsDraw(chart) {
                const meta = chart.getDatasetMeta(0);
                const ctx = chart.ctx;
                ctx.save();
                ctx.fillStyle = chartStrong;
                ctx.font = '700 10px Inter, system-ui, sans-serif';
                ctx.textBaseline = 'middle';

                meta.data.forEach((bar, index) => {
                    const value = speedValues[index];
                    if (value === null || value === undefined || speedBandKm[index] <= 0) {
                        return;
                    }

                    const text = `${speedNumberFormat.format(Number(value))} ${speedUnit}`;
                    const textWidth = ctx.measureText(text).width;
                    const preferredX = bar.x + 8;
                    const availableRight = chart.width - 8;
                    if (preferredX + textWidth <= availableRight) {
                        ctx.textAlign = 'left';
                        ctx.fillText(text, preferredX, bar.y);
                    } else {
                        ctx.textAlign = 'right';
                        ctx.fillText(text, availableRight, bar.y);
                    }
                });
                ctx.restore();
            }
        };

        new Chart(speedCanvas, {
            type: 'bar',
            plugins: [speedValueLabels],
            data: {
                labels: speedChartLabels,
                datasets: [{
                    label: speedUnit,
                    data: speedValues,
                    backgroundColor: [palette.teal, palette.sky, palette.amber, palette.coral],
                    hoverBackgroundColor: [palette.emerald, '#60a5fa', '#fbbf24', '#f43f5e'],
                    borderWidth: 0,
                    borderRadius: 8,
                    borderSkipped: false,
                    barThickness: 22,
                    maxBarThickness: 26
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', axis: 'y', intersect: false },
                layout: { padding: { right: <?= $hasTractionBattery ? '78' : '48' ?> } },
                plugins: {
                    ...commonPlugins,
                    legend: { display: false },
                    tooltip: {
                        ...commonPlugins.tooltip,
                        callbacks: {
                            label(context) {
                                const value = context.raw;
                                if (value === null || value === undefined) {
                                    return 'Bez dat';
                                }
                                return `${speedNumberFormat.format(Number(value))} ${speedUnit}`;
                            },
                            afterLabel(context) {
                                return `${speedNumberFormat.format(Number(speedBandKm[context.dataIndex] || 0))} km jízd`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: chartGrid, drawBorder: false },
                        ticks: { color: chartText },
                        title: { display: true, text: speedUnit, color: chartText }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: chartStrong, font: { size: 10, weight: '600' } }
                    }
                }
            }
        });
    }
</script>

<script nonce="<?= h(cspNonce()) ?>">
(() => {
    const initTripEditor = () => {
    const modal = document.getElementById('tripEditorModal');
    const form = document.getElementById('tripEditorForm');
    if (!modal || !form || !window.bootstrap) return;
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
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
        modalInstance.show();
    };
    document.querySelector('[data-trip-create]')?.addEventListener('click', () => open());
    document.querySelectorAll('[data-trip-edit]').forEach(btn => btn.addEventListener('click', () => open(data[btn.dataset.tripEdit] || null)));
    modal.addEventListener('shown.bs.modal', () => document.getElementById('trip_started_at')?.focus());

    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTripEditor, { once: true });
    } else {
        initTripEditor();
    }
})();
</script>

<script nonce="<?= h(cspNonce()) ?>">
(() => {
    const initTripMap = () => {
        const modal = document.getElementById('tripMapModal');
        const mapElement = document.getElementById('tripRouteMap');
        if (!modal || !mapElement) return;

        const details = document.getElementById('tripMapDetails');
        if (!window.L) {
            if (details) details.innerHTML = '<div class="route-map-empty is-error"><i class="bi bi-exclamation-triangle"></i><div><b>Mapová knihovna se nenačetla.</b><span>Zkontrolujte připojení k CDN a obnovte stránku.</span></div></div>';
            return;
        }
        if (!window.bootstrap) {
            if (details) details.innerHTML = '<div class="route-map-empty is-error"><i class="bi bi-exclamation-triangle"></i><div><b>Dialog mapy se nepodařilo inicializovat.</b><span>Obnovte stránku a zkuste to znovu.</span></div></div>';
            return;
        }

        const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
        const loading = document.getElementById('tripMapLoading');
        const subtitle = document.getElementById('tripMapSubtitle');
        const stats = Array.from(document.querySelectorAll('#tripMapStats > div strong'));
        let map = null;
        let routeLayer = null;
        let markerLayer = null;
        let activeTrip = 0;
        let activeRouteBounds = null;
        let activeRoutePoint = null;

        const fmt = new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 1 });
        const time = value => {
            if (!value) return '—';
            const normalized = value.includes('T') ? value : value.replace(' ', 'T');
            const date = new Date(normalized);
            return Number.isNaN(date.getTime()) ? value : date.toLocaleString('cs-CZ', { dateStyle: 'short', timeStyle: 'short' });
        };
        const safe = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));

        const ensureMap = () => {
            if (map) return map;
            map = L.map(mapElement, { zoomControl: true, preferCanvas: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);
            routeLayer = L.layerGroup().addTo(map);
            markerLayer = L.layerGroup().addTo(map);
            return map;
        };

        const fitActiveRoute = () => {
            if (!map) return;

            map.invalidateSize({ pan: false });
            if (activeRouteBounds && activeRouteBounds.isValid()) {
                map.fitBounds(activeRouteBounds, {
                    paddingTopLeft: [42, 42],
                    paddingBottomRight: [42, 42],
                    maxZoom: 15,
                    animate: false
                });
                return;
            }

            if (activeRoutePoint) {
                map.setView(activeRoutePoint, 14, { animate: false });
            }
        };

        const marker = (point, kind, title, body) => {
            const icon = L.divIcon({
                className: 'route-map-div-icon',
                html: `<span class="route-map-pin ${kind}"><i class="bi ${kind === 'charge' ? 'bi-lightning-charge-fill' : kind === 'start' ? 'bi-play-fill' : kind === 'finish' ? 'bi-flag-fill' : 'bi-pause-fill'}"></i></span>`,
                iconSize: [34, 34],
                iconAnchor: [17, 17]
            });
            return L.marker([point.lat, point.lng], { icon }).bindPopup(`<div class="route-popup"><b>${safe(title)}</b>${body ? `<span>${body}</span>` : ''}</div>`);
        };

        const paint = data => {
            const m = ensureMap();
            routeLayer.clearLayers();
            markerLayer.clearLayers();
            const points = data.route?.points || [];
            const trip = data.trip || {};
            activeRouteBounds = null;
            activeRoutePoint = null;

            subtitle.textContent = `${trip.start_address || 'Start'} → ${trip.end_address || 'Cíl'}`;
            stats[0].textContent = `${fmt.format(trip.distance_km || 0)} km`;
            stats[1].textContent = `${trip.driving_minutes || 0} min`;
            stats[2].textContent = trip.avg_speed_kmh == null ? '—' : `${fmt.format(trip.avg_speed_kmh)} km/h`;
            stats[3].textContent = data.route?.source === 'telemetry' ? 'GPS / telemetrie' : data.route?.source === 'endpoints' ? 'Start + cíl' : 'Bez GPS';

            if (!data.map_available || !points.length) {
                m.setView([49.8, 15.5], 7);
                details.innerHTML = '<div class="route-map-empty"><i class="bi bi-geo-alt"></i><div><b>Pro tuto jízdu nejsou dostupné souřadnice.</b><span>Mapa se zpřístupní u jízd s GPS startem/cílem nebo s telemetrickými body.</span></div></div>';
                return;
            }

            const latLngs = points.map(point => [Number(point.lat), Number(point.lng)]).filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));
            if (!latLngs.length) {
                throw new Error('Mapová data neobsahují platné GPS souřadnice.');
            }
            if (latLngs.length >= 2) {
                L.polyline(latLngs, { color: '#2dd4bf', weight: 5, opacity: .92, lineCap: 'round', lineJoin: 'round' }).addTo(routeLayer);
                L.polyline(latLngs, { color: '#f59e0b', weight: 1.4, opacity: .86, dashArray: '4 9' }).addTo(routeLayer);
            }

            const start = points[0];
            const finish = points[points.length - 1];
            marker(start, 'start', 'Start', `${safe(trip.start_address || '')}<br>${safe(time(trip.started_at))}`).addTo(markerLayer);
            if (points.length > 1) marker(finish, 'finish', 'Cíl', `${safe(trip.end_address || '')}<br>${safe(time(trip.ended_at))}`).addTo(markerLayer);

            (data.stops || []).forEach((stop, index) => {
                marker(stop, 'stop', `Zastávka ${index + 1}`, `${stop.minutes || 0} min<br>${safe(time(stop.started_at))}`).addTo(markerLayer);
            });
            (data.charging || []).forEach((charge) => {
                const soc = charge.start_soc != null || charge.end_soc != null
                    ? `<br>SoC ${charge.start_soc ?? '—'} → ${charge.end_soc ?? '—'} %`
                    : '';
                marker(charge, 'charge', charge.station || 'Nabíjení', `${fmt.format(charge.quantity || 0)} ${safe(charge.unit || 'kWh')}${soc}<br>${safe(time(charge.occurred_at))}`).addTo(markerLayer);
            });

            const bounds = L.latLngBounds(latLngs);
            if (latLngs.length === 1) {
                activeRoutePoint = latLngs[0];
            } else {
                activeRouteBounds = bounds.pad(.10);
            }
            fitActiveRoute();
            window.setTimeout(fitActiveRoute, 80);

            const chips = [];
            if (data.route?.has_telemetry_track) chips.push(`<span><i class="bi bi-broadcast-pin"></i> ${points.length} GPS bodů</span>`);
            if ((data.stops || []).length) chips.push(`<span><i class="bi bi-pause-circle"></i> ${(data.stops || []).length} zastávek</span>`);
            if ((data.charging || []).length) chips.push(`<span><i class="bi bi-lightning-charge"></i> ${(data.charging || []).length} nabíjení</span>`);
            if (trip.start_soc != null || trip.end_soc != null) chips.push(`<span><i class="bi bi-battery-half"></i> SoC ${trip.start_soc ?? '—'} → ${trip.end_soc ?? '—'} %</span>`);
            details.innerHTML = chips.length ? `<div class="route-map-detail-chips">${chips.join('')}</div>` : '<span class="text-secondary">K jízdě nejsou další mapové události.</span>';
        };

        const loadTrip = async tripId => {
            if (!tripId) return;
            activeTrip = tripId;
            loading.hidden = false;
            details.innerHTML = '';
            subtitle.textContent = 'Načítám mapová data…';
            stats.forEach(item => { item.textContent = '—'; });
            modalInstance.show();

            try {
                const response = await fetch(`trip-map.php?vehicle_id=<?= (int)$vehicle['id'] ?>&trip_id=${encodeURIComponent(tripId)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                const raw = await response.text();
                let data = null;
                try {
                    data = JSON.parse(raw);
                } catch (error) {
                    throw new Error(response.redirected ? 'Relace vypršela. Přihlaste se prosím znovu.' : 'Server nevrátil platná mapová data.');
                }
                if (!response.ok) throw new Error(data.error || 'Mapová data se nepodařilo načíst.');
                if (activeTrip !== tripId) return;
                paint(data);
            } catch (error) {
                ensureMap().setView([49.8, 15.5], 7);
                details.innerHTML = `<div class="route-map-empty is-error"><i class="bi bi-exclamation-triangle"></i><div><b>Mapu nelze zobrazit.</b><span>${safe(error.message || 'Neznámá chyba')}</span></div></div>`;
            } finally {
                loading.hidden = true;
                window.setTimeout(fitActiveRoute, 120);
            }
        };

        document.querySelectorAll('[data-trip-map]').forEach(button => {
            button.addEventListener('click', () => loadTrip(Number(button.dataset.tripMap || 0)));
        });
        modal.addEventListener('shown.bs.modal', () => {
            window.setTimeout(fitActiveRoute, 40);
            window.setTimeout(fitActiveRoute, 180);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTripMap, { once: true });
    } else {
        initTripMap();
    }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

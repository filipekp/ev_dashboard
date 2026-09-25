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
    $intelligence = isset($intelligence) && is_array($intelligence) ? $intelligence : [];
    $liveDrive = isset($intelligence['live_drive']) && is_array($intelligence['live_drive'])
        ? $intelligence['live_drive']
        : NULL;
    $batteryHealth = isset($intelligence['battery_health']) && is_array($intelligence['battery_health'])
        ? $intelligence['battery_health']
        : [];
    $rangePrediction = isset($intelligence['range_prediction']) && is_array($intelligence['range_prediction'])
        ? $intelligence['range_prediction']
        : [];
    $qualitySummary = isset($intelligence['quality_summary']) && is_array($intelligence['quality_summary'])
        ? $intelligence['quality_summary']
        : [];
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
        || ($liveTelemetry['vehicle_speed_kmh'] ?? NULL) !== NULL
        || $liveTelemetry['battery_temperature_c'] !== NULL
        || ($liveTelemetry['air_conditioning_state'] ?? NULL) !== NULL
        || ($liveTelemetry['auxiliary_heating_state'] ?? NULL) !== NULL
        || ($liveTelemetry['active_ventilation_state'] ?? NULL) !== NULL
        || ($liveTelemetry['window_heating_front'] ?? NULL) !== NULL
        || ($liveTelemetry['window_heating_rear'] ?? NULL) !== NULL
        || ($liveTelemetry['windshield_defrost_state'] ?? NULL) !== NULL
        || ($liveTelemetry['steering_wheel_heat_state'] ?? NULL) !== NULL
        || ($liveTelemetry['outside_temperature_c'] ?? NULL) !== NULL
    );
    if ($hasTractionBattery && isset($batteryHealth['soh_pct']) && $batteryHealth['soh_pct'] !== NULL) {
        $soh = (float)$batteryHealth['soh_pct'];
        $sohSourceMap = [
            'manual_diagnostic' => 'Diagnostická hodnota',
            'trip_energy_model' => 'EV Stats Battery Health',
            'insufficient_data' => 'Nedostatek dat',
        ];
        $sohSource = $sohSourceMap[(string)($batteryHealth['source'] ?? '')] ?? 'EV Stats odhad';
        $sohSamples = array_fill(0, max(0, (int)($batteryHealth['sample_count'] ?? 0)), 1);
        $sohClass = $soh >= 90 ? 'green' : ($soh >= 80 ? 'amber' : 'red');
    }
    $qualityLabel = static function ($status): string {
        $labels = [
            'good' => 'Spolehlivá',
            'warning' => 'Prověřit',
            'bad' => 'Podezřelá',
            'unknown' => 'Neověřeno',
        ];
        return $labels[(string)$status] ?? 'Neověřeno';
    };
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
    $chargingStateLabel = static function ($state): string {
        $value = strtolower(trim((string)$state));
        $labels = [
            'notcharging' => 'Nenabíjí',
            'charging' => 'AC nabíjení',
            'fastcharging' => 'DC rychlonabíjení',
            'reservedcharging' => 'Naplánované nabíjení',
            'wirelesscharging' => 'Bezdrátové nabíjení',
            'v2loperating' => 'V2L aktivní',
            'v2lstop' => 'V2L zastaveno',
            'v2xoperating' => 'V2X aktivní',
        ];
        return $labels[$value] ?? ($value !== '' ? $value : '—');
    };
    $chargingMinutes = static function ($minutes): string {
        if (!is_numeric($minutes) || (int)$minutes <= 0) {
            return '';
        }
        $minutes = (int)$minutes;
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours > 0 && $rest > 0) {
            return $hours . ' h ' . $rest . ' min';
        }
        return $hours > 0 ? $hours . ' h' : $rest . ' min';
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
            $chargingDetails = [];
            if ($liveCharging === TRUE) {
                $liveChargingLabel = 'Nabíjí';
                if ($liveTelemetry['charging_power_kw'] !== NULL) {
                    $chargingDetails[] = cz((float)$liveTelemetry['charging_power_kw'], 1) . ' kW';
                }
            } elseif ($livePlugged === TRUE) {
                $liveChargingLabel = 'Připojeno';
                $chargingDetails[] = 'kabel je připojen';
            } elseif ($livePlugged === FALSE) {
                $liveChargingLabel = 'Odpojeno';
                $chargingDetails[] = 'kabel není připojen';
            } elseif ($liveCharging === FALSE) {
                $liveChargingLabel = 'Nenabíjí';
                $chargingDetails[] = 'nabíjení není aktivní';
            }
            if (($liveTelemetry['charging_status'] ?? NULL) !== NULL) {
                $chargingDetails[] = $chargingStateLabel($liveTelemetry['charging_status']);
            }
            $remainingCharging = $chargingMinutes($liveTelemetry['charging_remaining_minutes'] ?? NULL);
            if ($liveCharging === TRUE && $remainingCharging !== '') {
                $chargingDetails[] = 'zbývá ' . $remainingCharging;
            }
            $targetStandard = $liveTelemetry['charging_target_standard_pct'] ?? NULL;
            $targetQuick = $liveTelemetry['charging_target_quick_pct'] ?? NULL;
            if ($targetStandard !== NULL || $targetQuick !== NULL) {
                $targets = [];
                if ($targetStandard !== NULL) {
                    $targets[] = 'AC ' . cz((float)$targetStandard, 0) . ' %';
                }
                if ($targetQuick !== NULL) {
                    $targets[] = 'DC ' . cz((float)$targetQuick, 0) . ' %';
                }
                $chargingDetails[] = 'cíl ' . implode(' / ', $targets);
            }
            $liveChargingDetail = $chargingDetails ? implode(' · ', array_unique($chargingDetails)) : 'stav není dostupný';
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
                <?php if ($hasFuelSystem && $liveTelemetry['fuel_level_pct'] !== NULL): ?>
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
                        if (($liveTelemetry['climate_control_mode'] ?? NULL) !== NULL) {
                            $mode = strtolower((string)$liveTelemetry['climate_control_mode']);
                            $airDetails[] = $mode === 'auto' ? 'automatika' : ($mode === 'manual' ? 'manuální režim' : $mode);
                        }
                        if (($liveTelemetry['climate_blower_speed'] ?? NULL) !== NULL) {
                            $blower = (int)$liveTelemetry['climate_blower_speed'];
                            $airDetails[] = $blower < 0 ? 'ventilátor auto' : ($blower === 0 ? 'ventilátor vypnutý' : 'ventilátor ' . $blower . '/8');
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
                <?php if (($liveTelemetry['outside_temperature_c'] ?? NULL) !== NULL): ?>
                    <div class="live-vehicle-metric"><small>VENKOVNÍ TEPLOTA</small><strong><?= cz((float)$liveTelemetry['outside_temperature_c'], 1) ?> °C</strong><span>hlášená vozidlem</span></div>
                <?php endif; ?>
                <?php if (($liveTelemetry['windshield_defrost_state'] ?? NULL) !== NULL): ?>
                    <div class="live-vehicle-metric"><small>ČELNÍ SKLO</small><strong><?= h($climateStateLabel($liveTelemetry['windshield_defrost_state'])) ?></strong><span>odmlžení / defrost</span></div>
                <?php endif; ?>
                <?php if (($liveTelemetry['steering_wheel_heat_state'] ?? NULL) !== NULL): ?>
                    <div class="live-vehicle-metric"><small>VYHŘÍVÁNÍ VOLANTU</small><strong><?= h($climateStateLabel($liveTelemetry['steering_wheel_heat_state'])) ?></strong><span>aktuální stav</span></div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
    <section class="live-drive-panel<?= $liveDrive === NULL ? ' is-idle' : '' ?>" data-live-drive data-vehicle-id="<?= (int)$vehicle['id'] ?>" <?= $liveDrive === NULL ? 'hidden' : '' ?>>
        <div class="live-drive-head">
            <div>
                <span class="live-drive-pulse" aria-hidden="true"></span>
                <div><small>LIVE DRIVE</small><strong>Probíhající jízda</strong></div>
            </div>
            <span data-live-field="last_snapshot_at"><?= $liveDrive !== NULL && !empty($liveDrive['last_snapshot_at']) ? h(date('H:i:s', strtotime((string)$liveDrive['last_snapshot_at']))) : '—' ?></span>
        </div>
        <div class="live-drive-grid">
            <div><small>AKTUÁLNÍ RYCHLOST</small><strong data-live-field="current_speed_kmh"><?= $liveDrive !== NULL && $liveDrive['current_speed_kmh'] !== NULL ? cz((float)$liveDrive['current_speed_kmh'], 0) : '—' ?></strong><span>km/h</span></div>
            <div><small>UJETO</small><strong data-live-field="distance_km"><?= $liveDrive !== NULL ? cz((float)$liveDrive['distance_km'], 1) : '—' ?></strong><span>km</span></div>
            <div><small>PRŮM. SPOTŘEBA</small><strong data-live-field="avg_consumption_kwh_100"><?= $liveDrive !== NULL && $liveDrive['avg_consumption_kwh_100'] !== NULL ? cz((float)$liveDrive['avg_consumption_kwh_100'], 1) : '—' ?></strong><span>kWh/100 km</span></div>
            <div><small>SoC</small><strong data-live-field="current_soc"><?= $liveDrive !== NULL && $liveDrive['current_soc'] !== NULL ? cz((float)$liveDrive['current_soc'], 0) : '—' ?></strong><span>%</span></div>
            <div><small>EV STATS DOJEZD</small><strong data-live-field="predicted_remaining_range_km"><?= $liveDrive !== NULL && $liveDrive['predicted_remaining_range_km'] !== NULL ? cz((float)$liveDrive['predicted_remaining_range_km'], 0) : '—' ?></strong><span>km</span></div>
            <div><small>DATA QUALITY</small><strong data-live-field="quality_score"><?= $liveDrive !== NULL && $liveDrive['quality_score'] !== NULL ? (int)$liveDrive['quality_score'] : '—' ?></strong><span>/ 100</span></div>
        </div>
        <div class="live-drive-foot">
            <span><i class="bi bi-geo-alt"></i> <b data-live-field="start_address"><?= h((string)($liveDrive['start_address'] ?? 'Start')) ?></b> → <b data-live-field="current_address"><?= h((string)($liveDrive['current_address'] ?? 'aktuální poloha')) ?></b></span>
            <span>Jízda běží <b data-live-field="elapsed_minutes"><?= $liveDrive !== NULL ? (int)$liveDrive['elapsed_minutes'] : 0 ?></b> min · predikce <b data-live-field="prediction_confidence_pct"><?= $liveDrive !== NULL ? (int)($liveDrive['prediction_confidence_pct'] ?? 0) : 0 ?></b> % confidence</span>
        </div>
    </section>
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

    <?php if ($hasTractionBattery): ?>
        <section class="ev-intelligence-grid">
            <article class="card ev-intelligence-card">
                <div class="ev-intelligence-head"><span><i class="bi bi-battery-charging"></i></span><div><small>BATTERY HEALTH</small><h2>Digitální zdraví baterie</h2></div></div>
                <?php if (($batteryHealth['soh_pct'] ?? NULL) !== NULL): ?>
                    <div class="ev-intelligence-value"><strong><?= cz((float)$batteryHealth['soh_pct'], 1) ?>%</strong><span>SoH</span></div>
                    <div class="ev-intelligence-meter"><i style="width:<?= min(100, max(0, (float)$batteryHealth['soh_pct'])) ?>%"></i></div>
                    <p>Odhadovaná využitelná kapacita <b><?= ($batteryHealth['usable_capacity_kwh'] ?? NULL) !== NULL ? cz((float)$batteryHealth['usable_capacity_kwh'], 1) . ' kWh' : '—' ?></b> z nominálních <?= ($batteryHealth['nominal_capacity_kwh'] ?? NULL) !== NULL ? cz((float)$batteryHealth['nominal_capacity_kwh'], 1) . ' kWh' : '—' ?>.</p>
                    <footer><span><?= (int)($batteryHealth['sample_count'] ?? 0) ?> vzorků</span><span>confidence <?= (int)($batteryHealth['confidence_pct'] ?? 0) ?> %</span><?php if (($batteryHealth['trend_pct_per_10000_km'] ?? NULL) !== NULL): ?><span>trend <?= cz((float)$batteryHealth['trend_pct_per_10000_km'], 2) ?> % / 10 000 km</span><?php endif; ?></footer>
                <?php else: ?>
                    <div class="ev-intelligence-empty"><b>Potřebuji více jízd se SoC a energií.</b><span>Model se zpřesní automaticky s dalšími daty.</span></div>
                <?php endif; ?>
            </article>
            <article class="card ev-intelligence-card">
                <div class="ev-intelligence-head"><span><i class="bi bi-signpost-2"></i></span><div><small>EV STATS PREDICTION</small><h2>Reálný dojezd</h2></div></div>
                <?php if (($rangePrediction['full_range_km'] ?? NULL) !== NULL): ?>
                    <div class="ev-intelligence-value"><strong><?= cz((float)$rangePrediction['full_range_km'], 0) ?> km</strong><span>na 100 %</span></div>
                    <p>Model očekává spotřebu <b><?= cz((float)$rangePrediction['expected_consumption_kwh_100'], 1) ?> kWh/100 km</b> podle historie tohoto vozu<?php if (($rangePrediction['soc_pct'] ?? NULL) !== NULL && ($rangePrediction['remaining_range_km'] ?? NULL) !== NULL): ?>. Při <?= cz((float)$rangePrediction['soc_pct'], 0) ?> % zbývá přibližně <b><?= cz((float)$rangePrediction['remaining_range_km'], 0) ?> km</b><?php endif; ?>.</p>
                    <footer><span><?= (int)($rangePrediction['sample_count'] ?? 0) ?> jízd v modelu</span><span>confidence <?= (int)($rangePrediction['confidence_pct'] ?? 0) ?> %</span><?php if (($rangePrediction['vehicle_reported_range_km'] ?? NULL) !== NULL): ?><span>auto hlásí <?= cz((float)$rangePrediction['vehicle_reported_range_km'], 0) ?> km</span><?php endif; ?></footer>
                <?php else: ?>
                    <div class="ev-intelligence-empty"><b>Predikční model se právě učí.</b><span>Pro první odhad potřebuje alespoň několik dokončených jízd s energií.</span></div>
                <?php endif; ?>
            </article>
            <article class="card ev-intelligence-card">
                <div class="ev-intelligence-head"><span><i class="bi bi-shield-check"></i></span><div><small>DATA QUALITY ENGINE</small><h2>Důvěryhodnost historie</h2></div></div>
                <div class="ev-intelligence-value"><strong><?= ($qualitySummary['total'] ?? 0) > 0 ? cz((float)($qualitySummary['avg_score'] ?? 0), 0) : '—' ?></strong><span>/ 100</span></div>
                <div class="quality-summary-row"><span class="quality-dot good"></span><b><?= (int)($qualitySummary['good'] ?? 0) ?></b> spolehlivých <span class="quality-dot warning"></span><b><?= (int)($qualitySummary['warning'] ?? 0) ?></b> k prověření <span class="quality-dot bad"></span><b><?= (int)($qualitySummary['bad'] ?? 0) ?></b> podezřelých</div>
                <p>GPS skoky, nesmyslná rychlost, výpadky telemetrie, překryvy jízd a chyby tachometru se označují před tím, než ovlivní analytiku.</p>
                <footer><span><?= (int)($qualitySummary['total'] ?? 0) ?> jízd celkem</span><span><?= (int)($qualitySummary['unknown'] ?? 0) ?> čeká na kontrolu</span></footer>
            </article>
        </section>
    <?php endif; ?>

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
            <div class="card energy energy-overview">
                <div class="energy-overview-head">
                    <div>
                        <h2><i class="bi bi-lightning-charge-fill"></i> Energetická bilance a nabíjecí lokality</h2>
                        <p>Nabitá energie, náklady a nejčastější místa v aktuálním období</p>
                    </div>
                    <a href="operations.php?vehicle_id=<?= (int)$vehicle['id'] ?>#energy" class="energy-history-link">Historie energie <i class="bi bi-arrow-up-right"></i></a>
                </div>
                <?php if ($chargingHistoryAvailable): ?>
                    <div class="energy-kpi-grid">
                        <div class="energy-kpi">
                            <small>NABITO</small>
                            <strong><?= cz($chargedEnergyKwh, 1) ?> <em>kWh</em></strong>
                            <span><?= cz($chargingSessionCount, 0) ?> relací<?= $chargingActiveSessionCount > 0 ? ' · ' . cz($chargingActiveSessionCount, 0) . ' probíhá' : '' ?> · Ø <?= cz($chargingAvgSessionKwh, 1) ?> kWh</span>
                        </div>
                        <div class="energy-kpi">
                            <small>NÁKLADY</small>
                            <strong><?= $chargingCostTotal > 0 ? cz($chargingCostTotal, 0) : '—' ?> <em><?= $chargingCostTotal > 0 ? h($chargingCurrency === 'CZK' ? 'Kč' : $chargingCurrency) : '' ?></em></strong>
                            <span><?= $chargingAvgPricePerKwh > 0 ? 'Ø ' . cz($chargingAvgPricePerKwh, 2) . ' ' . h($chargingCurrency === 'CZK' ? 'Kč' : $chargingCurrency) . '/kWh' : 'Cena zatím není evidovaná' ?></span>
                        </div>
                        <div class="energy-kpi">
                            <small>CENOVÉ POKRYTÍ</small>
                            <strong><?= cz($chargingPriceCoveragePct, 0) ?> <em>%</em></strong>
                            <span><?= cz($chargingPricedKwh, 1) ?> kWh s evidovanou cenou</span>
                        </div>
                        <div class="energy-kpi">
                            <small>NABITO / JÍZDY</small>
                            <strong><?= $totalKwh > 0 ? cz($chargingEnergyCoveragePct, 0) : '—' ?> <em><?= $totalKwh > 0 ? '%' : '' ?></em></strong>
                            <span>Jízdy spotřebovaly <?= cz($totalKwh, 1) ?> kWh</span>
                        </div>
                    </div>

                    <div class="energy-location-panel">
                        <div class="energy-location-head">
                            <div><small>TOP LOKALITY</small><strong>Kam nejčastěji teče energie</strong></div>
                            <span>podle kWh</span>
                        </div>
                        <?php if ($chargingLocationsAvailable): ?>
                            <div class="energy-location-chart"><canvas id="energyLocations"></canvas></div>
                        <?php else: ?>
                            <div class="energy-location-empty"><i class="bi bi-geo-alt"></i><span>U nabíjecích relací zatím nejsou uložené lokality.</span></div>
                        <?php endif; ?>
                    </div>

                    <div class="energy-insights">
                        <div>
                            <span><i class="bi bi-receipt"></i> Energie z dokladů</span>
                            <strong><?= cz($chargingDocumentPct, 0) ?> %</strong>
                            <small><?= cz($chargingDocumentKwh, 1) ?> kWh s přesnými daty z dokladu</small>
                        </div>
                        <div>
                            <span><i class="bi bi-broadcast-pin"></i> Odhadovaná energie</span>
                            <strong><?= cz($chargingEstimatedPct, 0) ?> %</strong>
                            <small><?= cz($chargingEstimatedKwh, 1) ?> kWh je označeno jako odhad</small>
                        </div>
                        <div>
                            <span><i class="bi bi-car-front"></i> Náklad energie / 100 km</span>
                            <strong><?= $chargingCostTotal > 0 && $totalKm > 0 ? cz($chargingCostPer100Km, 0) . ' ' . h($chargingCurrency === 'CZK' ? 'Kč' : $chargingCurrency) : '—' ?></strong>
                            <small><?= cz($totalKm, 0) ?> km v aktuálním období</small>
                        </div>
                    </div>
                    <footer>
                        <span>Poměr „nabito / jízdy“ je orientační: nabíjecí relace může přesahovat hranice zvoleného období a zahrnuje nabíjecí ztráty.</span>
                    </footer>
                <?php elseif ($chargeDataAvailable): ?>
                    <div class="energy-kpi-grid energy-kpi-grid-legacy">
                        <div class="energy-kpi"><small>ODHAD NABITO</small><strong><?= cz($chargeTotal, 1) ?> <em>kWh</em></strong><span>z jízdních / SoC dat</span></div>
                        <div class="energy-kpi"><small>VEŘEJNÉ DC</small><strong><?= cz($publicPct, 0) ?> <em>%</em></strong><span><?= cz($publicStops, 0) ?> zastávek · <?= cz($publicKwh, 1) ?> kWh</span></div>
                        <div class="energy-kpi"><small>AC / OSTATNÍ</small><strong><?= cz($homePct, 0) ?> <em>%</em></strong><span><?= cz($homeKwh, 1) ?> kWh</span></div>
                        <div class="energy-kpi"><small>SPOTŘEBA JÍZD</small><strong><?= cz($totalKwh, 1) ?> <em>kWh</em></strong><span><?= cz($avgCons, 1) ?> kWh/100 km</span></div>
                    </div>
                    <div class="energy-location-panel">
                        <div class="energy-location-head"><div><small>ODHAD MIXU</small><strong>AC vs. veřejné DC</strong></div><span>z importu jízd</span></div>
                        <div class="energy-location-chart energy-location-chart-legacy"><canvas id="energyLegacy"></canvas></div>
                    </div>
                    <footer><span>* Veřejná energie je odhad z přírůstku SoC. Pro přesnější přehled evidujte nabíjení v Historii energie.</span></footer>
                <?php else: ?>
                    <div class="energy-empty-state">
                        <i class="bi bi-lightning-charge"></i>
                        <div><strong>Zatím chybí nabíjecí relace</strong><span>Nabíjení z OEM telemetrie nebo ručně přidané záznamy se zde automaticky promítnou.</span></div>
                    </div>
                    <div class="energy-insights energy-insights-empty">
                        <div><span><i class="bi bi-car-front"></i> Spotřeba jízd</span><strong><?= cz($totalKwh, 1) ?> kWh</strong><small><?= $totalKm > 0 ? cz($avgCons, 1) . ' kWh/100 km' : 'bez dat' ?></small></div>
                        <?php if ($costDataAvailable): ?><div><span><i class="bi bi-cash-coin"></i> Náklady z importu</span><strong><?= cz($costTotal, 0) ?> Kč</strong><small>součet dostupných hodnot</small></div><?php endif; ?>
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
                    <th>DATA / INSIGHT</th>
                    <th class="trip-actions-col">AKCE</th>
                </tr>
                </thead>
                <tbody><?php foreach ($historyTrips as $t): $isActiveTrip = (string)($t['trip_state'] ?? 'completed') === 'active'; ?>
                    <tr class="<?= $isActiveTrip ? 'trip-is-live' : '' ?>">
                        <td><?= date('d.m.Y H:i', strtotime($t['started_at'])) ?><?php if ($isActiveTrip): ?> <span class="live-trip-badge"><i class="bi bi-broadcast-pin"></i> LIVE</span><?php endif; ?></td>
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
                        <?php
                            $tripQualityStatus = (string)($t['quality_status'] ?? 'unknown');
                            $tripQualityScore = is_numeric($t['quality_score'] ?? NULL) ? (int)$t['quality_score'] : NULL;
                            $tripDelta = is_numeric($t['consumption_delta_pct'] ?? NULL) ? (float)$t['consumption_delta_pct'] : NULL;
                            $tripExpected = is_numeric($t['expected_consumption_kwh_100'] ?? NULL) ? (float)$t['expected_consumption_kwh_100'] : NULL;
                        ?>
                        <td>
                            <div class="trip-quality-cell">
                                <span class="quality-badge <?= h($tripQualityStatus) ?>"><i></i><?= h($qualityLabel($tripQualityStatus)) ?><?= $tripQualityScore !== NULL ? ' · ' . $tripQualityScore : '' ?></span>
                                <?php if ($hasTractionBattery && $tripExpected !== NULL): ?>
                                    <small>Oček. <?= cz($tripExpected, 1) ?> kWh/100 km<?= $tripDelta !== NULL ? ' · ' . ($tripDelta > 0 ? '+' : '') . cz($tripDelta, 0) . ' %' : '' ?></small>
                                <?php elseif (($t['telemetry_bad_point_count'] ?? NULL) !== NULL): ?>
                                    <small><?= (int)$t['telemetry_bad_point_count'] ?> podezřelých bodů</small>
                                <?php else: ?>
                                    <small>automatická kontrola</small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="trip-actions-col">
                            <div class="trip-row-actions">
                                <?php if (($t['start_lat'] ?? null) !== null || ($t['end_lat'] ?? null) !== null || strpos((string)($t['source_format'] ?? ''), 'telemetry_') === 0): ?>
                                    <button type="button" class="icon-action map-action" data-trip-map="<?= (int)$t['id'] ?>" title="Zobrazit trasu" aria-label="Zobrazit trasu na mapě"><i class="bi bi-map"></i></button>
                                <?php endif; ?>
                                <?php if ($isActiveTrip): ?>
                                    <button type="button" class="icon-action" disabled title="Probíhající jízda se aktualizuje automaticky" aria-label="Probíhající jízdu nelze zatím upravit"><i class="bi bi-arrow-repeat"></i></button>
                                    <button type="button" class="icon-action danger-action" disabled title="Probíhající jízdu nelze smazat" aria-label="Probíhající jízdu nelze smazat"><i class="bi bi-trash3"></i></button>
                                <?php else: ?>
                                    <button type="button" class="icon-action" data-trip-edit="<?= (int)$t['id'] ?>" title="Upravit jízdu" aria-label="Upravit jízdu"><i class="bi bi-pencil"></i></button>
                                    <form method="post" action="trip-delete.php" class="trip-delete-inline" data-confirm="Opravdu chcete tuto jízdu trvale smazat? U telemetry jízdy zůstanou raw data zachována, ale jízda se už při synchronizaci ani rebuild procesu znovu nevytvoří.">
                                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                                        <input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
                                        <input type="hidden" name="return_to" value="dashboard">
                                        <button type="submit" class="icon-action danger-action" title="Smazat jízdu" aria-label="Smazat jízdu"><i class="bi bi-trash3"></i></button>
                                    </form>
                                <?php endif; ?>
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
    const energyLocationLabels = <?=json_encode(array_column($chargingTopLocations ?? [], 'label'), JSON_UNESCAPED_UNICODE)?>;
    const energyLocationKwh = <?=json_encode(array_column($chargingTopLocations ?? [], 'kwh'))?>;
    const energyLocationSessions = <?=json_encode(array_column($chargingTopLocations ?? [], 'sessions'))?>;
    const energyLocationCosts = <?=json_encode(array_column($chargingTopLocations ?? [], 'cost'))?>;
    const energyCurrency = <?=json_encode($chargingCurrency === 'CZK' ? 'Kč' : $chargingCurrency, JSON_UNESCAPED_UNICODE)?>;

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

    <?php if ($hasTractionBattery && $chargingHistoryAvailable && $chargingLocationsAvailable): ?>
    const energyLocationCanvas = document.getElementById('energyLocations');
    if (energyLocationCanvas) {
        const locationValueLabels = {
            id: 'locationValueLabels',
            afterDatasetsDraw(chart) {
                const meta = chart.getDatasetMeta(0);
                const ctx = chart.ctx;
                const formatter = new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 1 });
                ctx.save();
                ctx.font = '700 10px Inter, system-ui, sans-serif';
                ctx.fillStyle = chartStrong;
                ctx.textBaseline = 'middle';
                meta.data.forEach((bar, index) => {
                    const value = Number(energyLocationKwh[index] || 0);
                    const text = `${formatter.format(value)} kWh`;
                    const width = ctx.measureText(text).width;
                    const x = Math.min(bar.x + 8, chart.chartArea.right - width);
                    ctx.textAlign = 'left';
                    ctx.fillText(text, Math.max(x, chart.chartArea.left + 6), bar.y);
                });
                ctx.restore();
            }
        };
        new Chart(energyLocationCanvas, {
            type: 'bar',
            plugins: [locationValueLabels],
            data: {
                labels: energyLocationLabels,
                datasets: [{
                    label: 'Dodaná energie',
                    data: energyLocationKwh,
                    backgroundColor: [palette.teal, palette.sky, palette.emerald, palette.amber, palette.coral],
                    borderColor: 'transparent',
                    borderWidth: 0,
                    borderRadius: 8,
                    borderSkipped: false,
                    maxBarThickness: 18
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { right: 42 } },
                interaction: { mode: 'nearest', axis: 'y', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...commonPlugins.tooltip,
                        callbacks: {
                            title(items) { return energyLocationLabels[items[0].dataIndex] || ''; },
                            label(context) {
                                const i = context.dataIndex;
                                const parts = [`${Number(energyLocationKwh[i] || 0).toLocaleString('cs-CZ')} kWh`, `${energyLocationSessions[i] || 0} relací`];
                                const cost = Number(energyLocationCosts[i] || 0);
                                if (cost > 0) parts.push(`${cost.toLocaleString('cs-CZ', { maximumFractionDigits: 0 })} ${energyCurrency}`);
                                return parts;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: chartGrid, drawBorder: false },
                        ticks: { color: chartText, callback: value => `${value} kWh` }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: chartStrong,
                            font: { weight: '600', size: 10 },
                            callback(value) {
                                const label = this.getLabelForValue(value);
                                return label.length > 28 ? `${label.slice(0, 27)}…` : label;
                            }
                        }
                    }
                }
            }
        });
    }
    <?php elseif ($hasTractionBattery && !$chargingHistoryAvailable && $chargeDataAvailable): ?>
    const energyLegacyCanvas = document.getElementById('energyLegacy');
    if (energyLegacyCanvas) {
        new Chart(energyLegacyCanvas, {
            type: 'bar',
            data: {
                labels: ['AC / ostatní', 'Veřejné DC'],
                datasets: [{
                    label: 'Energie',
                    data: [<?=round($homeKwh, 2)?>, <?=round($publicKwh, 2)?>],
                    backgroundColor: [palette.emerald, palette.amber],
                    borderRadius: 9,
                    borderSkipped: false,
                    maxBarThickness: 32
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: commonPlugins.tooltip },
                scales: {
                    x: { beginAtZero: true, grid: { color: chartGrid }, ticks: { color: chartText, callback: value => `${value} kWh` } },
                    y: { grid: { display: false }, ticks: { color: chartStrong } }
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

<script nonce="<?= h(cspNonce()) ?>">
(() => {
    const panel = document.querySelector('[data-live-drive]');
    if (!panel) return;

    const vehicleId = Number(panel.dataset.vehicleId || 0);
    const fields = name => panel.querySelectorAll(`[data-live-field="${name}"]`);
    const number = (value, digits = 0) => value == null || Number.isNaN(Number(value))
        ? '—'
        : new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(Number(value));
    const text = (name, value) => fields(name).forEach(element => { element.textContent = value; });

    const paint = drive => {
        if (!drive) {
            panel.hidden = true;
            panel.classList.add('is-idle');
            return;
        }
        panel.hidden = false;
        panel.classList.remove('is-idle');
        text('current_speed_kmh', number(drive.current_speed_kmh, 0));
        text('distance_km', number(drive.distance_km, 1));
        text('avg_consumption_kwh_100', number(drive.avg_consumption_kwh_100, 1));
        text('current_soc', number(drive.current_soc, 0));
        text('predicted_remaining_range_km', number(drive.predicted_remaining_range_km, 0));
        text('quality_score', drive.quality_score == null ? '—' : String(drive.quality_score));
        text('elapsed_minutes', String(drive.elapsed_minutes || 0));
        text('prediction_confidence_pct', String(drive.prediction_confidence_pct || 0));
        text('start_address', drive.start_address || 'Start');
        text('current_address', drive.current_address || 'aktuální poloha');
        if (drive.last_snapshot_at) {
            const date = new Date(drive.last_snapshot_at.replace(' ', 'T'));
            text('last_snapshot_at', Number.isNaN(date.getTime()) ? drive.last_snapshot_at : date.toLocaleTimeString('cs-CZ'));
        }
    };

    const refresh = async () => {
        if (!vehicleId || document.hidden) return;
        try {
            const response = await fetch(`live-drive.php?vehicle_id=${encodeURIComponent(vehicleId)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) return;
            const data = await response.json();
            paint(data.live_drive || null);
        } catch (error) {
            // Při dočasném výpadku ponecháme poslední známý stav bez blikání UI.
        }
    };

    window.setInterval(refresh, 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

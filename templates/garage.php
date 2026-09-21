<?php
$pageTitle = 'Moje garáž';
$showNavigation = true;
$navTitle = 'Moje garáž';
require __DIR__ . '/partials/header.php';
$canManageGarageVehicles = $app->auth()->canManageVehicles($user);
?>
<main class="wrap garage-page">
    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' ?> shadow-sm" role="alert"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="garage-heading dashboard-hero">
        <div>
            <span class="eyebrow">DIGITAL GARAGE</span>
            <h1>Moje garáž</h1>
            <p>Rychlý přehled všech vozidel, jejich provozu a ekonomiky.</p>
        </div>
        <div class="d-flex align-items-end gap-2 flex-wrap">
            <form method="get" class="garage-year-filter">
                <label class="form-label mb-0">Analytický rok
                    <select class="form-select form-select-sm" name="year" data-auto-submit>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= (int)$year ?>" <?= (int)$year === $selectedYear ? 'selected' : '' ?>><?= (int)$year ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <?php if ($canManageGarageVehicles): ?>
                <a class="btn btn-primary" href="vehicles.php"><i class="bi bi-plus-lg"></i> Přidat vozidlo</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="garage-summary dashboard-summary-grid">
        <div class="metric-card"><small>NÁJEZD <?= (int)$selectedYear ?></small><b><?= cz($totals['distance_km'], 0) ?> km</b><span>celkem v garáži</span></div>
        <div class="metric-card"><small>PROVOZ</small><b><?= cz($totals['operating_cost'], 0) ?> Kč</b><span>energie, servis a další</span></div>
        <div class="metric-card"><small>TCO</small><b><?= cz($totals['full_tco'], 0) ?> Kč</b><span>celkové náklady vlastnictví</span></div>
        <div class="metric-card"><small>VOZIDLA</small><b><?= count($vehicles) ?></b><span>v dostupné garáži</span></div>
    </section>

    <?php if ($comparison): ?>
        <section class="vehicle-card-grid">
            <?php foreach ($comparison as $row):
                $vehicleId = (int)$row['id'];
                $photo = $vehiclePhotos[$vehicleId] ?? null;
                $powertrain = strtoupper((string)$row['powertrain_type']);
                $isActive = isset($activeVehicle['id']) && (int)$activeVehicle['id'] === $vehicleId;
            ?>
                <article class="garage-vehicle-card card <?= $isActive ? 'is-active' : '' ?>">
                    <div class="garage-photo">
                        <?php if ($photo): ?>
                            <img src="media.php?view=<?= (int)$photo['id'] ?>" alt="<?= h($row['name']) ?>">
                        <?php else: ?>
                            <div class="garage-photo-empty"><span>🚙</span><small>Přidejte fotografii vozidla</small></div>
                        <?php endif; ?>
                        <span class="powertrain-badge"><?= h(powertrainLabel($powertrain)) ?></span>
                        <?php if ($canManageGarageVehicles): ?>
                            <a class="garage-edit-button" href="vehicles.php?edit=<?= $vehicleId ?>" title="Upravit vozidlo" aria-label="Upravit <?= h($row['name']) ?>"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if ($isActive): ?><span class="garage-active-badge">Aktivní</span><?php endif; ?>
                    </div>
                    <div class="garage-card-body">
                        <div class="garage-card-title-row">
                            <div><small><?= h((string)($row['registration_plate'] ?? $row['vin'])) ?></small><h2><?= h($row['name']) ?></h2></div>
                            <span class="vehicle-status-dot" title="Vozidlo v garáži"></span>
                        </div>
                        <div class="garage-card-stats">
                            <span><small>Nájezd</small><b><?= cz($row['distance_km'], 0) ?> km</b></span>
                            <span><small>Provoz</small><b><?= cz($row['operating_cost_per_km'], 2) ?> Kč/km</b></span>
                            <span><small>Spotřeba</small><b><?= powertrainHasBattery($powertrain) && $row['consumed_kwh'] > 0
                                    ? cz($row['avg_consumption'], 1) . ' kWh/100 km'
                                    : (powertrainHasFuel($powertrain) && ($row['fuel_consumed_l'] ?? 0) > 0
                                        ? cz($row['avg_fuel_consumption'], 1) . ' ' . h(fuelUnit($powertrain)) . '/100 km'
                                        : '—') ?></b></span>
                        </div>
                        <div class="garage-card-actions">
                            <a class="btn btn-primary" href="index.php?vehicle_id=<?= $vehicleId ?>">Otevřít vozidlo</a>
                            <a class="btn btn-outline-secondary" href="timeline.php?vehicle_id=<?= $vehicleId ?>">Timeline</a>
                            <a class="text-link" href="documents.php?vehicle_id=<?= $vehicleId ?>#vehicle-photos"><?= $photo ? 'Fotografie' : '＋ Přidat foto' ?></a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
        <?php require __DIR__ . '/partials/pagination.php'; ?>
    <?php else: ?>
        <section class="empty-state card">
            <h2>Vaše garáž je prázdná</h2>
            <p>Přidejte první vozidlo a začněte sledovat jízdy, energii a náklady.</p>
            <a class="btn btn-primary" href="<?= $canManageGarageVehicles ? 'vehicles.php' : 'index.php?add_vehicle=1' ?>"><i class="bi bi-plus-lg"></i> Přidat vozidlo</a>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>

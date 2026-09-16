<?php
$pageTitle = 'Vozidla';
$showNavigation = true;
$navTitle = 'Správa vozidel';
require __DIR__ . '/partials/header.php';

$manufacturerLabels = [
    'SKODA' => 'Škoda',
    'KIA' => 'Kia',
    'HYUNDAI' => 'Hyundai',
    'VOLKSWAGEN' => 'Volkswagen',
    'AUDI' => 'Audi',
    'TESLA' => 'Tesla',
    'OTHER' => 'Jiný',
];
$powertrainLabels = [
    'BEV' => 'BEV – elektromobil',
    'PHEV' => 'PHEV – plug-in hybrid',
    'HEV' => 'HEV – hybrid',
    'PETROL' => 'Benzín',
    'DIESEL' => 'Nafta',
    'LPG' => 'LPG',
    'CNG' => 'CNG',
];

?>
<main class="wrap narrow admin-list-page">
  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <section class="card table-card admin-list-card">
    <div class="list-toolbar">
      <div>
        <span class="section-kicker">Digitální garáž</span>
        <h2>🚙 Vozidla</h2>
        <p>Přehled všech vozidel. Detaily a přiřazení uživatelů upravíte v jednom okně.</p>
      </div>
      <?php if ($app->auth()->canManageVehicles($me)): ?><button class="btn primary" type="button" data-vehicle-create>＋ Přidat vozidlo</button><?php endif; ?>
    </div>

    <div class="table-wrap">
      <table class="data-table admin-list-table">
        <thead>
        <tr>
          <th>Vozidlo</th>
          <th>Pohon</th>
          <th>VIN / SPZ</th>
          <th>Uživatelé</th>
          <th>Jízdy</th>
          <th class="actions-col">Akce</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($vehicles as $v):
            $vehicleId = (int)$v['id'];
            $payload = [
                'id' => $vehicleId,
                'name' => (string)$v['name'],
                'vin' => (string)$v['vin'],
                'manufacturer' => (string)($v['manufacturer'] ?? 'OTHER'),
                'powertrain_type' => (string)($v['powertrain_type'] ?? 'BEV'),
                'battery_kwh' => $v['battery_kwh'],
                'battery_nominal_kwh' => $v['battery_nominal_kwh'] ?? $v['battery_kwh'],
                'soh_manual_pct' => $v['soh_manual_pct'] ?? null,
                'home_label' => $v['home_label'] ?? '',
                'fuel_tank_l' => $v['fuel_tank_l'] ?? null,
                'registration_plate' => $v['registration_plate'] ?? '',
                'first_registration_date' => $v['first_registration_date'] ?? '',
                'acquisition_date' => $v['acquisition_date'] ?? '',
                'acquisition_price' => $v['acquisition_price'] ?? null,
                'current_value' => $v['current_value'] ?? null,
                'odometer_km' => $v['odometer_km'] ?? null,
                'user_ids' => $assigned[$vehicleId] ?? [],
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
            $powertrain = (string)($v['powertrain_type'] ?? 'BEV');
        ?>
          <tr>
            <td><div class="table-primary"><strong><?= h($v['name']) ?></strong><small><?= h($manufacturerLabels[strtoupper((string)($v['manufacturer'] ?? 'OTHER'))] ?? (string)$v['manufacturer']) ?></small></div></td>
            <td style="position: relative;"><span class="powertrain-badge"><?= h($powertrainLabels[$powertrain] ?? $powertrain) ?></span></td>
            <td><div class="table-primary"><span><?= h($v['vin']) ?></span><small><?= h((string)($v['registration_plate'] ?? '')) ?: 'Bez SPZ' ?></small></div></td>
            <td><span class="count-badge"><?= count($assigned[$vehicleId] ?? []) ?></span></td>
            <td><span class="count-badge"><?= (int)$v['trip_count'] ?></span></td>
            <td class="actions-col">
              <div class="row-actions">
                <a class="btn btn-compact" href="index.php?vehicle_id=<?= $vehicleId ?>" title="Otevřít dashboard">Dashboard</a>
                <button class="btn btn-compact" type="button" data-vehicle-edit data-vehicle='<?= h((string)$json) ?>'>Upravit</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$vehicles): ?>
          <tr><td colspan="6" class="empty-table">Zatím nejsou založena žádná vozidla.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<div class="app-modal" id="vehicleEditorModal" hidden aria-hidden="true">
  <div class="app-modal-backdrop" data-vehicle-close></div>
  <section class="app-modal-dialog admin-modal-dialog admin-modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="vehicleEditorTitle">
    <header class="app-modal-head">
      <div>
        <span class="section-kicker" id="vehicleEditorKicker">Nové vozidlo</span>
        <h2 id="vehicleEditorTitle">Přidat vozidlo</h2>
        <p id="vehicleEditorSubtitle">Základní údaje, provozní parametry a přístup uživatelů na jednom místě.</p>
      </div>
      <button class="modal-close" type="button" data-vehicle-close aria-label="Zavřít">×</button>
    </header>

    <form method="post" class="app-modal-body" id="vehicleEditorForm">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" id="vehicleAction" value="create">
      <input type="hidden" name="id" id="vehicleId" value="">

      <div class="admin-modal-grid vehicle-modal-grid">
        <div class="form-panel">
          <h3>Základní údaje</h3>
          <div class="modal-field-grid">
            <label class="span-2"><span>Název</span><input id="vehicleName" name="name" placeholder="Škoda Elroq 85" required></label>
            <label><span>Výrobce</span><select id="vehicleManufacturer" name="manufacturer" required><?php foreach ($manufacturerLabels as $code => $label): ?><option value="<?= h($code) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Typ pohonu</span><select id="vehiclePowertrain" name="powertrain_type"><?php foreach ($powertrainLabels as $code => $label): ?><option value="<?= h($code) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
            <label class="span-2"><span>VIN</span><input id="vehicleVin" name="vin" maxlength="32" required></label>
            <label><span>SPZ</span><input id="vehiclePlate" name="registration_plate" maxlength="32"></label>
            <label><span>Domácí lokalita</span><input id="vehicleHome" name="home_label" placeholder="Vilémov 173"></label>
          </div>
        </div>

        <div class="form-panel">
          <h3>Technické údaje</h3>
          <div class="modal-field-grid">
            <label data-electric-field><span>Využitelná kapacita (kWh)</span><input id="vehicleBattery" name="battery_kwh" type="number" step="0.1" min="0"></label>
            <label data-electric-field><span>Nominální kapacita (kWh)</span><input id="vehicleBatteryNominal" name="battery_nominal_kwh" type="number" step="0.1" min="0"></label>
            <label data-electric-field><span>SoH z diagnostiky (%)</span><input id="vehicleSoh" name="soh_manual_pct" type="number" step="0.1" min="50" max="110"></label>
            <label data-fuel-field><span>Objem nádrže (l)</span><input id="vehicleTank" name="fuel_tank_l" type="number" step="0.1" min="0"></label>
            <label><span>Aktuální tachometr (km)</span><input id="vehicleOdometer" name="odometer_km" type="number" step="0.1" min="0"></label>
          </div>
          <small class="form-note" data-electric-field>Přesná hodnota SoH z BMS/diagnostiky má přednost před odhadem z jízd.</small>
        </div>

        <div class="form-panel">
          <h3>Pořízení a hodnota</h3>
          <div class="modal-field-grid">
            <label><span>První registrace</span><input id="vehicleFirstRegistration" name="first_registration_date" type="date"></label>
            <label><span>Datum pořízení</span><input id="vehicleAcquisitionDate" name="acquisition_date" type="date"></label>
            <label><span>Pořizovací cena (Kč)</span><input id="vehicleAcquisitionPrice" name="acquisition_price" type="number" step="1" min="0"></label>
            <label><span>Aktuální hodnota (Kč)</span><input id="vehicleCurrentValue" name="current_value" type="number" step="1" min="0"></label>
          </div>
        </div>

        <div class="form-panel">
          <h3>Přiřazení uživatelů</h3>
          <p class="form-panel-help"><?= $app->auth()->isAdmin($me) ? 'Správci vozidel, kterým administrátor předává vozidlo do správy.' : 'Vaši řidiči, kterým povolujete používat toto vozidlo.' ?></p>
          <label><span>Změna přiřazení platí od</span><input type="date" name="assignment_effective_date" value="<?= date('Y-m-d') ?>"></label>
          <div class="assignment-list assignment-list-compact">
            <?php foreach ($users as $u): ?>
              <label class="check assignment">
                <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" data-vehicle-user>
                <span><?= h($u['name']) ?><small><?= h($u['email']) ?></small></span>
              </label>
            <?php endforeach; ?>
            <?php if (!$users): ?><p class="empty-assignment">Nejsou dostupní žádní aktivní uživatelé.</p><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="modal-actions admin-modal-actions">
        <div class="danger-zone" id="vehicleDeleteZone" hidden>
          <?php if ($app->auth()->isAdmin($me)): ?>
            <button class="btn danger" type="submit" name="action" value="delete" id="vehicleDeleteButton">Smazat vozidlo</button>
          <?php endif; ?>
        </div>
        <div class="modal-actions-right">
          <button type="button" class="btn" data-vehicle-close>Zrušit</button>
          <button type="submit" class="btn primary" id="vehicleSubmit">Přidat vozidlo</button>
        </div>
      </div>
    </form>
  </section>
</div>

<script defer src="assets/pages/vehicles.js?v=<?= (int)@filemtime(__DIR__ . '/../public/assets/pages/vehicles.js') ?>"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>

<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vozidla – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=20260915-nav">
</head>
<body>
<?php $navTitle = 'Správa vozidel'; require __DIR__ . '/partials/navigation.php'; ?>
<main class="wrap narrow"><?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <section class="card admin-card"><h2>➕ Přidat vozidlo</h2>
    <form method="post" class="vehicle-create-grid"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                                          value="create"><label>Název<input
          name="name" placeholder="Škoda Elroq 85" required></label><label>Výrobce<select name="manufacturer" required><option value="SKODA">Škoda</option><option value="KIA">Kia</option><option value="HYUNDAI">Hyundai</option><option value="VOLKSWAGEN">Volkswagen</option><option value="AUDI">Audi</option><option value="TESLA">Tesla</option><option value="OTHER">Jiný</option></select></label><label>VIN<input name="vin" maxlength="32" required></label><label>Typ pohonu<select name="powertrain_type"><option value="BEV">BEV – elektromobil</option><option value="PHEV">PHEV – plug-in hybrid</option><option value="HEV">HEV – hybrid</option><option value="PETROL">Benzín</option><option value="DIESEL">Diesel</option><option value="LPG">LPG</option><option value="CNG">CNG</option></select></label><label>Aktuálně
        využitelná kapacita (kWh)<input name="battery_kwh" type="number" step="0.1" min="0" value="77"></label><label>Nominální kapacita
        nového vozu (kWh)<input name="battery_nominal_kwh" type="number" step="0.1" min="0" value="77"></label><label>SoH z diagnostiky
        (%)<input name="soh_manual_pct" type="number" step="0.1" min="50" max="110" placeholder="např. 96,5"></label><label>Domácí lokalita<input
          name="home_label" placeholder="Vilémov 173"></label>
      <label>Objem nádrže (l)<input name="fuel_tank_l" type="number" step="0.1" min="0"></label><label>SPZ<input name="registration_plate" maxlength="32"></label><label>První registrace<input name="first_registration_date" type="date"></label><label>Datum pořízení<input name="acquisition_date" type="date"></label><label>Pořizovací cena (Kč)<input name="acquisition_price" type="number" step="1" min="0"></label><label>Aktuální hodnota (Kč)<input name="current_value" type="number" step="1" min="0"></label><label>Aktuální tachometr (km)<input name="odometer_km" type="number" step="0.1" min="0"></label><button class="btn primary">Přidat vozidlo</button>
    </form>
  </section>
  <?php foreach ($vehicles as $v): ?>
    <section class="card admin-card vehicle-admin">
    <div class="admin-title">
      <div><h2>🚙 <?= h($v['name']) ?></h2>
        <p>VIN <?= h($v['vin']) ?> · <?= $v['trip_count'] ?>
          jízd<?php if ($v['soh_manual_pct'] !== NULL): ?> · SoH <?= cz((float)$v['soh_manual_pct'], 1) ?> %<?php endif; ?></p></div>
      <a class="btn" href="index.php?vehicle_id=<?= $v['id'] ?>">Otevřít dashboard</a></div>
    <div class="admin-columns">
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="update"><input type="hidden"
                                                                                                                                    name="id"
                                                                                                                                    value="<?= $v['id'] ?>"><label>Název<input
            name="name" value="<?= h($v['name']) ?>" required></label><label>Výrobce<select name="manufacturer" required><?php foreach (['SKODA'=>'Škoda','KIA'=>'Kia','HYUNDAI'=>'Hyundai','VOLKSWAGEN'=>'Volkswagen','AUDI'=>'Audi','TESLA'=>'Tesla','OTHER'=>'Jiný'] as $code=>$label): ?><option value="<?= h($code) ?>" <?= strtoupper((string)($v['manufacturer'] ?? '')) === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label><label>VIN<input name="vin" value="<?= h($v['vin']) ?>" required></label><label>Typ pohonu<select name="powertrain_type"><?php foreach (['BEV'=>'BEV – elektromobil','PHEV'=>'PHEV – plug-in hybrid','HEV'=>'HEV – hybrid','PETROL'=>'Benzín','DIESEL'=>'Diesel','LPG'=>'LPG','CNG'=>'CNG'] as $code=>$label): ?><option value="<?= h($code) ?>" <?= ($v['powertrain_type'] ?? 'BEV') === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label><label>Aktuálně
          využitelná kapacita (kWh)<input name="battery_kwh" type="number" step="0.1" value="<?= h((string)$v['battery_kwh']) ?>"></label><label>Nominální kapacita nového vozu (kWh)<input name="battery_nominal_kwh" type="number"
                                                                                                             step="0.1"
                                                                                                             value="<?= h((string)($v['battery_nominal_kwh'] ?? $v['battery_kwh'])) ?>"></label><label>SoH z diagnostiky
          (%)<input name="soh_manual_pct" type="number" step="0.1" min="50" max="110" value="<?= h((string)($v['soh_manual_pct'] ?? '')) ?>"><small>Volitelné.
            Přesná hodnota z BMS/diagnostiky má přednost před odhadem z jízd.</small></label><label>Domácí lokalita<input name="home_label"
                                                                                                                          value="<?= h($v['home_label']) ?>"></label>
        <label>Objem nádrže (l)<input name="fuel_tank_l" type="number" step="0.1" min="0" value="<?= h((string)($v['fuel_tank_l'] ?? '')) ?>"></label><label>SPZ<input name="registration_plate" value="<?= h((string)($v['registration_plate'] ?? '')) ?>"></label><label>První registrace<input name="first_registration_date" type="date" value="<?= h((string)($v['first_registration_date'] ?? '')) ?>"></label><label>Datum pořízení<input name="acquisition_date" type="date" value="<?= h((string)($v['acquisition_date'] ?? '')) ?>"></label><label>Pořizovací cena (Kč)<input name="acquisition_price" type="number" step="1" min="0" value="<?= h((string)($v['acquisition_price'] ?? '')) ?>"></label><label>Aktuální hodnota (Kč)<input name="current_value" type="number" step="1" min="0" value="<?= h((string)($v['current_value'] ?? '')) ?>"></label><label>Aktuální tachometr (km)<input name="odometer_km" type="number" step="0.1" min="0" value="<?= h((string)($v['odometer_km'] ?? '')) ?>"></label><button class="btn primary">Uložit údaje</button>
      </form>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="assign"><input type="hidden"
                                                                                                                                    name="vehicle_id"
                                                                                                                                    value="<?= $v['id'] ?>"><b>Přiřazení
          uživatelům</b><?php foreach ($users as $u): ?><label class="check assignment"><input type="checkbox" name="user_ids[]"
                                                                                               value="<?= $u['id'] ?>" <?= in_array((int)$u['id'], $assigned[(int)$v['id']] ?? [], TRUE) ? 'checked' : '' ?>><span><?= h($u['name']) ?><small><?= h($u['email']) ?></small></span>
          </label><?php endforeach; ?>
        <button class="btn primary">Uložit přiřazení</button>
      </form>
    </div>
    <?php if ($app->auth()->isAdmin($me)): ?>
      <form method="post" onsubmit="return confirm('Smazat vozidlo včetně všech importovaných jízd?')"><input type="hidden" name="csrf"
                                                                                                              value="<?= h(csrfToken()) ?>"><input
        type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>">
      <button class="btn danger">Smazat vozidlo</button></form><?php endif; ?></section><?php endforeach; ?></main>
</body>
</html>

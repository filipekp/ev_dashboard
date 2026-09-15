<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Provoz vozidla – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=20260915-nav">
</head>
<body>
<?php
$powertrain = strtoupper((string)($vehicle['powertrain_type'] ?? 'BEV'));
$hasTractionBattery = in_array($powertrain, ['BEV', 'PHEV'], TRUE);
$hasFuelSystem = in_array($powertrain, ['PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'], TRUE);
$fuelEnergyType = [
    'DIESEL' => 'diesel',
    'LPG' => 'lpg',
    'CNG' => 'cng',
][$powertrain] ?? 'petrol';
?>
<?php $navTitle = 'Provoz vozidla'; require __DIR__ . '/partials/navigation.php'; ?>
<main class="wrap operations-page">
  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <section class="card operations-head">
    <div>
      <small>PROVOZNÍ EVIDENCE</small>
      <h1><?= h($vehicle['name']) ?></h1>
      <p><?= h((string)$vehicle['powertrain_type']) ?> · VIN <?= h($vehicle['vin']) ?></p>
    </div>
    <form method="get" class="vehicle-switch">
      <select name="vehicle_id" onchange="this.form.submit()">
        <?php foreach ($vehicles as $item): ?>
          <option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)$vehicle['id'] ? 'selected' : '' ?>>
            <?= h($item['name']) ?> · <?= h($item['vin']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </section>

  <section class="kpis operations-kpis">
    <div class="card kpi"><small><?= $hasTractionBattery && !$hasFuelSystem ? 'ELEKTŘINA' : ($hasFuelSystem && !$hasTractionBattery ? 'PALIVO' : 'ENERGIE / PALIVO') ?></small><strong><?= cz($summary['energy_cost'], 0) ?> <em>Kč</em></strong></div>
    <div class="card kpi"><small>SERVIS</small><strong><?= cz($summary['service_cost'], 0) ?> <em>Kč</em></strong></div>
    <div class="card kpi"><small>OSTATNÍ NÁKLADY</small><strong><?= cz($summary['other_cost'], 0) ?> <em>Kč</em></strong></div>
    <div class="card kpi"><small>CELKEM</small><strong><?= cz($summary['total_cost'], 0) ?> <em>Kč</em></strong></div>
    <div class="card kpi"><small>NÁKLADY / KM</small><strong class="green"><?= cz($summary['cost_per_km'], 2) ?> <em>Kč/km</em></strong></div>
  </section>

  <section class="grid2 operations-forms">
    <div class="card">
      <h2><?= $hasTractionBattery && !$hasFuelSystem ? '⚡ Nabíjení' : ($hasFuelSystem && !$hasTractionBattery ? '⛽ Tankování' : '⛽ Tankování / nabíjení') ?></h2>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_energy">
        <label>Datum a čas<input type="datetime-local" name="occurred_at" value="<?= date('Y-m-d\TH:i') ?>" required></label>
        <?php if ($hasTractionBattery && $hasFuelSystem): ?>
          <div class="form-grid-2">
            <label>Událost<select name="entry_type" id="operationEntryType"><option value="charging">Nabíjení</option><option value="fueling">Tankování</option></select></label>
            <label>Energie / palivo<select name="energy_type" id="operationEnergyType"><option value="electricity">Elektřina</option><option value="petrol">Benzín</option><option value="diesel">Nafta</option><option value="lpg">LPG</option><option value="cng">CNG</option></select></label>
          </div>
        <?php elseif ($hasTractionBattery): ?>
          <input type="hidden" name="entry_type" value="charging">
          <input type="hidden" name="energy_type" value="electricity">
        <?php else: ?>
          <input type="hidden" name="entry_type" value="fueling">
          <input type="hidden" name="energy_type" value="<?= h($fuelEnergyType) ?>">
        <?php endif; ?>
        <div class="form-grid-2">
          <label>Množství <?= $hasTractionBattery && !$hasFuelSystem ? '(kWh)' : ($hasFuelSystem && !$hasTractionBattery ? '(l / kg)' : '') ?><input type="number" step="0.001" min="0" name="quantity" required></label>
          <label>Cena za jednotku<input type="number" step="0.01" min="0" name="unit_price"></label>
        </div>
        <div class="form-grid-2">
          <label>Celková cena<input type="number" step="0.01" min="0" name="total_price"></label>
          <label>Tachometr (km)<input type="number" step="0.1" min="0" name="odometer_km"></label>
        </div>
        <label>Stanice / místo<input name="station"></label>
        <label>Poznámka<textarea name="note" rows="2"></textarea></label>
        <button class="btn primary">Uložit</button>
      </form>
    </div>

    <div class="card">
      <h2>🔧 Servisní záznam</h2>
      <form method="post" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_service">
        <div class="form-grid-2">
          <label>Datum<input type="date" name="serviced_at" value="<?= date('Y-m-d') ?>" required></label>
          <label>Kategorie<input name="category" value="servis" required></label>
        </div>
        <label>Název úkonu<input name="title" placeholder="Výměna oleje / brzdová kapalina / STK" required></label>
        <div class="form-grid-2">
          <label>Servis / dodavatel<input name="provider"></label>
          <label>Tachometr (km)<input type="number" step="0.1" min="0" name="odometer_km"></label>
        </div>
        <label>Cena<input type="number" step="0.01" min="0" name="cost"></label>
        <label>Poznámka<textarea name="note" rows="2"></textarea></label>
        <label>Příloha <small>PDF/JPG/PNG/WEBP, max. 10 MB</small><input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
        <button class="btn primary">Uložit servis</button>
      </form>
    </div>

    <div class="card">
      <h2>💸 Ostatní náklad</h2>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_expense">
        <div class="form-grid-2">
          <label>Datum<input type="date" name="occurred_at" value="<?= date('Y-m-d') ?>" required></label>
          <label>Kategorie<select name="category"><option value="insurance">Pojištění</option><option value="tires">Pneumatiky</option><option value="parking">Parkování</option><option value="toll">Dálniční poplatky</option><option value="accessories">Příslušenství</option><option value="other">Ostatní</option></select></label>
        </div>
        <label>Název<input name="title" required></label>
        <div class="form-grid-2">
          <label>Částka<input type="number" step="0.01" min="0" name="amount" required></label>
          <label>Tachometr (km)<input type="number" step="0.1" min="0" name="odometer_km"></label>
        </div>
        <label>Poznámka<textarea name="note" rows="2"></textarea></label>
        <button class="btn primary">Uložit náklad</button>
      </form>
    </div>

    <div class="card">
      <h2>⏰ Připomínka</h2>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_reminder">
        <label>Název<input name="title" placeholder="STK / výměna oleje / servis" required></label>
        <label>Kategorie<input name="category" value="servis" required></label>
        <div class="form-grid-2">
          <label>Termín<input type="date" name="due_date"></label>
          <label>nebo při km<input type="number" step="1" min="0" name="due_odometer_km"></label>
        </div>
        <label>Poznámka<textarea name="note" rows="2"></textarea></label>
        <button class="btn primary">Přidat připomínku</button>
      </form>
    </div>
  </section>

  <section class="card operations-section">
    <h2>⏰ Připomínky</h2>
    <div class="table-scroll">
      <table class="data-table"><thead><tr><th>Stav</th><th>Připomínka</th><th>Termín</th><th>Km</th><th></th></tr></thead><tbody>
      <?php foreach ($reminders as $row): ?>
        <tr class="<?= $row['completed_at'] ? 'muted-row' : '' ?>">
          <td><?= $row['completed_at'] ? '✓ Hotovo' : 'Aktivní' ?></td><td><b><?= h($row['title']) ?></b><br><small><?= h($row['category']) ?></small></td>
          <td><?= h((string)($row['due_date'] ?? '—')) ?></td><td><?= $row['due_odometer_km'] !== null ? cz((float)$row['due_odometer_km'], 0) : '—' ?></td>
          <td><?php if (!$row['completed_at']): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="complete_reminder"><input type="hidden" name="reminder_id" value="<?= (int)$row['id'] ?>"><button class="btn">Hotovo</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  </section>

  <section class="card operations-section">
    <h2>📒 Kniha jízd</h2>
    <div class="table-scroll">
      <table class="data-table"><thead><tr><th>Datum</th><th>Trasa</th><th>Km</th><th>Účel</th><th>Poznámka</th><th></th></tr></thead><tbody>
      <?php foreach ($tripLog as $trip): ?>
        <?php $tripFormId = 'trip-form-' . (int)$trip['id']; ?>
        <tr>
          <td><?= h(date('d.m.Y H:i', strtotime($trip['started_at']))) ?></td>
          <td><?= h(displayRoute($trip['start_address'], $trip['end_address'])) ?></td>
          <td><?= cz((float)$trip['distance_km'], 1) ?></td>
          <td>
            <select name="classification" form="<?= h($tripFormId) ?>">
              <option value="">—</option>
              <option value="private" <?= $trip['classification'] === 'private' ? 'selected' : '' ?>>Soukromá</option>
              <option value="business" <?= $trip['classification'] === 'business' ? 'selected' : '' ?>>Služební</option>
              <option value="commute" <?= $trip['classification'] === 'commute' ? 'selected' : '' ?>>Dojíždění</option>
              <option value="other" <?= $trip['classification'] === 'other' ? 'selected' : '' ?>>Ostatní</option>
            </select>
          </td>
          <td><input name="trip_note" form="<?= h($tripFormId) ?>" value="<?= h((string)($trip['trip_note'] ?? '')) ?>"></td>
          <td>
            <form method="post" id="<?= h($tripFormId) ?>">
              <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="action" value="update_trip">
              <input type="hidden" name="trip_id" value="<?= (int)$trip['id'] ?>">
              <button class="btn">Uložit</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  </section>

  <section class="grid2">
    <div class="card operations-section"><h2>⛽ Historie energie a paliva</h2><div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Typ</th><th>Množství</th><th>Cena</th><th>Místo</th></tr></thead><tbody><?php foreach ($energyEntries as $row): ?><tr><td><?= h(date('d.m.Y H:i', strtotime($row['occurred_at']))) ?></td><td><?= h($row['energy_type']) ?></td><td><?= cz((float)$row['quantity'], 2) ?> <?= h($row['unit']) ?></td><td><?= $row['total_price'] !== null ? cz((float)$row['total_price'], 0).' Kč' : '—' ?></td><td><?= h((string)($row['station'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <div class="card operations-section"><h2>🔧 Servisní historie</h2><div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Úkon</th><th>Km</th><th>Cena</th><th>Přílohy</th></tr></thead><tbody><?php foreach ($services as $row): ?><tr><td><?= h(date('d.m.Y', strtotime($row['serviced_at']))) ?></td><td><b><?= h($row['title']) ?></b><br><small><?= h($row['category']) ?></small></td><td><?= $row['odometer_km'] !== null ? cz((float)$row['odometer_km'], 0) : '—' ?></td><td><?= $row['cost'] !== null ? cz((float)$row['cost'], 0).' Kč' : '—' ?></td><td><?php foreach ($serviceAttachments[(int)$row['id']] ?? [] as $attachment): ?><a href="attachment.php?id=<?= (int)$attachment['id'] ?>"><?= h($attachment['original_name']) ?></a><br><?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  </section>

  <section class="card operations-section"><h2>💸 Historie ostatních nákladů</h2><div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Kategorie</th><th>Název</th><th>Částka</th><th>Km</th></tr></thead><tbody><?php foreach ($expenses as $row): ?><tr><td><?= h(date('d.m.Y', strtotime($row['occurred_at']))) ?></td><td><?= h($row['category']) ?></td><td><?= h($row['title']) ?></td><td><?= cz((float)$row['amount'], 0) ?> Kč</td><td><?= $row['odometer_km'] !== null ? cz((float)$row['odometer_km'], 0) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
<?php if ($hasTractionBattery && $hasFuelSystem): ?>
<script>
  const operationEntryType = document.getElementById('operationEntryType');
  const operationEnergyType = document.getElementById('operationEnergyType');
  if (operationEntryType && operationEnergyType) {
    operationEntryType.addEventListener('change', () => {
      operationEnergyType.value = operationEntryType.value === 'charging' ? 'electricity' : <?= json_encode($fuelEnergyType) ?>;
    });
  }
</script>
<?php endif; ?>
</body>
</html>

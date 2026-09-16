<?php
$pageTitle = 'Provoz vozidla';
$showNavigation = true;
$navTitle = 'Provoz vozidla';
require __DIR__ . '/partials/header.php';
?>
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
<main class="wrap operations-page">
  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <section class="card operations-head">
    <div>
      <small>PROVOZNÍ EVIDENCE</small>
      <h1><?= h($vehicle['name']) ?></h1>
      <p><?= h(powertrainLabel($powertrain)) ?> · VIN <?= h($vehicle['vin']) ?></p>
    </div>
    <div class="active-vehicle-note"><span>✓</span><div><small>AKTIVNÍ VOZIDLO</small><b>Řídí se výběrem v levém panelu</b></div></div>
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

  <section class="card operations-section trip-book-section" id="trip-book">
    <div class="section-heading-row">
      <div><small>KNIHA JÍZD</small><h2>📒 Evidence jízd</h2><p>Jízdy můžete zapisovat ručně, předvyplnit z importu a kdykoliv později upravit.</p></div>
      <button type="button" class="btn primary" data-trip-create>＋ Přidat jízdu</button>
    </div>

    <div class="trip-book-history">
      <?php if ($tripBook): ?>
      <div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Trasa</th><th>Km</th><th>Typ</th><th>Účel</th><th>Zdroj</th><th></th></tr></thead><tbody>
      <?php foreach ($tripBook as $trip): ?>
        <tr>
          <td><?= h(date('d.m.Y H:i', strtotime($trip['started_at']))) ?></td>
          <td><b><?= h(displayRoute($trip['start_address'], $trip['end_address'])) ?></b></td>
          <td><?= cz((float)$trip['distance_km'], 1) ?></td>
          <td><?= h((string)($trip['classification'] ?: '—')) ?></td>
          <td><?= h((string)($trip['purpose'] ?: '—')) ?></td>
          <td><?= $trip['source_trip_id'] ? '✦ Import #' . (int)$trip['source_trip_id'] : 'Ručně' ?></td>
          <td><button type="button" class="btn trip-edit-button" data-trip-edit='<?= h(json_encode([
            "id" => (int)$trip["id"], "source_trip_id" => $trip["source_trip_id"] ? (int)$trip["source_trip_id"] : 0,
            "started_at" => date("Y-m-d\\TH:i", strtotime($trip["started_at"])),
            "ended_at" => $trip["ended_at"] ? date("Y-m-d\\TH:i", strtotime($trip["ended_at"])) : "",
            "start_address" => (string)$trip["start_address"], "end_address" => (string)$trip["end_address"],
            "distance_km" => (string)$trip["distance_km"], "start_odometer_km" => (string)($trip["start_odometer_km"] ?? ""),
            "end_odometer_km" => (string)($trip["end_odometer_km"] ?? ""), "classification" => (string)($trip["classification"] ?? ""),
            "purpose" => (string)($trip["purpose"] ?? ""), "trip_note" => (string)($trip["note"] ?? "")
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>Upravit</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?><div class="empty-state-inline"><b>Kniha jízd je zatím prázdná.</b><br>První jízdu můžete zapsat ručně nebo ji předvyplnit z již importovaných dat.<br><button type="button" class="btn primary empty-action" data-trip-create>＋ Zapsat první jízdu</button></div><?php endif; ?>
    </div>
  </section>

  <div class="app-modal" id="tripModal" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-dialog trip-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tripModalTitle">
      <div class="app-modal-head">
        <div><small>KNIHA JÍZD</small><h2 id="tripModalTitle">Přidat jízdu</h2><p id="tripModalSubtitle">Zapište jízdu ručně nebo použijte data z importu.</p></div>
        <button type="button" class="modal-close" data-modal-close aria-label="Zavřít">×</button>
      </div>
      <div class="app-modal-body">
        <div class="trip-source-panel">
          <div><b>✦ Předvyplnit z importované jízdy</b><small>Volitelné – vybraná data můžete před uložením libovolně upravit.</small></div>
          <select id="tripImportSource">
            <option value="">Nevybráno – ruční záznam</option>
            <?php foreach ($importedTrips as $trip): ?>
              <option value="<?= (int)$trip['id'] ?>" data-trip='<?= h(json_encode([
                "id" => (int)$trip["id"], "started_at" => date("Y-m-d\\TH:i", strtotime($trip["started_at"])),
                "ended_at" => $trip["ended_at"] ? date("Y-m-d\\TH:i", strtotime($trip["ended_at"])) : "",
                "start_address" => (string)$trip["start_address"], "end_address" => (string)$trip["end_address"],
                "distance_km" => (string)$trip["distance_km"], "start_odometer_km" => (string)($trip["start_odometer_km"] ?? ""),
                "end_odometer_km" => (string)($trip["end_odometer_km"] ?? ""), "classification" => (string)($trip["classification"] ?? ""),
                "trip_note" => (string)($trip["trip_note"] ?? "")
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><?= h(date('d.m.Y H:i', strtotime($trip['started_at']))) ?> · <?= h(displayRoute($trip['start_address'], $trip['end_address'])) ?> · <?= cz((float)$trip['distance_km'], 1) ?> km</option>
            <?php endforeach; ?>
          </select>
        </div>
        <form method="post" class="stack" id="tripModalForm">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="add_trip_book" id="tripFormAction">
          <input type="hidden" name="entry_id" value="0" id="tripEntryId">
          <input type="hidden" name="source_trip_id" value="0" id="tripSourceId">
          <div class="form-grid-2">
            <label>Začátek<input type="datetime-local" name="started_at" id="tripStartedAt" required></label>
            <label>Konec<input type="datetime-local" name="ended_at" id="tripEndedAt"></label>
          </div>
          <div class="form-grid-2">
            <label>Odkud<input name="start_address" id="tripStartAddress" placeholder="Olomouc" required></label>
            <label>Kam<input name="end_address" id="tripEndAddress" placeholder="Praha" required></label>
          </div>
          <div class="form-grid-3">
            <label>Vzdálenost (km)<input type="number" step="0.01" min="0" name="distance_km" id="tripDistance" required></label>
            <label>Počáteční km<input type="number" step="0.1" min="0" name="start_odometer_km" id="tripStartOdometer"></label>
            <label>Konečné km<input type="number" step="0.1" min="0" name="end_odometer_km" id="tripEndOdometer"></label>
          </div>
          <div class="form-grid-2">
            <label>Typ jízdy<select name="classification" id="tripClassification"><option value="">—</option><option value="private">Soukromá</option><option value="business">Služební</option><option value="commute">Dojíždění</option><option value="other">Ostatní</option></select></label>
            <label>Účel cesty<input name="purpose" id="tripPurpose" placeholder="Schůzka, cesta do práce…"></label>
          </div>
          <label>Poznámka<textarea name="trip_note" id="tripNote" rows="3"></textarea></label>
          <div class="modal-actions"><button type="button" class="btn" data-modal-close>Zrušit</button><button class="btn primary" id="tripSubmitButton">Uložit jízdu</button></div>
        </form>
      </div>
    </div>
  </div>

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

<script>
(() => {
  const modal = document.getElementById('tripModal');
  const form = document.getElementById('tripModalForm');
  if (!modal || !form) return;
  const fields = {
    action: document.getElementById('tripFormAction'), entryId: document.getElementById('tripEntryId'), sourceId: document.getElementById('tripSourceId'),
    startedAt: document.getElementById('tripStartedAt'), endedAt: document.getElementById('tripEndedAt'), startAddress: document.getElementById('tripStartAddress'),
    endAddress: document.getElementById('tripEndAddress'), distance: document.getElementById('tripDistance'), startOdometer: document.getElementById('tripStartOdometer'),
    endOdometer: document.getElementById('tripEndOdometer'), classification: document.getElementById('tripClassification'), purpose: document.getElementById('tripPurpose'), note: document.getElementById('tripNote')
  };
  const source = document.getElementById('tripImportSource');
  const title = document.getElementById('tripModalTitle');
  const subtitle = document.getElementById('tripModalSubtitle');
  const submit = document.getElementById('tripSubmitButton');
  let lastFocus = null;
  const localNow = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0,16); };
  const fill = (data = {}) => {
    fields.startedAt.value = data.started_at || localNow(); fields.endedAt.value = data.ended_at || '';
    fields.startAddress.value = data.start_address || ''; fields.endAddress.value = data.end_address || ''; fields.distance.value = data.distance_km || '';
    fields.startOdometer.value = data.start_odometer_km || ''; fields.endOdometer.value = data.end_odometer_km || '';
    fields.classification.value = data.classification || ''; fields.purpose.value = data.purpose || ''; fields.note.value = data.trip_note || '';
  };
  const open = (mode, data = {}) => {
    lastFocus = document.activeElement; form.reset(); source.value = ''; fields.sourceId.value = 0;
    if (mode === 'edit') {
      title.textContent = 'Upravit jízdu'; subtitle.textContent = 'Upravte údaje evidované jízdy.'; submit.textContent = 'Uložit změny';
      fields.action.value = 'update_trip_book'; fields.entryId.value = data.id || 0; fields.sourceId.value = data.source_trip_id || 0; fill(data);
      if (data.source_trip_id) source.value = String(data.source_trip_id);
    } else {
      title.textContent = 'Přidat jízdu'; subtitle.textContent = 'Zapište jízdu ručně nebo použijte data z importu.'; submit.textContent = 'Uložit jízdu';
      fields.action.value = 'add_trip_book'; fields.entryId.value = 0; fill();
    }
    modal.hidden = false; modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); setTimeout(() => fields.startedAt.focus(), 30);
  };
  const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); if (lastFocus) lastFocus.focus(); };
  document.querySelectorAll('[data-trip-create]').forEach(el => el.addEventListener('click', () => open('create')));
  document.querySelectorAll('[data-trip-edit]').forEach(el => el.addEventListener('click', () => { try { open('edit', JSON.parse(el.dataset.tripEdit)); } catch(e) {} }));
  document.querySelectorAll('[data-modal-close]').forEach(el => el.addEventListener('click', close));
  source.addEventListener('change', () => {
    const option = source.options[source.selectedIndex]; fields.sourceId.value = source.value || 0;
    if (!source.value || !option.dataset.trip) return;
    try { const data = JSON.parse(option.dataset.trip); fill(data); fields.sourceId.value = data.id || 0; } catch(e) {}
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

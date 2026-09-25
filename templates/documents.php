<?php
$pageTitle = 'Dokumenty';
$showNavigation = true;
$navTitle = 'Dokumenty';
$runHistory = isset($runHistory) && is_array($runHistory) ? $runHistory : [];
$photos = isset($photos) && is_array($photos) ? $photos : [];
$linkedOperationCount = isset($linkedOperationCount) ? (int)$linkedOperationCount : 0;
$aiAvailable = !empty($aiAvailable);
require __DIR__ . '/partials/header.php';
?>
<main class="wrap documents-page">
  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <section class="card document-hero">
    <div>
      <small>AI INBOX</small>
      <h1>📄 Dokumenty · <?= h($vehicle['name']) ?></h1>
      <p>Nahrajte účtenku, fakturu za nabíjení nebo servisní doklad. Data se vždy nejdříve zobrazí ke kontrole.</p>
    </div>
    <div class="document-ai-badge">
      AI: <b><?= h($aiProvider) ?></b>
      <?php if (!$aiAvailable): ?><small>není aktivní</small><?php endif; ?>
    </div>
  </section>

  <?php $vehicleWorkspaceTab = 'documents'; require __DIR__ . '/partials/vehicle-workspace.php'; ?>

  <section class="grid2 document-upload-grid">
    <div class="card">
      <h2>✨ Vytěžit nový doklad</h2>
      <form method="post" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="upload_document">
        <label>
          Dokument
          <input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp,application/json,text/plain" required>
        </label>
        <p class="form-help">PDF/JPG/PNG/WebP do 20 MB. Stejný soubor se ukládá jen jednou; další pokusy spustíte akcí „Vytěžit znovu“ v archivu.</p>
        <button class="btn primary">Nahrát a analyzovat</button>
      </form>
    </div>

    <div class="card" id="vehicle-photos">
      <h2>📸 Fotografie vozidla</h2>
      <form method="post" action="media.php" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="upload_photo">
        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
        <label>
          Fotografie
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
        </label>
        <label>
          Popisek
          <input name="caption" maxlength="190" placeholder="Např. letní kola 2026">
        </label>
        <button class="btn">Přidat fotografii</button>
      </form>

      <?php if ($photos): ?>
        <div class="vehicle-photo-grid">
          <?php foreach ($photos as $photo): ?>
            <figure class="vehicle-photo-card <?= !empty($photo['is_primary']) ? 'is-primary' : '' ?>">
              <img src="media.php?view=<?= (int)$photo['id'] ?>" alt="<?= h((string)($photo['caption'] ?: $vehicle['name'])) ?>">
              <figcaption>
                <span class="vehicle-photo-caption"><?= !empty($photo['is_primary']) ? '★ Hlavní · ' : '' ?><?= h((string)($photo['caption'] ?? '')) ?></span>
                <div class="vehicle-photo-actions">
                  <?php if (empty($photo['is_primary'])): ?>
                    <form method="post" action="media.php">
                      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                      <input type="hidden" name="action" value="set_primary">
                      <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                      <input type="hidden" name="media_id" value="<?= (int)$photo['id'] ?>">
                      <button class="link-button">Nastavit jako hlavní</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="media.php" data-confirm="Opravdu chcete tuto fotografii trvale smazat? Fyzický soubor bude odstraněn také z úložiště serveru.">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_photo">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                    <input type="hidden" name="media_id" value="<?= (int)$photo['id'] ?>">
                    <button class="link-button destructive-link" type="submit"><i class="bi bi-trash3"></i> Smazat</button>
                  </form>
                </div>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($run): ?>
    <?php
      $x = is_array($run['extracted'] ?? null) ? $run['extracted'] : null;
    ?>
    <section class="card extraction-review">
      <div class="table-card-head document-review-head">
        <div>
          <h2>🔎 Kontrola vytěžených údajů</h2>
          <p>
            <?= h($run['original_name']) ?>
            · <?= h((string)($run['extractor'] ?: 'bez extraktoru')) ?>
            · běh #<?= (int)$run['id'] ?>
          </p>
        </div>
        <div class="document-review-actions">
          <span class="status-pill status-<?= h((string)$run['status']) ?>"><?= h((string)$run['status']) ?></span>
          <form method="post" class="document-reextract-form" data-confirm="Spustit nové vytěžení originálního dokumentu? Vznikne nový běh a aktuální data zůstanou beze změny až do jeho potvrzení.">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="reextract_document">
            <input type="hidden" name="document_id" value="<?= (int)$run['document_id'] ?>">
            <select name="extraction_mode" aria-label="Způsob opakovaného vytěžení">
              <option value="auto">Automaticky</option>
              <option value="local">Lokální parser</option>
              <option value="ai" <?= !$aiAvailable ? 'disabled' : '' ?>>AI<?= !$aiAvailable ? ' – nedostupná' : '' ?></option>
            </select>
            <button class="btn btn-compact" type="submit">↻ Vytěžit znovu</button>
          </form>
        </div>
      </div>

      <?php if ($run['status'] === 'error'): ?>
        <div class="error"><?= h((string)$run['error_message']) ?></div>
      <?php elseif ($x): ?>
        <div class="extraction-summary">
          <div><small>TYP</small><b><?= h((string)$x['document_type']) ?></b></div>
          <div><small>POSKYTOVATEL</small><b><?= h((string)($x['provider'] ?? '—')) ?></b></div>
          <div><small>DATUM</small><b><?= h((string)($x['document_date'] ?? '—')) ?></b></div>
          <div><small>JISTOTA</small><b><?= cz(((float)($x['confidence'] ?? 0)) * 100, 0) ?> %</b></div>
        </div>

        <?php if (!empty($x['energy_entries'])): ?>
          <h3>Tankování / nabíjení</h3>
          <div class="table-scroll">
            <table class="data-table">
              <thead><tr><th>Datum</th><th>Typ</th><th>Množství</th><th>Cena</th><th>Místo</th></tr></thead>
              <tbody>
                <?php foreach ($x['energy_entries'] as $row): ?>
                  <tr>
                    <td><?= h((string)$row['occurred_at']) ?></td>
                    <td><?= h((string)$row['energy_type']) ?></td>
                    <td><?= cz((float)$row['quantity'], 3) ?></td>
                    <td><?= $row['total_price'] !== null ? cz((float)$row['total_price'], 2) . ' ' . h((string)$x['currency']) : '—' ?></td>
                    <td><?= h((string)($row['station'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if (!empty($x['service_records'])): ?>
          <h3>Servis</h3>
          <div class="table-scroll">
            <table class="data-table">
              <thead><tr><th>Datum</th><th>Úkon</th><th>Dodavatel</th><th>Cena</th></tr></thead>
              <tbody>
                <?php foreach ($x['service_records'] as $row): ?>
                  <tr>
                    <td><?= h((string)$row['serviced_at']) ?></td>
                    <td><?= h((string)$row['title']) ?></td>
                    <td><?= h((string)($row['provider'] ?? '')) ?></td>
                    <td><?= $row['cost'] !== null ? cz((float)$row['cost'], 2) . ' ' . h((string)$x['currency']) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if (!empty($x['expenses'])): ?>
          <h3>Ostatní náklady</h3>
          <div class="table-scroll">
            <table class="data-table">
              <thead><tr><th>Datum</th><th>Kategorie</th><th>Název</th><th>Částka</th></tr></thead>
              <tbody>
                <?php foreach ($x['expenses'] as $row): ?>
                  <tr>
                    <td><?= h((string)$row['occurred_at']) ?></td>
                    <td><?= h((string)$row['category']) ?></td>
                    <td><?= h((string)$row['title']) ?></td>
                    <td><?= cz((float)$row['amount'], 2) . ' ' . h((string)$x['currency']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if ($run['status'] === 'review' && !$isLatestRun): ?>
          <div class="notice extraction-replace-note">
            Tento běh už není nejnovější. Pro potvrzení otevřete aktuální vytěžení dokumentu.
          </div>
        <?php elseif ($run['status'] === 'review'): ?>
          <?php if ($linkedOperationCount > 0): ?>
            <div class="notice extraction-replace-note">
              Tento dokument už má v evidenci <?= (int)$linkedOperationCount ?> vytvořených položek. Potvrzením se nahradí pouze položky, které vznikly z tohoto dokumentu; ostatní záznamy vozidla zůstanou beze změny.
            </div>
          <?php endif; ?>
          <form method="post" class="review-confirm" data-confirm="Potvrdit vytěžené údaje a zapsat je do evidence? Pokud byl dokument potvrzen dříve, jeho původní automaticky vytvořené položky budou nahrazeny.">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="confirm_import">
            <input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>">
            <button class="btn primary">✓ Potvrdit a zapsat do evidence</button>
            <small>Originální dokument i historie předchozích vytěžení zůstanou dohledatelné.</small>
          </form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($runHistory): ?>
        <details class="extraction-history" <?= count($runHistory) > 1 ? 'open' : '' ?>>
          <summary>Historie vytěžení · <?= (int)($runPagination['total'] ?? count($runHistory)) ?> běhů</summary>
          <div class="table-scroll">
            <table class="data-table">
              <thead><tr><th>Běh</th><th>Spuštěno</th><th>Extraktor</th><th>Jistota</th><th>Stav</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($runHistory as $historyRun): ?>
                  <tr class="<?= (int)$historyRun['id'] === (int)$run['id'] ? 'is-current-run' : '' ?>">
                    <td>#<?= (int)$historyRun['id'] ?></td>
                    <td><?= h(date('d.m.Y H:i', strtotime((string)$historyRun['created_at']))) ?></td>
                    <td><?= h((string)($historyRun['extractor'] ?: '—')) ?></td>
                    <td><?= $historyRun['confidence'] !== null ? cz((float)$historyRun['confidence'] * 100, 0) . ' %' : '—' ?></td>
                    <td><span class="status-pill status-<?= h((string)$historyRun['status']) ?>"><?= h((string)$historyRun['status']) ?></span></td>
                    <td><a href="documents.php?vehicle_id=<?= (int)$vehicle['id'] ?>&amp;run_id=<?= (int)$historyRun['id'] ?>">Detail</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($runPagination)): ?>
            <?php $pagination = $runPagination; require __DIR__ . '/partials/pagination.php'; ?>
          <?php endif; ?>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="card operations-section">
    <div class="table-card-head">
      <div>
        <h2>🗂 Archiv dokumentů</h2>
        <p class="form-help">Opakované vytěžení používá stále stejný bezpečně uložený originál a vytváří nový běh v historii.</p>
      </div>
    </div>
    <div class="table-scroll">
      <table class="data-table document-archive-table">
        <thead>
          <tr><th>Datum</th><th>Dokument</th><th>Typ</th><th>Provider</th><th>Stav</th><th>Akce</th></tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $doc): ?>
            <tr>
              <td><?= h(date('d.m.Y H:i', strtotime($doc['created_at']))) ?></td>
              <td>
                <b><?= h($doc['original_name']) ?></b>
                <small class="table-subline"><?= h(substr($doc['sha256'], 0, 12)) ?>…</small>
              </td>
              <td><?= h($doc['document_type']) ?></td>
              <td><?= h((string)($doc['provider'] ?? '—')) ?></td>
              <td>
                <span class="status-pill status-<?= h((string)($doc['import_status'] ?? 'uploaded')) ?>">
                  <?= h((string)($doc['import_status'] ?? 'uploaded')) ?>
                </span>
                <?php if (!empty($doc['extractor'])): ?>
                  <small class="table-subline"><?= h((string)$doc['extractor']) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <div class="document-row-actions">
                  <div class="document-row-links">
                    <a href="document-file.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener">Otevřít</a>
                    <?php if ((int)($doc['import_run_id'] ?? 0) > 0): ?>
                      <span>·</span>
                      <a href="documents.php?vehicle_id=<?= (int)$vehicle['id'] ?>&amp;run_id=<?= (int)$doc['import_run_id'] ?>">Detail</a>
                    <?php endif; ?>
                  </div>
                  <form method="post" class="document-reextract-form document-reextract-inline" data-confirm="Spustit nové vytěžení tohoto dokumentu? Předchozí běhy zůstanou v historii.">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reextract_document">
                    <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                    <select name="extraction_mode" aria-label="Způsob opakovaného vytěžení dokumentu <?= h($doc['original_name']) ?>">
                      <option value="auto">Auto</option>
                      <option value="local">Parser</option>
                      <option value="ai" <?= !$aiAvailable ? 'disabled' : '' ?>>AI<?= !$aiAvailable ? ' – off' : '' ?></option>
                    </select>
                    <button class="link-button" type="submit">↻ Vytěžit znovu</button>
                  </form>
                  <form method="post" class="document-delete-form" data-confirm="Opravdu chcete tento doklad trvale smazat? Originální soubor bude fyzicky odstraněn ze serveru. Položky provozní evidence vytvořené pouze tímto dokladem budou odstraněny; u telemetry nabíjení se zruší pouze cena převzatá z dokladu.">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                    <button class="link-button destructive-link" type="submit"><i class="bi bi-trash3"></i> Smazat</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$documents): ?>
            <tr><td colspan="6">Zatím nejsou uložené žádné dokumenty.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($documentPagination)): ?>
      <?php $pagination = $documentPagination; require __DIR__ . '/partials/pagination.php'; ?>
    <?php endif; ?>
  </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

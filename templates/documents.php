<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1"><?php require __DIR__ . '/partials/pwa-head.php'; ?>
  <title>Dokumenty – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/app.css') ?>">
</head>
<body>
<?php $navTitle='Dokumenty'; require __DIR__.'/partials/navigation.php'; ?>
<main class="wrap documents-page">
  <?php if($flash): ?><div class="<?= $flash['type']==='error'?'error':'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <section class="card document-hero">
    <div><small>AI INBOX</small><h1>📄 Dokumenty · <?= h($vehicle['name']) ?></h1><p>Nahrajte účtenku, fakturu za nabíjení nebo servisní doklad. Data se vždy nejdříve zobrazí ke kontrole.</p></div>
    <div class="document-ai-badge">AI: <b><?= h($aiProvider) ?></b></div>
  </section>

  <section class="grid2 document-upload-grid">
    <div class="card">
      <h2>✨ Vytěžit nový doklad</h2>
      <form method="post" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="upload_document">
        <label>Dokument<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp,application/json,text/plain" required></label>
        <p class="form-help">PDF/JPG/PNG/WebP do 20 MB. Stejný soubor nelze k vozidlu importovat dvakrát.</p>
        <button class="btn primary">Nahrát a analyzovat</button>
      </form>
    </div>
    <div class="card" id="vehicle-photos">
      <h2>📸 Fotografie vozidla</h2>
      <?php $photos=$app->vehicleMedia()->listForVehicle((int)$vehicle['id']); ?>
      <form method="post" action="media.php" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="upload_photo"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
        <label>Fotografie<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
        <label>Popisek<input name="caption" maxlength="190" placeholder="Např. letní kola 2026"></label>
        <button class="btn">Přidat fotografii</button>
      </form>
      <?php if($photos): ?><div class="vehicle-photo-grid"><?php foreach($photos as $photo): ?><figure class="vehicle-photo-card <?= !empty($photo['is_primary'])?'is-primary':'' ?>"><img src="media.php?view=<?= (int)$photo['id'] ?>" alt="<?= h((string)($photo['caption'] ?: $vehicle['name'])) ?>"><figcaption><?= !empty($photo['is_primary'])?'★ Hlavní · ':'' ?><?= h((string)($photo['caption'] ?? '')) ?><?php if(empty($photo['is_primary'])): ?><form method="post" action="media.php"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="set_primary"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><input type="hidden" name="media_id" value="<?= (int)$photo['id'] ?>"><button class="link-button">Nastavit jako hlavní</button></form><?php endif; ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
    </div>
  </section>

  <?php if($run): $x=is_array($run['extracted'] ?? null)?$run['extracted']:null; ?>
    <section class="card extraction-review">
      <div class="table-card-head"><div><h2>🔎 Kontrola vytěžených údajů</h2><p><?= h($run['original_name']) ?> · <?= h((string)$run['extractor']) ?></p></div><span class="status-pill status-<?= h((string)$run['status']) ?>"><?= h((string)$run['status']) ?></span></div>
      <?php if($run['status']==='error'): ?><div class="error"><?= h((string)$run['error_message']) ?></div><?php elseif($x): ?>
        <div class="extraction-summary"><div><small>TYP</small><b><?= h((string)$x['document_type']) ?></b></div><div><small>POSKYTOVATEL</small><b><?= h((string)($x['provider'] ?? '—')) ?></b></div><div><small>DATUM</small><b><?= h((string)($x['document_date'] ?? '—')) ?></b></div><div><small>JISTOTA</small><b><?= cz(((float)($x['confidence'] ?? 0))*100,0) ?> %</b></div></div>
        <?php if(!empty($x['energy_entries'])): ?><h3>Tankování / nabíjení</h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Typ</th><th>Množství</th><th>Cena</th><th>Místo</th></tr></thead><tbody><?php foreach($x['energy_entries'] as $r): ?><tr><td><?= h((string)$r['occurred_at']) ?></td><td><?= h((string)$r['energy_type']) ?></td><td><?= cz((float)$r['quantity'],3) ?></td><td><?= $r['total_price']!==null?cz((float)$r['total_price'],2).' '.h((string)$x['currency']):'—' ?></td><td><?= h((string)($r['station'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <?php if(!empty($x['service_records'])): ?><h3>Servis</h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Úkon</th><th>Dodavatel</th><th>Cena</th></tr></thead><tbody><?php foreach($x['service_records'] as $r): ?><tr><td><?= h((string)$r['serviced_at']) ?></td><td><?= h((string)$r['title']) ?></td><td><?= h((string)($r['provider'] ?? '')) ?></td><td><?= $r['cost']!==null?cz((float)$r['cost'],2).' '.h((string)$x['currency']):'—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <?php if(!empty($x['expenses'])): ?><h3>Ostatní náklady</h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Kategorie</th><th>Název</th><th>Částka</th></tr></thead><tbody><?php foreach($x['expenses'] as $r): ?><tr><td><?= h((string)$r['occurred_at']) ?></td><td><?= h((string)$r['category']) ?></td><td><?= h((string)$r['title']) ?></td><td><?= cz((float)$r['amount'],2).' '.h((string)$x['currency']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <?php if($run['status']==='review'): ?><form method="post" class="review-confirm"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="confirm_import"><input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>"><button class="btn primary">✓ Potvrdit a zapsat do evidence</button><small>Potvrzením vzniknou provozní položky. Originální dokument zůstane dohledatelný.</small></form><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="card operations-section">
    <h2>🗂 Archiv dokumentů</h2>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Datum</th><th>Dokument</th><th>Typ</th><th>Provider</th><th>Stav</th><th></th></tr></thead><tbody>
      <?php foreach($documents as $doc): ?><tr><td><?= h(date('d.m.Y H:i',strtotime($doc['created_at']))) ?></td><td><b><?= h($doc['original_name']) ?></b><small class="table-subline"><?= h(substr($doc['sha256'],0,12)) ?>…</small></td><td><?= h($doc['document_type']) ?></td><td><?= h((string)($doc['provider'] ?? '—')) ?></td><td><span class="status-pill status-<?= h((string)($doc['import_status'] ?? 'uploaded')) ?>"><?= h((string)($doc['import_status'] ?? 'uploaded')) ?></span></td><td><a href="document-file.php?id=<?= (int)$doc['id'] ?>" target="_blank">Otevřít</a><?php if((int)($doc['import_run_id'] ?? 0)>0): ?> · <a href="documents.php?vehicle_id=<?= (int)$vehicle['id'] ?>&run_id=<?= (int)$doc['import_run_id'] ?>">Detail</a><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if(!$documents): ?><tr><td colspan="6">Zatím nejsou uložené žádné dokumenty.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</main>
</body></html>

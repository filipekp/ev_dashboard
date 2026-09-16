<?php
$pageTitle='Univerzální mapování importu';
$showNavigation=true;
$navTitle='Univerzální import';
require __DIR__.'/partials/header.php';
$headers=$preview['headers'] ?? [];
$rows=$preview['rows'] ?? [];
?>
<main class="wrap narrow generic-import-page">
  <?php if ($flash): ?><div class="<?= $flash['type']==='error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <section class="page-hero">
    <div>
      <span class="eyebrow">NEZNÁMÝ FORMÁT · KROK 2</span>
      <h1>Namapujte sloupce souboru</h1>
      <p>Nativní plugin tento soubor nerozpoznal. Data můžete přesto bezpečně importovat do vozidla <b><?=h((string)$vehicle['name'])?></b>. Původní soubor byl uložen jako vzorek pro budoucí implementaci pluginu.</p>
    </div>
  </section>

  <section class="card">
    <div class="list-toolbar"><div><span class="section-kicker">Náhled zdroje</span><h2><?=h((string)$sample['original_name'])?></h2><p><?=strtoupper(h((string)$preview['format']))?> · <?=number_format((int)$sample['file_size']/1024,1,',',' ')?> kB · SHA-256 <?=h(substr((string)$sample['sha256'],0,16))?>…</p></div></div>
    <div class="table-wrap">
      <table class="data-table generic-preview-table">
        <thead><tr><?php foreach ($headers as $header): ?><th><?=h($header)?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($headers as $header): ?><td><?=h((string)($row[$header] ?? ''))?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </section>

  <form method="post" class="card generic-mapping-card">
    <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
    <input type="hidden" name="token" value="<?=h($token)?>">
    <div class="list-toolbar"><div><span class="section-kicker">Mapování do databáze</span><h2>Co znamenají jednotlivé sloupce?</h2><p>Povinné jsou pouze <b>Začátek jízdy</b> a <b>Vzdálenost</b>. Ostatní údaje namapujte, pokud je export obsahuje.</p></div></div>

    <div class="mapping-grid">
      <?php foreach ($fields as $target=>$label): ?>
        <label class="mapping-row">
          <span><?=h($label)?></span>
          <select name="mapping[<?=h($target)?>]" <?=in_array($target,['started_at','distance_km'],true)?'required':''?>>
            <option value="">— nepoužít —</option>
            <?php foreach ($headers as $header): ?><option value="<?=h($header)?>"><?=h($header)?></option><?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="mapping-options">
      <label><span>Formát data a času</span><select name="date_format"><option value="auto">Automaticky rozpoznat</option><option value="d.m.Y H:i">DD.MM.RRRR HH:MM</option><option value="d.m.Y H:i:s">DD.MM.RRRR HH:MM:SS</option><option value="Y-m-d H:i:s">RRRR-MM-DD HH:MM:SS</option><option value="Y-m-d H:i">RRRR-MM-DD HH:MM</option></select></label>
      <label class="check-row"><input type="checkbox" name="save_profile" value="1"> <span>Uložit toto mapování pro další použití</span></label>
      <label><span>Název profilu</span><input type="text" name="profile_name" maxlength="190" placeholder="Např. Export VW ID.3 – září 2026"></label>
    </div>

    <div class="form-actions"><a class="btn" href="import.php">Zrušit</a><button class="btn primary" type="submit">Importovat podle mapování</button></div>
  </form>
</main>

<?php require __DIR__.'/partials/footer.php'; ?>

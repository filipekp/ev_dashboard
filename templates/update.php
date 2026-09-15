<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Aktualizace – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">⚡</a>
  <b>Aktualizace aplikace</b>
  <div class="spacer"></div>
  <a class="toplink" href="index.php">Dashboard</a>
  <a class="toplink" href="users.php">Uživatelé</a>
  <a class="toplink" href="vehicles.php">Vozidla</a>
  <a class="toplink" href="logout.php">Odhlásit</a>
</header>
<main class="wrap narrow">
  <?php if ($message): ?><div class="notice"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <section class="card admin-card">
    <h2>🔄 Aktualizace z GitHubu</h2>
    <p>Zdroj: <code><?= h((string)$app->config()->get('update.repository')) ?></code>.</p>
    <p>Kanál: <strong><?= $channel === 'release' ? 'Release' : 'DEV' ?></strong><?php if ($channel === 'dev'): ?> · větev <code><?= h((string)$app->config()->get('update.branch')) ?></code><?php endif; ?>. Nastavuje se přes <code>UPDATE_CHANNEL=release</code> nebo <code>UPDATE_CHANNEL=dev</code> v <code>.env</code>.</p>
    <p>Nainstalovaná verze: <strong><?= h((string)($installedVersion['version'] ?? 'local')) ?></strong></p>
    <?php if ($remote): ?>
      <p>Dostupná verze: <strong><?= h((string)$remote['version']) ?></strong><?php if (!empty($remote['date'])): ?> · <?= h((string)$remote['date']) ?><?php endif; ?></p>
      <?php if (!empty($remote['message'])): ?><p><?= nl2br(h((string)$remote['message'])) ?></p><?php endif; ?>
    <?php else: ?>
      <p class="muted">Informaci o dostupné verzi se nepodařilo načíst. Samotná aktualizace může přesto fungovat.</p>
    <?php endif; ?>
    <p><strong>.env se při aktualizaci nepřepisuje.</strong> Před nasazením se vytvoří záloha spravovaných souborů v neveřejném adresáři <code>.updates/</code> a následně se automaticky spustí nové databázové migrace.</p>
    <form method="post" onsubmit="return confirm('Opravdu stáhnout a nasadit aktuální verzi z GitHubu?');">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="update">
      <button class="btn primary" type="submit">Stáhnout a aktualizovat aplikaci</button>
    </form>
  </section>

  <section class="card admin-card">
    <h2>🗄️ Databázové migrace</h2>
    <?php if (!$migrationStatus): ?>
      <p>Žádné migrace nebyly nalezeny.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Migrace</th><th>Stav</th><th>Aplikováno</th></tr></thead>
          <tbody>
          <?php foreach ($migrationStatus as $row): ?>
            <tr>
              <td><code><?= h((string)$row['migration']) ?></code></td>
              <td>
                <?php if ($row['changed_after_apply']): ?>⚠️ změněna po aplikaci
                <?php elseif ($row['applied']): ?>✅ použita
                <?php else: ?>⏳ čeká
                <?php endif; ?>
              </td>
              <td><?= h((string)($row['applied_at'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="migrate">
      <button class="btn" type="submit">Spustit pouze migrace</button>
    </form>
  </section>
</main>
</body>
</html>

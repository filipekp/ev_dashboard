<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$me = $app->auth()->requireAdmin();
$migrations = $app->migrations();
$updater = $app->updater();
$message = null;
$error = null;
$remote = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'migrate') {
            $applied = $migrations->migrate();
            $message = $applied
                ? 'Migrace dokončeny: ' . implode(', ', $applied)
                : 'Databáze je aktuální, nebyla potřeba žádná migrace.';
        } elseif ($action === 'update') {
            $result = $updater->update();
            $applied = $result['migrations'] ?? [];
            $message = 'Aktualizace z GitHubu byla dokončena.';
            if ($applied) {
                $message .= ' Provedené migrace: ' . implode(', ', $applied) . '.';
            } else {
                $message .= ' Databáze nevyžadovala novou migraci.';
            }
        }
    }

    try {
        $remote = $updater->remoteInfo();
    } catch (Throwable $e) {
        // Aktualizaci lze spustit i když GitHub API pro informaci o commitu selže.
        $remote = null;
    }
    $migrationStatus = $migrations->status();
} catch (Throwable $e) {
    $error = $e->getMessage();
    try {
        $migrationStatus = $migrations->status();
    } catch (Throwable $ignored) {
        $migrationStatus = [];
    }
}
?>
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
    <p>Zdroj: <code><?= h((string)$app->config()->get('update.repository')) ?></code>, větev <code><?= h((string)$app->config()->get('update.branch')) ?></code>.</p>
    <?php if ($remote): ?>
      <p>Poslední commit: <strong><?= h((string)$remote['short_sha']) ?></strong><?php if (!empty($remote['date'])): ?> · <?= h((string)$remote['date']) ?><?php endif; ?></p>
      <?php if (!empty($remote['message'])): ?><p><?= nl2br(h((string)$remote['message'])) ?></p><?php endif; ?>
    <?php else: ?>
      <p class="muted">Informaci o posledním commitu se nepodařilo načíst. Samotná aktualizace může přesto fungovat.</p>
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

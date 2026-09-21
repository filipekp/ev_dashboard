<?php
$pageTitle = 'Aktualizace';
$showNavigation = true;
$navTitle = 'Aktualizace aplikace';
require __DIR__ . '/partials/header.php';
?>
<main class="wrap narrow update-page">
  <?php if ($message): ?>
    <div class="notice"><?= h($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="card admin-card update-overview">
    <div class="update-title-row">
      <div>
        <h2>🔄 Aktualizace z GitHubu</h2>
        <p class="muted update-subtitle">
          Zdroj: <code><?= h((string)$app->config()->get('update.repository')) ?></code>
        </p>
      </div>
      <span class="update-channel <?= $channel === 'release' ? 'release' : 'dev' ?>">
        <?= $channel === 'release' ? 'Release' : 'DEV' ?>
      </span>
    </div>

    <div class="update-version-grid">
      <div class="update-version-box">
        <span>Nainstalovaná verze</span>
        <strong><?= h((string)($installedVersion['version'] ?? 'local')) ?></strong>
      </div>
      <div class="update-version-box">
        <span>Dostupná verze</span>
        <strong><?= $remote ? h((string)$remote['version']) : '—' ?></strong>
        <?php if ($remote && !empty($remote['date'])): ?>
          <small><?= h(date('j. n. Y H:i', strtotime((string)$remote['date']))) ?></small>
        <?php endif; ?>
      </div>
    </div>

    <p class="update-channel-help">
      Kanál se nastavuje přes <code>UPDATE_CHANNEL=release</code> nebo <code>UPDATE_CHANNEL=dev</code>
      v <code>.env</code><?php if ($channel === 'dev'): ?>, aktuální větev je
      <code><?= h((string)$app->config()->get('update.branch')) ?></code><?php endif; ?>.
    </p>

    <?php if (!$remote): ?>
      <p class="muted">Informaci o dostupné verzi se nepodařilo načíst. Samotná aktualizace může přesto fungovat.</p>
    <?php endif; ?>

    <div class="update-safety-note">
      <strong>.env se při aktualizaci nepřepisuje.</strong>
      Před nasazením se vytvoří záloha spravovaných souborů v neveřejném adresáři
      <code>.updates/</code> a následně se automaticky spustí nové databázové migrace.
    </div>

    <form method="post" onsubmit="return confirm('Opravdu stáhnout a nasadit aktuální verzi z GitHubu?');">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="update">
      <button class="btn primary" type="submit">Stáhnout a aktualizovat aplikaci</button>
    </form>
  </section>

  <section class="card admin-card changelog-section">
    <div class="changelog-heading">
      <div>
        <h2>📝 Přehled změn</h2>
        <p class="muted">
          <?php if ($channel === 'release'): ?>
            Všechny releasy novější než nainstalovaná verze
            <strong><?= h((string)($installedVersion['version'] ?? 'local')) ?></strong>.
          <?php else: ?>
            Commity od poslední nainstalované DEV verze.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($changelog): ?>
        <span class="changelog-count"><?= (int)($changelogPagination['total'] ?? count($changelog)) ?> změn</span>
      <?php endif; ?>
    </div>

    <?php if (!$changelog): ?>
      <div class="changelog-empty">
        <strong>✅ Aplikace je aktuální.</strong>
        <span>Od nainstalované verze nejsou k dispozici žádné novější změny.</span>
      </div>
    <?php else: ?>
      <div class="changelog-list">
        <?php foreach ($changelog as $index => $entry): ?>
          <article class="changelog-item<?= $index === 0 ? ' latest' : '' ?>">
            <div class="changelog-marker" aria-hidden="true"></div>
            <div class="changelog-card">
              <header class="changelog-card-head">
                <div>
                  <div class="changelog-version-row">
                    <strong><?= h((string)$entry['version']) ?></strong>
                    <?php if ($index === 0): ?>
                      <span class="changelog-badge">Nejnovější</span>
                    <?php endif; ?>
                    <?php if (!empty($entry['prerelease'])): ?>
                      <span class="changelog-badge prerelease">Pre-release</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($entry['name']) && (string)$entry['name'] !== (string)$entry['version']): ?>
                    <h3><?= h((string)$entry['name']) ?></h3>
                  <?php endif; ?>
                </div>
                <div class="changelog-meta">
                  <?php if (!empty($entry['date'])): ?>
                    <time datetime="<?= h((string)$entry['date']) ?>">
                      <?= h(date('j. n. Y H:i', strtotime((string)$entry['date']))) ?>
                    </time>
                  <?php endif; ?>
                  <?php if (!empty($entry['url'])): ?>
                    <a href="<?= h((string)$entry['url']) ?>" target="_blank" rel="noopener noreferrer">GitHub ↗</a>
                  <?php endif; ?>
                </div>
              </header>
              <div class="release-notes">
                <?= markdown((string)($entry['body'] ?? '')) ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php $pagination = $changelogPagination ?? null; require __DIR__ . '/partials/pagination.php'; ?>
    <?php endif; ?>
  </section>

  <section class="card admin-card">
    <h2>🗄️ Databázové migrace</h2>
    <?php if (!$migrationStatus): ?>
      <p>Žádné migrace nebyly nalezeny.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
          <tr>
            <th>Migrace</th>
            <th>Stav</th>
            <th>Aplikováno</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($migrationStatus as $row): ?>
            <tr>
              <td><code><?= h((string)$row['migration']) ?></code></td>
              <td>
                <?php if ($row['changed_after_apply']): ?>
                  ⚠️ změněna po aplikaci
                <?php elseif ($row['applied']): ?>
                  ✅ použita
                <?php else: ?>
                  ⏳ čeká
                <?php endif; ?>
              </td>
              <td><?= h((string)($row['applied_at'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php $pagination = $migrationPagination ?? null; require __DIR__ . '/partials/pagination.php'; ?>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="migrate">
      <button class="btn" type="submit">Spustit pouze migrace</button>
    </form>
  </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

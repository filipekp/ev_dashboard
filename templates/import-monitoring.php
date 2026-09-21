<?php
$pageTitle = 'Monitoring importů';
$showNavigation = true;
$navTitle = 'Monitoring importů';
require __DIR__ . '/partials/header.php';

$rangeLabels = [
    '1' => 'Posledních 24 hodin',
    '7' => 'Posledních 7 dní',
    '30' => 'Posledních 30 dní',
    '90' => 'Posledních 90 dní',
    'all' => 'Celá historie',
];
$statusLabel = static function (?string $status): string {
    $labels = [
        'running' => 'Probíhá',
        'success' => 'Úspěch',
        'failed' => 'Selhalo',
        'uploaded' => 'Nahráno',
        'processing' => 'Zpracovává se',
        'review' => 'Ke kontrole',
        'confirmed' => 'Potvrzeno',
        'error' => 'Chyba',
        'pending_mapping' => 'Čeká na mapování',
        'imported' => 'Importováno',
    ];
    return $labels[$status ?? ''] ?? (string)$status;
};
$statusClass = static function (?string $status): string {
    if (in_array($status, ['success', 'confirmed', 'imported'], true)) {
        return 'is-success';
    }
    if (in_array($status, ['failed', 'error'], true)) {
        return 'is-error';
    }
    if (in_array($status, ['running', 'processing', 'uploaded', 'pending_mapping'], true)) {
        return 'is-running';
    }
    if ($status === 'review') {
        return 'is-warning';
    }
    return '';
};
$vehicleLabel = static function (array $row): string {
    if (!empty($row['vehicle_name'])) {
        return (string)$row['vehicle_name'];
    }
    return !empty($row['vehicle_id']) ? 'Vozidlo #' . (int)$row['vehicle_id'] : 'Odstraněné vozidlo';
};
$userLabel = static function (array $row): string {
    if (!empty($row['user_name'])) {
        return (string)$row['user_name'];
    }
    return !empty($row['user_id']) ? 'Uživatel #' . (int)$row['user_id'] : '—';
};
?>
<main class="wrap import-monitoring-page">
    <section class="monitoring-hero">
        <div>
            <span class="eyebrow">ADMIN · PROVOZNÍ DOHLED</span>
            <h1>Monitoring importů</h1>
            <p>Centrální audit importů jízd, univerzálních formátů a dokumentového zpracování napříč celou aplikací.</p>
        </div>
        <div class="monitoring-period">
            <small>Sledované období</small>
            <b><?= h($rangeLabels[$range] ?? $rangeLabels['30']) ?></b>
            <?php if ($from !== null): ?><span>od <?= h(date('d.m.Y H:i', strtotime($from))) ?></span><?php endif; ?>
        </div>
    </section>

    <form class="card monitoring-filters" method="get">
        <label>Období
            <select name="range">
                <?php foreach ($rangeLabels as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $range === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Stav datového importu
            <select name="status">
                <option value="">Všechny stavy</option>
                <?php foreach (['running' => 'Probíhá', 'success' => 'Úspěch', 'failed' => 'Selhalo'] as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $integrationStatus === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Zdroj
            <select name="source_type">
                <option value="">Všechny zdroje</option>
                <?php foreach ($sourceTypes as $value): ?>
                    <option value="<?= h($value) ?>"<?= $sourceType === $value ? ' selected' : '' ?>><?= h($value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Stav dokumentu
            <select name="document_status">
                <option value="">Všechny stavy</option>
                <?php foreach (['uploaded' => 'Nahráno', 'processing' => 'Zpracovává se', 'review' => 'Ke kontrole', 'confirmed' => 'Potvrzeno', 'error' => 'Chyba'] as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $documentStatus === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn" type="submit">Použít filtry</button>
    </form>

    <section class="monitoring-kpis">
        <article class="monitoring-kpi">
            <span>Datové importy</span>
            <strong><?= (int)$summary['integration_total'] ?></strong>
            <small><b><?= (int)$summary['integration_success'] ?></b> úspěšných · <em><?= (int)$summary['integration_failed'] ?></em> selhalo</small>
        </article>
        <article class="monitoring-kpi <?= (int)$summary['integration_failed'] > 0 ? 'has-alert' : '' ?>">
            <span>Selhané importy</span>
            <strong><?= (int)$summary['integration_failed'] ?></strong>
            <small><?= (int)$summary['integration_running'] ?> právě probíhá</small>
        </article>
        <article class="monitoring-kpi">
            <span>Naimportované řádky</span>
            <strong><?= number_format((int)$summary['rows_imported'], 0, ',', ' ') ?></strong>
            <small><?= number_format((int)$summary['rows_skipped'], 0, ',', ' ') ?> přeskočeno</small>
        </article>
        <article class="monitoring-kpi <?= (int)$summary['document_errors'] > 0 ? 'has-alert' : '' ?>">
            <span>Dokumentové chyby</span>
            <strong><?= (int)$summary['document_errors'] ?></strong>
            <small><?= (int)$summary['document_total'] ?> dokumentových běhů</small>
        </article>
        <article class="monitoring-kpi <?= (int)$summary['unknown_pending'] > 0 ? 'has-warning' : '' ?>">
            <span>Neznámé formáty</span>
            <strong><?= (int)$summary['unknown_pending'] ?></strong>
            <small>čeká na namapování · <?= (int)$summary['unknown_failed'] ?> selhalo</small>
        </article>
    </section>

    <section class="card admin-card monitoring-section">
        <div class="monitoring-section-head">
            <div><span class="eyebrow">DIAGNOSTIKA</span><h2>Selhané importy</h2></div>
            <span class="monitoring-count"><?= (int)$failedPagination['total'] ?></span>
        </div>
        <?php if (!$failedRuns): ?>
            <div class="empty-state-inline">Ve zvoleném období nejsou evidované žádné selhané datové importy.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="monitoring-table">
                    <thead><tr><th>Čas</th><th>Zdroj</th><th>Uživatel</th><th>Vozidlo</th><th>Chyba</th><th>ID</th></tr></thead>
                    <tbody>
                    <?php foreach ($failedRuns as $run): ?>
                        <tr>
                            <td><b><?= h(date('d.m.Y H:i:s', strtotime((string)$run['started_at']))) ?></b><?php if (!empty($run['finished_at'])): ?><small>konec <?= h(date('H:i:s', strtotime((string)$run['finished_at']))) ?></small><?php endif; ?></td>
                            <td><span class="source-chip"><?= h((string)$run['source_type']) ?></span><small><?= h((string)($run['source_label'] ?? '—')) ?></small></td>
                            <td><b><?= h($userLabel($run)) ?></b><small><?= h((string)($run['user_email'] ?? '')) ?></small></td>
                            <td><b><?= h($vehicleLabel($run)) ?></b><small><?= h((string)($run['vehicle_vin'] ?? $run['registration_plate'] ?? '')) ?></small></td>
                            <td class="monitoring-error-cell"><code><?= h((string)($run['error_message'] ?? 'Bez detailu chyby')) ?></code></td>
                            <td>#<?= (int)$run['id'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php $pagination = $failedPagination; require __DIR__ . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>

    <section class="card admin-card monitoring-section">
        <div class="monitoring-section-head">
            <div><span class="eyebrow">AUDIT</span><h2>Běhy importů</h2></div>
            <span class="monitoring-count"><?= (int)$integrationPagination['total'] ?></span>
        </div>
        <div class="table-wrap">
            <table class="monitoring-table">
                <thead><tr><th>Start</th><th>Stav</th><th>Zdroj</th><th>Uživatel</th><th>Vozidlo</th><th>Výsledek</th><th>Trvání</th><th>ID</th></tr></thead>
                <tbody>
                <?php foreach ($integrationRuns as $run): ?>
                    <?php
                    $duration = null;
                    if (!empty($run['finished_at'])) {
                        $duration = max(0, strtotime((string)$run['finished_at']) - strtotime((string)$run['started_at']));
                    }
                    ?>
                    <tr>
                        <td><b><?= h(date('d.m.Y H:i:s', strtotime((string)$run['started_at']))) ?></b></td>
                        <td><span class="monitoring-status <?= h($statusClass((string)$run['status'])) ?>"><?= h($statusLabel((string)$run['status'])) ?></span></td>
                        <td><span class="source-chip"><?= h((string)$run['source_type']) ?></span><small><?= h((string)($run['source_label'] ?? '—')) ?></small></td>
                        <td><b><?= h($userLabel($run)) ?></b><small><?= h((string)($run['user_email'] ?? '')) ?></small></td>
                        <td><b><?= h($vehicleLabel($run)) ?></b><small><?= h((string)($run['vehicle_vin'] ?? $run['registration_plate'] ?? '')) ?></small></td>
                        <td><b><?= (int)$run['imported_count'] ?> importováno</b><small><?= (int)$run['skipped_count'] ?> přeskočeno</small></td>
                        <td><?= $duration !== null ? h(number_format($duration, 0, ',', ' ') . ' s') : '—' ?></td>
                        <td>#<?= (int)$run['id'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$integrationRuns): ?><tr><td colspan="8">Pro vybrané filtry nejsou žádné běhy.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php $pagination = $integrationPagination; require __DIR__ . '/partials/pagination.php'; ?>
    </section>

    <section class="card admin-card monitoring-section">
        <div class="monitoring-section-head">
            <div><span class="eyebrow">DOKUMENTY & AI</span><h2>Běhy importu dokumentů</h2></div>
            <span class="monitoring-count"><?= (int)$documentPagination['total'] ?></span>
        </div>
        <div class="table-wrap">
            <table class="monitoring-table">
                <thead><tr><th>Čas</th><th>Stav</th><th>Dokument</th><th>Extractor</th><th>Uživatel</th><th>Vozidlo</th><th>Confidence</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($documentRuns as $run): ?>
                    <tr>
                        <td><b><?= h(date('d.m.Y H:i:s', strtotime((string)$run['created_at']))) ?></b><small>akt. <?= h(date('d.m. H:i:s', strtotime((string)$run['updated_at']))) ?></small></td>
                        <td><span class="monitoring-status <?= h($statusClass((string)$run['status'])) ?>"><?= h($statusLabel((string)$run['status'])) ?></span></td>
                        <td><b><?= h((string)$run['original_name']) ?></b><small><?= h((string)$run['document_type']) ?><?= !empty($run['provider']) ? ' · ' . h((string)$run['provider']) : '' ?></small></td>
                        <td><?= h((string)($run['extractor'] ?? '—')) ?></td>
                        <td><b><?= h($userLabel($run)) ?></b><small><?= h((string)($run['user_email'] ?? '')) ?></small></td>
                        <td><b><?= h($vehicleLabel($run)) ?></b><small><?= h((string)($run['vehicle_vin'] ?? $run['registration_plate'] ?? '')) ?></small></td>
                        <td><?= $run['confidence'] !== null ? h(number_format((float)$run['confidence'] * 100, 1, ',', ' ') . ' %') : '—' ?></td>
                        <td>
                            <?php if (!empty($run['error_message'])): ?><code class="monitoring-inline-error"><?= h((string)$run['error_message']) ?></code><?php endif; ?>
                            <a href="document-file.php?id=<?= (int)$run['document_id'] ?>" target="_blank" rel="noopener">Soubor</a>
                            <?php if (!empty($run['vehicle_id'])): ?> · <a href="documents.php?vehicle_id=<?= (int)$run['vehicle_id'] ?>&run_id=<?= (int)$run['id'] ?>">Detail</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$documentRuns): ?><tr><td colspan="8">Pro vybrané filtry nejsou žádné dokumentové běhy.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php $pagination = $documentPagination; require __DIR__ . '/partials/pagination.php'; ?>
    </section>

    <section class="card admin-card monitoring-section">
        <div class="monitoring-section-head">
            <div><span class="eyebrow">UNIVERZÁLNÍ IMPORT</span><h2>Neznámé formáty</h2></div>
            <span class="monitoring-count"><?= (int)$unknownPagination['total'] ?></span>
        </div>
        <div class="table-wrap">
            <table class="monitoring-table">
                <thead><tr><th>Čas</th><th>Stav</th><th>Soubor</th><th>Uživatel</th><th>Vozidlo</th><th>Výsledek</th><th>SHA-256</th></tr></thead>
                <tbody>
                <?php foreach ($unknownImports as $sample): ?>
                    <tr>
                        <td><b><?= h(date('d.m.Y H:i:s', strtotime((string)$sample['created_at']))) ?></b></td>
                        <td><span class="monitoring-status <?= h($statusClass((string)$sample['status'])) ?>"><?= h($statusLabel((string)$sample['status'])) ?></span></td>
                        <td><b><?= h((string)$sample['original_name']) ?></b><small><?= h(strtoupper((string)$sample['file_format'])) ?> · <?= h(number_format((int)$sample['file_size'] / 1024, 1, ',', ' ')) ?> kB</small></td>
                        <td><b><?= h($userLabel($sample)) ?></b><small><?= h((string)($sample['user_email'] ?? '')) ?></small></td>
                        <td><b><?= h($vehicleLabel($sample)) ?></b><small><?= h((string)($sample['vehicle_vin'] ?? $sample['registration_plate'] ?? '')) ?></small></td>
                        <td><b><?= (int)$sample['imported_count'] ?> importováno</b><small><?= (int)$sample['skipped_count'] ?> přeskočeno<?= !empty($sample['error_message']) ? ' · ' . h((string)$sample['error_message']) : '' ?></small></td>
                        <td><code><?= h(substr((string)$sample['sha256'], 0, 16)) ?>…</code></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$unknownImports): ?><tr><td colspan="7">Ve zvoleném období nejsou žádné neznámé formáty.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php $pagination = $unknownPagination; require __DIR__ . '/partials/pagination.php'; ?>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
$pageTitle = 'Connected Car';
$showNavigation = true;
$navTitle = 'Connected Car';
require __DIR__ . '/partials/header.php';
?>
<main class="wrap narrow connected-car-page">
    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card page-hero connected-hero">
        <div class="connected-hero-icon">⌁</div>
        <div>
            <span class="section-kicker">OEM CONNECTORS</span>
            <h1>Přímé propojení s automobilkou</h1>
            <p>EV Stats používá pluginovou vrstvu konektorů. Každé vozidlo se připojuje přímo k podporovanému API výrobce a telemetrie se ukládá do jednotného interního modelu.</p>
        </div>
        <div class="connected-hero-status">
            <span class="status-dot is-online"></span>
            <b><?= count($items) ?> vozidel</b>
            <small><?= count(array_filter($items, static function (array $item): bool { return !empty($item['connection']); })) ?> připojeno</small>
        </div>
    </section>

    <section class="card connected-vehicles-card">
        <div class="section-heading">
            <div><span class="section-kicker">VOZIDLA</span><h2>Konektory vozidel</h2></div>
        </div>

        <div class="connected-vehicle-list">
            <?php foreach ($items as $item):
                $v = $item['vehicle'];
                $connection = $item['connection'];
                $telemetry = $item['telemetry'];
                $connectorOptions = $item['connectors'];
                $connectorLabel = $connection['provider'] ?? '';
                foreach ($connectorOptions as $option) {
                    if (!empty($connection) && ($option['id'] ?? '') === ($connection['provider'] ?? '')) {
                        $connectorLabel = $option['label'] ?? $connectorLabel;
                    }
                }
            ?>
                <article class="connected-vehicle-row">
                    <div class="connected-vehicle-main">
                        <span class="connected-car-icon">🚙</span>
                        <div>
                            <small><?= h((string)($v['manufacturer'] ?? '')) ?></small>
                            <h3><?= h((string)$v['name']) ?></h3>
                            <p><?= h((string)$v['vin']) ?></p>
                        </div>
                    </div>

                    <div class="telemetry-mini-grid">
                        <span><small>Konektor</small><b><?= $connection ? h((string)$connectorLabel) : ($connectorOptions ? 'Dostupný' : 'Není dostupný') ?></b></span>
                        <span><small>SoC</small><b><?= $telemetry && $telemetry['soc_pct'] !== null ? h(cz((float)$telemetry['soc_pct'], 0)) . ' %' : '—' ?></b></span>
                        <span><small>Dojezd</small><b><?= $telemetry && $telemetry['range_km'] !== null ? h(cz((float)$telemetry['range_km'], 0)) . ' km' : '—' ?></b></span>
                        <span><small>Tachometr</small><b><?= $telemetry && $telemetry['odometer_km'] !== null ? h(cz((float)$telemetry['odometer_km'], 0)) . ' km' : '—' ?></b></span>
                    </div>

                    <div class="connected-vehicle-actions">
                        <?php if ($connection): ?>
                            <span class="provider-status status-<?= h((string)$connection['status']) ?>"><?= h((string)$connection['status']) ?></span>
                            <small>Poslední sync: <?= h((string)($connection['last_synced_at'] ?? '—')) ?></small>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="sync_connection">
                                <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                <button class="btn btn-compact" type="submit">↻ Synchronizovat</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($app->auth()->canManageVehicles($user)): ?>
                            <a class="btn <?= $connection ? '' : 'primary' ?> btn-compact" href="vehicles.php?edit=<?= (int)$v['id'] ?>"><?= $connection ? 'Nastavení' : 'Připojit' ?></a>
                        <?php else: ?>
                            <small>Nastavení spravuje správce vozidla.</small>
                        <?php endif; ?>
                    </div>

                    <?php if ($connection && !empty($connection['last_error'])): ?>
                        <div class="connected-row-error" style="grid-column:1/-1"><?= h((string)$connection['last_error']) ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$items): ?><p class="empty-table">Nemáte dostupné žádné vozidlo.</p><?php endif; ?>
        </div>
    </section>

    <?php if ($recentRuns): ?>
        <section class="card connected-runs-card">
            <div class="section-heading"><div><span class="section-kicker">AUTOSYNC</span><h2>Poslední synchronizace</h2></div></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Čas</th><th>Vozidlo</th><th>Konektor</th><th>Spuštění</th><th>Stav</th><th>Snapshoty</th><th>Události</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentRuns as $run): ?>
                        <tr>
                            <td><?= h((string)$run['started_at']) ?></td>
                            <td><?= h((string)($run['vehicle_name'] ?? '—')) ?></td>
                            <td><?= h((string)($run['provider'] ?? '—')) ?></td>
                            <td><?= h((string)$run['trigger_type']) ?></td>
                            <td><span class="sync-status sync-<?= h((string)$run['status']) ?>"><?= h((string)$run['status']) ?></span><?php if (!empty($run['error_message'])): ?><small class="table-error"><?= h((string)$run['error_message']) ?></small><?php endif; ?></td>
                            <td><?= (int)$run['snapshots_created'] ?></td>
                            <td><?= (int)$run['events_created'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

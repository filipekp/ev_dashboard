<?php
$pageTitle = 'Timeline';
$showNavigation = true;
$navTitle = 'Timeline';
require __DIR__ . '/partials/header.php';
?>
<main class="wrap revolution-wrap">
    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="page-hero compact-hero">
        <div>
            <?php if ($vehiclePhoto): ?>
                <img class="hero-car-thumb" src="media.php?view=<?= (int)$vehiclePhoto['id'] ?>" alt="">
            <?php endif; ?>
        </div>
        <div>
            <span class="eyebrow">ŽIVOT VOZIDLA</span>
            <h1><?= h($vehicle['name']) ?> Timeline</h1>
            <p>Jízdy, energie, servis, náklady a online události vozidla v jedné chronologii.</p>
        </div>
        <a class="btn primary" href="import.php">＋ Přidat / importovat</a>
    </section>

    <?php $vehicleWorkspaceTab = 'timeline'; require __DIR__ . '/partials/vehicle-workspace.php'; ?>

    <section class="insight-strip">
        <?php foreach ($insights as $i): ?>
            <article class="insight-card">
                <span><?= $i['icon'] ?></span>
                <div>
                    <b><?= h($i['title']) ?></b>
                    <p><?= h($i['text']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="timeline-shell">
        <div class="timeline-list">
            <?php foreach ($events as $e): ?>
                <?php
                $meta = [
                    'trip' => ['🚗', 'Jízda'],
                    'energy' => ['⚡', 'Energie / palivo'],
                    'service' => ['🔧', 'Servis'],
                    'expense' => ['💳', 'Náklad'],
                    'connected' => ['⌁', 'Connected Car'],
                ][$e['type']] ?? ['•', 'Událost'];
                $activeTrip = $e['type'] === 'trip'
                    && (string)($e['trip_state'] ?? 'completed') === 'active';
                ?>
                <article class="timeline-event type-<?= h($e['type']) ?>">
                    <div class="timeline-dot"><?= $meta[0] ?></div>
                    <div class="timeline-body">
                        <small><?= h(date('d.m.Y H:i', strtotime($e['event_at']))) ?> · <?= $meta[1] ?></small>
                        <h3><?= h((string)($e['label'] ?: $meta[1])) ?></h3>
                        <p><?= h((string)($e['detail'] ?? '')) ?></p>
                    </div>
                    <div class="timeline-event-actions">
                        <strong>
                            <?php if ($e['type'] === 'trip'): ?>
                                <?= cz((float)$e['value'], 1) ?> km
                            <?php elseif ($e['value'] !== null): ?>
                                <?= cz((float)$e['value'], 0) ?> Kč
                            <?php endif; ?>
                        </strong>
                        <?php if ($e['type'] === 'trip'): ?>
                            <?php if ($activeTrip): ?>
                                <button class="icon-action danger-action" type="button" disabled title="Probíhající jízdu nelze smazat" aria-label="Probíhající jízdu nelze smazat">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            <?php else: ?>
                                <form method="post" action="trip-delete.php" data-confirm="Opravdu chcete tuto jízdu trvale smazat? U telemetry jízdy zůstanou raw data zachována, ale jízda se už při synchronizaci ani rebuild procesu znovu nevytvoří.">
                                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                                    <input type="hidden" name="trip_id" value="<?= (int)$e['id'] ?>">
                                    <input type="hidden" name="return_to" value="timeline">
                                    <button class="icon-action danger-action" type="submit" title="Smazat jízdu" aria-label="Smazat jízdu">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (!$events): ?>
                <div class="empty-state">
                    <b>Timeline je zatím prázdná</b>
                    <p>Importujte jízdu, fakturu nebo přidejte provozní záznam.</p>
                    <a class="btn primary" href="import.php">Otevřít Import Hub</a>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($pagination)): ?>
            <?php require __DIR__ . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

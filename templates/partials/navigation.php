<?php
/** Shared EV Stats cockpit navigation. */
$navUser = $user ?? $me ?? $app->auth()->currentUser() ?? [];
$navVehicles = isset($navigationVehicles) && is_array($navigationVehicles)
    ? $navigationVehicles
    : (isset($vehicles) && is_array($vehicles) ? $vehicles : ($navUser ? $app->auth()->allowedVehicles($navUser) : []));
$navVehicle = isset($vehicle) && is_array($vehicle) ? $vehicle : ($navUser ? $app->auth()->selectVehicle($navUser) : null);
$navPage = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
$navCanManageVehicles = $navUser ? $app->auth()->canManageVehicles($navUser) : false;
$navIsAdmin = $navUser ? $app->auth()->isAdmin($navUser) : false;
$navHasVehicle = $navVehicle !== null && isset($navVehicle['id']);
$navVehicleId = $navHasVehicle ? (int)$navVehicle['id'] : 0;
$navPowertrain = $navHasVehicle ? strtoupper((string)($navVehicle['powertrain_type'] ?? 'BEV')) : '';
$navTitle = isset($navTitle) && trim((string)$navTitle) !== '' ? (string)$navTitle : 'EV Stats';
$navAppVersion = isset($appVersionLabel) ? (string)$appVersionLabel : (string)$app->version()->label();
$navCurrent = static function (string $page) use ($navPage): string {
    return $navPage === $page ? ' is-active' : '';
};
$navVehicleQuery = $navVehicleId ? '?vehicle_id=' . $navVehicleId : '';
$navVehicleAwarePages = ['index.php', 'dashboard.php', 'operations.php', 'documents.php', 'timeline.php', 'import.php'];
$navVehicleTargetPage = in_array($navPage, $navVehicleAwarePages, true)
    ? ($navPage === 'dashboard.php' ? 'index.php' : $navPage)
    : 'index.php';
$navInitial = mb_strtoupper(mb_substr((string)($navUser['name'] ?? 'U'), 0, 1));
$navPageLabel = [
    'index.php' => 'Dashboard vozidla',
    'dashboard.php' => 'Dashboard vozidla',
    'garage.php' => 'Garáž',
    'operations.php' => 'Provoz a náklady',
    'documents.php' => 'Doklady',
    'timeline.php' => 'Timeline',
    'import.php' => 'Import Hub',
    'integrations.php' => 'Connected Car',
    'vehicles.php' => 'Vozidla',
    'users.php' => 'Uživatelé',
    'import-monitoring.php' => 'Monitoring',
    'update.php' => 'Aktualizace',
    'profile.php' => 'Profil',
][$navPage] ?? $navTitle;
?>
<header class="cockpit-topbar">
    <div class="cockpit-topbar-start">
        <a class="cockpit-brand" href="garage.php" aria-label="EV Stats – Garáž">
            <span class="cockpit-brand-mark"><i class="bi bi-lightning-charge-fill"></i></span>
            <span><b>EV Stats</b><small>Digital Garage</small></span>
        </a>
        <span class="cockpit-page-title"><small>WORKSPACE</small><b><?= h($navPageLabel) ?></b></span>
    </div>

    <div class="cockpit-topbar-center">
        <button class="cockpit-command" type="button" data-bs-toggle="modal" data-bs-target="#commandPalette" aria-label="Rychlé hledání">
            <i class="bi bi-search"></i>
            <span>Hledat vozidlo, stránku nebo akci…</span>
            <kbd>Ctrl K</kbd>
        </button>
    </div>

    <div class="cockpit-topbar-actions">
        <?php if ($navHasVehicle && $navVehicles): ?>
            <div class="vehicle-picker cockpit-vehicle-picker" data-vehicle-picker>
                <button class="vehicle-picker-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                    <span class="cockpit-vehicle-dot"><i class="bi <?= in_array($navPowertrain, ['BEV', 'PHEV'], true) ? 'bi-ev-station-fill' : 'bi-fuel-pump-fill' ?>"></i></span>
                    <span class="cockpit-vehicle-copy"><small>AKTIVNÍ VOZIDLO</small><b><?= h((string)$navVehicle['name']) ?></b></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="vehicle-picker-menu" role="listbox" hidden>
                    <?php foreach ($navVehicles as $option): ?>
                        <?php
                        $optionId = (int)$option['id'];
                        $optionPowertrain = strtoupper((string)($option['powertrain_type'] ?? 'BEV'));
                        $targetUrl = $navVehicleTargetPage . '?vehicle_id=' . $optionId;
                        ?>
                        <a class="vehicle-picker-option<?= $optionId === $navVehicleId ? ' is-selected' : '' ?>" href="<?= h($targetUrl) ?>" role="option" aria-selected="<?= $optionId === $navVehicleId ? 'true' : 'false' ?>">
                            <span class="vehicle-picker-icon"><i class="bi <?= in_array($optionPowertrain, ['BEV', 'PHEV'], true) ? 'bi-lightning-charge-fill' : 'bi-fuel-pump-fill' ?>"></i></span>
                            <span><b><?= h((string)$option['name']) ?></b><small><?= h(powertrainLabel($optionPowertrain)) ?><?= !empty($option['registration_plate']) ? ' · ' . h((string)$option['registration_plate']) : '' ?></small></span>
                            <?php if ((int)($navUser['default_vehicle_id'] ?? 0) === $optionId): ?><i class="bi bi-star-fill" title="Výchozí vozidlo"></i><?php endif; ?>
                            <?php if ($optionId === $navVehicleId): ?><strong><i class="bi bi-check2"></i></strong><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <a class="vehicle-picker-add" href="index.php?add_vehicle=1"><i class="bi bi-plus-lg"></i> Přidat nové vozidlo</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="theme-switcher dropdown">
            <button class="cockpit-icon-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Barevný režim">
                <i class="bi bi-circle-half" data-theme-bootstrap-icon></i><span class="visually-hidden" data-theme-label>Podle systému</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end theme-dropdown">
                <li><h6 class="dropdown-header">Barevný režim</h6></li>
                <li><button class="dropdown-item" type="button" data-theme-choice="light"><i class="bi bi-sun"></i> Světlý</button></li>
                <li><button class="dropdown-item" type="button" data-theme-choice="dark"><i class="bi bi-moon-stars"></i> Tmavý</button></li>
                <li><button class="dropdown-item" type="button" data-theme-choice="auto"><i class="bi bi-circle-half"></i> Podle systému</button></li>
            </ul>
        </div>
        <a class="cockpit-icon-button" href="profile.php" title="Můj profil"><span class="cockpit-avatar"><?= h($navInitial) ?></span></a>
    </div>
</header>

<aside class="cockpit-rail" aria-label="Hlavní navigace">
    <div class="cockpit-rail-brand" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></div>
    <nav class="cockpit-rail-nav">
        <small>PŘEHLED</small>
        <a class="<?= $navCurrent('garage.php') ?>" href="garage.php" data-bs-toggle="tooltip" data-bs-placement="left" title="Garáž"><i class="bi bi-grid-1x2-fill"></i><span>Garáž</span></a>
        <a class="<?= $navCurrent('index.php') ?><?= $navCurrent('dashboard.php') ?>" href="index.php<?= $navVehicleQuery ?>" data-bs-toggle="tooltip" data-bs-placement="left" title="Dashboard"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <?php if ($navHasVehicle): ?>
            <a class="<?= $navCurrent('timeline.php') ?>" href="timeline.php?vehicle_id=<?= $navVehicleId ?>" data-bs-toggle="tooltip" data-bs-placement="left" title="Timeline"><i class="bi bi-clock-history"></i><span>Timeline</span></a>
            <a class="<?= $navCurrent('import.php') ?>" href="import.php?vehicle_id=<?= $navVehicleId ?>" data-bs-toggle="tooltip" data-bs-placement="left" title="Import Hub"><i class="bi bi-cloud-arrow-up"></i><span>Import</span></a>
            <a class="<?= $navCurrent('integrations.php') ?>" href="integrations.php" data-bs-toggle="tooltip" data-bs-placement="left" title="Connected Car"><i class="bi bi-broadcast-pin"></i><span>Connected</span></a>
        <?php endif; ?>
        <?php if ($navCanManageVehicles || $navIsAdmin): ?>
            <small>SPRÁVA</small>
            <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('vehicles.php') ?>" href="vehicles.php" title="Vozidla"><i class="bi bi-car-front-fill"></i><span>Vozidla</span></a><?php endif; ?>
            <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('users.php') ?>" href="users.php" title="Uživatelé"><i class="bi bi-people-fill"></i><span>Uživatelé</span></a><?php endif; ?>
            <?php if ($navIsAdmin): ?><a class="<?= $navCurrent('import-monitoring.php') ?>" href="import-monitoring.php" title="Monitoring"><i class="bi bi-activity"></i><span>Monitoring</span></a><?php endif; ?>
            <?php if ($navIsAdmin): ?><a class="<?= $navCurrent('update.php') ?>" href="update.php" title="Aktualizace"><i class="bi bi-arrow-repeat"></i><span>Aktualizace</span></a><?php endif; ?>
        <?php endif; ?>
    </nav>
    <div class="cockpit-rail-bottom">
        <a class="<?= $navCurrent('profile.php') ?>" href="profile.php" title="Profil"><i class="bi bi-person-circle"></i><span>Profil</span></a>
        <a href="logout.php" title="Odhlásit se"><i class="bi bi-box-arrow-right"></i><span>Odhlásit</span></a>
        <small>v<?= h($navAppVersion) ?></small>
    </div>
</aside>

<header class="mobile-topbar">
    <a class="mobile-brand" href="garage.php" aria-label="EV Stats – Garáž"><i class="bi bi-lightning-charge-fill"></i> <b>EV Stats</b></a>
    <?php if ($navVehicles): ?>
        <form class="mobile-vehicle-picker" method="get" action="<?= h($navVehicleTargetPage) ?>">
            <label class="visually-hidden" for="mobileVehicleSelect">Aktivní vozidlo</label>
            <select id="mobileVehicleSelect" name="vehicle_id" data-auto-submit aria-label="Vybrat vozidlo z garáže">
                <?php if (!$navHasVehicle): ?><option value="" selected disabled>Vybrat vozidlo</option><?php endif; ?>
                <?php foreach ($navVehicles as $option): ?>
                    <?php $optionId = (int)$option['id']; ?>
                    <option value="<?= $optionId ?>"<?= $optionId === $navVehicleId ? ' selected' : '' ?>><?= h((string)$option['name']) ?><?= ((isset($option['registration_plate'])) ? ' | ' . h((string)$option['registration_plate']) : '') ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php else: ?>
        <span class="mobile-vehicle-picker-placeholder">Garáž</span>
    <?php endif; ?>
    <a class="mobile-avatar" href="profile.php" aria-label="Můj profil"><?= h($navInitial) ?></a>
</header>

<nav class="mobile-bottom-nav" aria-label="Mobilní navigace">
    <a class="<?= $navCurrent('garage.php') ?>" href="garage.php"><i class="bi bi-grid-1x2-fill"></i><small>Garáž</small></a>
    <a class="<?= $navCurrent('index.php') ?>" href="index.php<?= $navVehicleQuery ?>"><i class="bi bi-speedometer2"></i><small>Dashboard</small></a>
    <a class="mobile-add" href="<?= $navHasVehicle ? 'import.php?vehicle_id=' . $navVehicleId : 'index.php?add_vehicle=1' ?>"><span><i class="bi bi-plus-lg"></i></span><small>Přidat</small></a>
    <?php if ($navHasVehicle): ?><a class="<?= $navCurrent('timeline.php') ?>" href="timeline.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-clock-history"></i><small>Timeline</small></a><?php else: ?><a href="index.php?add_vehicle=1"><i class="bi bi-car-front"></i><small>Vozidlo</small></a><?php endif; ?>
    <button class="mobile-more-trigger<?= in_array($navPage, ['import.php', 'documents.php', 'integrations.php', 'vehicles.php', 'users.php', 'import-monitoring.php', 'update.php', 'profile.php'], true) ? ' is-active' : '' ?>" type="button" data-mobile-more-open aria-haspopup="dialog" aria-controls="mobileMoreMenu" aria-expanded="false"><i class="bi bi-three-dots"></i><small>Více</small></button>
</nav>

<div class="mobile-more-backdrop" data-mobile-more-backdrop hidden></div>
<section class="mobile-more-menu" id="mobileMoreMenu" data-mobile-more-menu role="dialog" aria-modal="true" aria-label="Další navigace" hidden>
    <header class="mobile-more-head">
        <div><small>EV STATS · <?= h($navAppVersion) ?></small><b>Rychlá nabídka</b></div>
        <button type="button" class="mobile-more-close" data-mobile-more-close aria-label="Zavřít menu">×</button>
    </header>
    <div class="mobile-more-links">
        <?php if ($navHasVehicle): ?>
            <a href="operations.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-battery-charging"></i><div><b>Provoz & náklady</b><small>Nabíjení, servis a výdaje</small></div><i class="bi bi-chevron-right"></i></a>
            <a href="documents.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-receipt"></i><div><b>Doklady</b><small>Faktury, účtenky a AI vytěžování</small></div><i class="bi bi-chevron-right"></i></a>
            <a class="<?= $navCurrent('import.php') ?>" href="import.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-cloud-arrow-up"></i><div><b>Import Hub</b><small>Import jízd a dat</small></div><i class="bi bi-chevron-right"></i></a>
            <a class="<?= $navCurrent('integrations.php') ?>" href="integrations.php"><i class="bi bi-broadcast-pin"></i><div><b>Connected Car</b><small>AutoSync a online telemetrie</small></div><i class="bi bi-chevron-right"></i></a>
        <?php endif; ?>
        <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('vehicles.php') ?>" href="vehicles.php"><i class="bi bi-car-front-fill"></i><div><b>Vozidla</b><small>Správa vozidel a parametrů</small></div><i class="bi bi-chevron-right"></i></a><?php endif; ?>
        <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('users.php') ?>" href="users.php"><i class="bi bi-people-fill"></i><div><b>Uživatelé</b><small>Hierarchie a oprávnění</small></div><i class="bi bi-chevron-right"></i></a><?php endif; ?>
        <?php if ($navIsAdmin): ?><a class="<?= $navCurrent('import-monitoring.php') ?>" href="import-monitoring.php"><i class="bi bi-activity"></i><div><b>Monitoring</b><small>Běhy, chyby a importy</small></div><i class="bi bi-chevron-right"></i></a><?php endif; ?>
        <div class="mobile-theme-row">
            <div><i class="bi bi-circle-half"></i><div><b>Vzhled aplikace</b><small data-theme-label>Podle systému</small></div></div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Barevný režim">
                <button type="button" class="btn" data-theme-choice="light" title="Světlý režim"><i class="bi bi-sun"></i></button>
                <button type="button" class="btn" data-theme-choice="dark" title="Tmavý režim"><i class="bi bi-moon-stars"></i></button>
                <button type="button" class="btn" data-theme-choice="auto" title="Podle systému"><i class="bi bi-circle-half"></i></button>
            </div>
        </div>
        <a class="<?= $navCurrent('profile.php') ?>" href="profile.php"><span class="avatar-mini"><?= h($navInitial) ?></span><div><b>Můj profil</b><small><?= h((string)($navUser['name'] ?? 'Uživatel')) ?></small></div><i class="bi bi-chevron-right"></i></a>
        <a class="mobile-more-logout" href="logout.php"><i class="bi bi-box-arrow-right"></i><div><b>Odhlásit se</b><small>Ukončit aktuální relaci</small></div><i class="bi bi-chevron-right"></i></a>
    </div>
</section>

<div class="modal fade command-palette-modal" id="commandPalette" tabindex="-1" aria-labelledby="commandPaletteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="command-palette-search">
                <i class="bi bi-search"></i>
                <input type="search" data-command-search placeholder="Hledat v EV Stats…" autocomplete="off" aria-labelledby="commandPaletteTitle">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="command-palette-body">
                <small id="commandPaletteTitle">RYCHLÁ NAVIGACE</small>
                <a data-command-item href="garage.php"><i class="bi bi-grid-1x2-fill"></i><span><b>Garáž</b><small>Přehled všech vozidel</small></span><kbd>G</kbd></a>
                <?php if ($navHasVehicle): ?>
                    <a data-command-item href="index.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-speedometer2"></i><span><b>Dashboard <?= h((string)$navVehicle['name']) ?></b><small>Aktuální stav a analytika</small></span></a>
                    <a data-command-item href="operations.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-battery-charging"></i><span><b>Provoz & náklady</b><small>Nabíjení, servis a výdaje</small></span></a>
                    <a data-command-item href="documents.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-receipt"></i><span><b>Doklady</b><small>Faktury a účtenky</small></span></a>
                    <a data-command-item href="timeline.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-clock-history"></i><span><b>Timeline</b><small>Chronologická historie vozidla</small></span></a>
                    <a data-command-item href="import.php?vehicle_id=<?= $navVehicleId ?>"><i class="bi bi-cloud-arrow-up"></i><span><b>Import Hub</b><small>CSV/XLSX a další zdroje</small></span></a>
                <?php endif; ?>
                <?php if ($navVehicles): ?>
                    <small>VOZIDLA</small>
                    <?php foreach ($navVehicles as $option): ?>
                        <a data-command-item href="index.php?vehicle_id=<?= (int)$option['id'] ?>"><i class="bi bi-car-front-fill"></i><span><b><?= h((string)$option['name']) ?></b><small><?= h((string)($option['registration_plate'] ?? $option['vin'] ?? '')) ?></small></span></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="command-palette-empty" data-command-empty hidden>Nic jsme nenašli.</div>
        </div>
    </div>
</div>

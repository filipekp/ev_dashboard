<?php
/** Shared EV Stats 4.0 navigation. */
$navUser = $user ?? $me ?? [];
$navVehicles = isset($vehicles) && is_array($vehicles) ? $vehicles : [];
$navVehicle = isset($vehicle) && is_array($vehicle) ? $vehicle : NULL;
$navPage = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
$navCanManageVehicles = $navUser ? $app->auth()->canManageVehicles($navUser) : FALSE;
$navIsAdmin = $navUser ? $app->auth()->isAdmin($navUser) : FALSE;
$navHasVehicle = $navVehicle !== NULL && isset($navVehicle['id']);
$navVehicleId = $navHasVehicle ? (int)$navVehicle['id'] : 0;
$navPowertrain = $navHasVehicle ? strtoupper((string)($navVehicle['powertrain_type'] ?? 'BEV')) : '';
$navTitle = isset($navTitle) ? (string)$navTitle : '';
$navCurrent = static function (string $page) use ($navPage): string { return $navPage === $page ? ' is-active' : ''; };
$navVehicleQuery = $navVehicleId ? '?vehicle_id=' . $navVehicleId : '';
?>
<aside class="app-sidebar" aria-label="Hlavní navigace">
    <a class="sidebar-brand" href="index.php"><span class="sidebar-brand-mark">⚡</span><span><b>EV Stats</b><small>Digital Garage</small></span></a>

    <?php if ($navHasVehicle && $navVehicles): ?>
        <form class="sidebar-vehicle" method="get" action="index.php">
            <small>AKTIVNÍ VOZIDLO</small>
            <select name="vehicle_id" onchange="this.form.submit()" aria-label="Vybrat vozidlo">
                <?php foreach ($navVehicles as $option): ?>
                    <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === $navVehicleId ? 'selected' : '' ?>><?= (int)($navUser['default_vehicle_id'] ?? 0) === (int)$option['id'] ? '★ ' : '' ?><?= h($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span><?= h($navPowertrain) ?> · <?= h((string)($navVehicle['registration_plate'] ?? $navVehicle['vin'] ?? '')) ?></span>
        </form>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <small>PŘEHLED</small>
        <a class="<?= $navCurrent('index.php') ?>" href="index.php<?= $navVehicleQuery ?>"><span>⌂</span> Přehled</a>
        <a class="<?= $navCurrent('garage.php') ?>" href="garage.php"><span>◈</span> Moje garáž</a>
        <?php if ($navHasVehicle): ?>
            <small>VOZIDLO</small>
            <a class="<?= $navCurrent('operations.php') ?>" href="operations.php?vehicle_id=<?= $navVehicleId ?>"><span>↗</span> Provoz & náklady</a>
            <a class="<?= $navCurrent('documents.php') ?>" href="documents.php?vehicle_id=<?= $navVehicleId ?>"><span>✦</span> Dokumenty & AI</a>
        <?php endif; ?>
        <?php if ($navCanManageVehicles || $navIsAdmin): ?>
            <small>SPRÁVA</small>
            <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('vehicles.php') ?>" href="vehicles.php"><span>⚙</span> Vozidla</a><?php endif; ?>
            <?php if ($navIsAdmin): ?><a class="<?= $navCurrent('users.php') ?>" href="users.php"><span>♙</span> Uživatelé</a><a class="<?= $navCurrent('update.php') ?>" href="update.php"><span>↻</span> Aktualizace</a><?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <a class="sidebar-profile<?= $navCurrent('profile.php') ?>" href="profile.php"><span class="avatar"><?= h(mb_strtoupper(mb_substr((string)($navUser['name'] ?? 'U'), 0, 1))) ?></span><span><b><?= h((string)($navUser['name'] ?? 'Profil')) ?></b><small>Můj účet</small></span><i>›</i></a>
        <a class="sidebar-logout" href="logout.php">Odhlásit se</a>
    </div>
</aside>

<header class="mobile-topbar">
    <a class="mobile-brand" href="index.php">⚡ <b>EV Stats</b></a>
    <?php if ($navHasVehicle && $navVehicles): ?><form method="get" action="index.php"><select name="vehicle_id" onchange="this.form.submit()" aria-label="Vybrat vozidlo"><?php foreach ($navVehicles as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === $navVehicleId ? 'selected' : '' ?>><?= h($option['name']) ?></option><?php endforeach; ?></select></form><?php endif; ?>
    <a class="mobile-avatar" href="profile.php"><?= h(mb_strtoupper(mb_substr((string)($navUser['name'] ?? 'U'), 0, 1))) ?></a>
</header>

<nav class="mobile-bottom-nav" aria-label="Mobilní navigace">
    <a class="<?= $navCurrent('index.php') ?>" href="index.php<?= $navVehicleQuery ?>"><span>⌂</span><small>Přehled</small></a>
    <a class="<?= $navCurrent('garage.php') ?>" href="garage.php"><span>◈</span><small>Garáž</small></a>
    <a class="mobile-add" href="<?= $navHasVehicle ? 'documents.php?vehicle_id=' . $navVehicleId : 'index.php?add_vehicle=1' ?>"><span>＋</span><small>Přidat</small></a>
    <?php if ($navHasVehicle): ?><a class="<?= $navCurrent('operations.php') ?>" href="operations.php?vehicle_id=<?= $navVehicleId ?>"><span>↗</span><small>Provoz</small></a><?php else: ?><a href="index.php?add_vehicle=1"><span>＋</span><small>Vozidlo</small></a><?php endif; ?>
    <a class="<?= $navCurrent('profile.php') ?>" href="profile.php"><span>☰</span><small>Více</small></a>
</nav>

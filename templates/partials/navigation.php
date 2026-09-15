<?php
/**
 * Shared application navigation.
 *
 * Expected variables:
 * - $app
 * - $user or $me
 * Optional:
 * - $vehicle
 * - $vehicles
 * - $navTitle
 */
$navUser = $user ?? $me ?? [];
$navVehicles = isset($vehicles) && is_array($vehicles) ? $vehicles : [];
$navVehicle = isset($vehicle) && is_array($vehicle) ? $vehicle : NULL;
$navPage = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
$navCanManageVehicles = $navUser ? $app->auth()->canManageVehicles($navUser) : FALSE;
$navIsAdmin = $navUser ? $app->auth()->isAdmin($navUser) : FALSE;
$navHasVehicle = $navVehicle !== NULL && isset($navVehicle['id']);
$navVehicleId = $navHasVehicle ? (int)$navVehicle['id'] : 0;
$navPowertrain = $navHasVehicle ? strtoupper((string)($navVehicle['powertrain_type'] ?? 'BEV')) : '';
$navHasTractionBattery = in_array($navPowertrain, ['BEV', 'PHEV'], TRUE);
$navTitle = isset($navTitle) ? (string)$navTitle : '';
$navCurrent = static function (string $page) use ($navPage): string {
    return $navPage === $page ? ' is-active' : '';
};
?>
<header class="topbar app-nav">
    <a class="brand" href="index.php" aria-label="EV Stats dashboard">⚡</a>

    <?php if ($navHasVehicle && $navVehicles): ?>
        <form class="vehicle-switch" method="get" action="index.php">
            <select name="vehicle_id" onchange="this.form.submit()" aria-label="Vybrat vozidlo">
                <?php foreach ($navVehicles as $navVehicleOption): ?>
                    <option value="<?= (int)$navVehicleOption['id'] ?>" <?= (int)$navVehicleOption['id'] === $navVehicleId ? 'selected' : '' ?>>
                        <?= (int)($navUser['default_vehicle_id'] ?? 0) === (int)$navVehicleOption['id'] ? '★ ' : '' ?><?= h($navVehicleOption['name']) ?> · <?= h($navVehicleOption['vin']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a class="vehicle nav-vehicle-summary" href="index.php?vehicle_id=<?= $navVehicleId ?>">
            <b><?= h($navVehicle['name']) ?></b>
            <span><?= h($navPowertrain) ?><?php if ($navHasTractionBattery && (float)($navVehicle['battery_kwh'] ?? 0) > 0): ?> · <?= cz((float)$navVehicle['battery_kwh'], 0) ?> kWh<?php elseif ((float)($navVehicle['fuel_tank_l'] ?? 0) > 0): ?> · <?= cz((float)$navVehicle['fuel_tank_l'], 0) ?> l<?php endif; ?></span>
            <small>VIN: <?= h($navVehicle['vin']) ?></small>
        </a>
    <?php elseif ($navTitle !== ''): ?>
        <div class="nav-page-title"><?= h($navTitle) ?></div>
    <?php else: ?>
        <div class="nav-page-title">EV Stats</div>
    <?php endif; ?>

    <div class="spacer"></div>

    <nav class="desktop-nav" aria-label="Hlavní navigace">
        <a class="toplink<?= $navCurrent('index.php') ?>" href="index.php<?= $navVehicleId ? '?vehicle_id=' . $navVehicleId : '' ?>">📊 Dashboard</a>

        <details class="nav-dropdown">
            <summary class="toplink">🚙 Vozidla <span class="nav-chevron">▾</span></summary>
            <div class="nav-dropdown-menu">
                <a href="index.php?add_vehicle=1">➕ Přidat vozidlo</a>
                <?php if ($navHasVehicle): ?><a href="operations.php?vehicle_id=<?= $navVehicleId ?>">🧾 Provoz</a><a href="documents.php?vehicle_id=<?= $navVehicleId ?>">📄 Dokumenty & AI</a><?php endif; ?>
                <a class="<?= $navCurrent('garage.php') ?>" href="garage.php">🚘 Garage</a>
                <?php if ($navCanManageVehicles): ?><a href="vehicles.php">⚙️ Správa vozidel</a><?php endif; ?>
            </div>
        </details>

        <?php if ($navIsAdmin): ?>
            <details class="nav-dropdown">
                <summary class="toplink">🛠 Správa <span class="nav-chevron">▾</span></summary>
                <div class="nav-dropdown-menu">
                    <a href="users.php">👥 Uživatelé</a>
                    <a href="vehicles.php">🚙 Vozidla</a>
                    <a href="update.php">🔄 Aktualizace</a>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($navHasVehicle): ?>
            <form action="upload.php" method="post" enctype="multipart/form-data" class="upload nav-upload">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="vehicle_id" value="<?= $navVehicleId ?>">
                <label>⬆ Nahrát data<input type="file" name="csv" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" onchange="this.form.submit()"></label>
            </form>
        <?php endif; ?>

        <a class="toplink nav-account-trigger<?= $navCurrent('profile.php') ?>" href="profile.php" title="Otevřít profil">👤 <span><?= h((string)($navUser['name'] ?? 'Profil')) ?></span></a>
        <a class="toplink nav-logout" href="logout.php" title="Odhlásit">↪</a>
    </nav>

    <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Otevřít menu" aria-controls="mobileMenuOverlay" aria-expanded="false">☰</button>
</header>

<div class="mobile-menu-overlay" id="mobileMenuOverlay" hidden>
    <div class="mobile-menu-header">
        <strong>EV Stats</strong>
        <button type="button" class="mobile-menu-close" id="mobileMenuClose" aria-label="Zavřít menu">×</button>
    </div>
    <a class="mobile-menu-account" href="profile.php">Přihlášen: <strong><?= h((string)($navUser['name'] ?? '')) ?></strong><span>Otevřít profil ›</span></a>
    <nav class="mobile-menu-links" aria-label="Mobilní navigace">
        <a href="index.php<?= $navVehicleId ? '?vehicle_id=' . $navVehicleId : '' ?>">📊 <span>Dashboard</span></a>
        <a href="garage.php">🚘 <span>Garage</span></a>
        <a href="index.php?add_vehicle=1">➕ <span>Přidat vozidlo</span></a>
        <?php if ($navHasVehicle): ?><a href="operations.php?vehicle_id=<?= $navVehicleId ?>">🧾 <span>Provoz</span></a><a href="documents.php?vehicle_id=<?= $navVehicleId ?>">📄 <span>Dokumenty & AI</span></a><?php endif; ?>
        <?php if ($navCanManageVehicles): ?><a href="vehicles.php">🚙 <span>Správa vozidel</span></a><?php endif; ?>
        <?php if ($navIsAdmin): ?>
            <div class="mobile-menu-section">Administrace</div>
            <a href="users.php">👥 <span>Uživatelé</span></a>
            <a href="update.php">🔄 <span>Aktualizace</span></a>
        <?php endif; ?>
        <?php if ($navHasVehicle): ?>
            <form action="upload.php" method="post" enctype="multipart/form-data" class="mobile-menu-upload">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="vehicle_id" value="<?= $navVehicleId ?>">
                <label>⬆ <span>Nahrát data</span><input type="file" name="csv" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" onchange="this.form.submit()"></label>
            </form>
        <?php endif; ?>
        <a href="profile.php">👤 <span>Můj profil</span></a>
        <a href="logout.php" class="mobile-menu-logout">↪ <span>Odhlásit</span></a>
    </nav>
</div>

<script>
(() => {
    const toggle = document.getElementById('mobileMenuToggle');
    const overlay = document.getElementById('mobileMenuOverlay');
    const close = document.getElementById('mobileMenuClose');
    if (!toggle || !overlay || !close) return;

    const openMenu = () => {
        overlay.hidden = false;
        document.body.classList.add('mobile-menu-open');
        toggle.setAttribute('aria-expanded', 'true');
        close.focus();
    };
    const closeMenu = () => {
        overlay.hidden = true;
        document.body.classList.remove('mobile-menu-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
    };

    toggle.addEventListener('click', openMenu);
    close.addEventListener('click', closeMenu);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !overlay.hidden) closeMenu();
    });

    document.querySelectorAll('.nav-dropdown').forEach((dropdown) => {
        dropdown.addEventListener('toggle', () => {
            if (!dropdown.open) return;
            document.querySelectorAll('.nav-dropdown[open]').forEach((other) => {
                if (other !== dropdown) other.removeAttribute('open');
            });
        });
    });
})();
</script>

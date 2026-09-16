<?php
/** Shared EV Stats 4.0 navigation. */
$navUser = $user ?? $me ?? $app->auth()->currentUser() ?? [];
$navVehicles = isset($vehicles) && is_array($vehicles) ? $vehicles : ($navUser ? $app->auth()->allowedVehicles($navUser) : []);
$navVehicle = isset($vehicle) && is_array($vehicle) ? $vehicle : ($navUser ? $app->auth()->selectVehicle($navUser) : NULL);
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
        <div class="vehicle-picker" data-vehicle-picker>
            <button class="vehicle-picker-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                <span class="vehicle-picker-label">AKTIVNÍ VOZIDLO</span>
                <span class="vehicle-picker-main"><i><?= (int)($navUser['default_vehicle_id'] ?? 0) === $navVehicleId ? '★' : '◆' ?></i><b><?= h((string)$navVehicle['name']) ?></b><em>⌄</em></span>
                <span class="vehicle-picker-meta"><?= h(powertrainLabel($navPowertrain)) ?><?= powertrainHasBattery($navPowertrain) && !empty($navVehicle['battery_kwh']) ? ' · ' . h((string)$navVehicle['battery_kwh']) . ' kWh' : '' ?><?= powertrainHasFuel($navPowertrain) && !empty($navVehicle['fuel_tank_l']) ? ' · ' . h((string)$navVehicle['fuel_tank_l']) . ' ' . h(fuelUnit($navPowertrain)) : '' ?><?= !empty($navVehicle['registration_plate']) ? ' · ' . h((string)$navVehicle['registration_plate']) : '' ?></span>
            </button>
            <div class="vehicle-picker-menu" role="listbox" hidden>
                <?php foreach ($navVehicles as $option): ?>
                    <?php
                    $optionId = (int)$option['id'];
                    $optionPowertrain = strtoupper((string)($option['powertrain_type'] ?? 'BEV'));
                    $targetPage = in_array($navPage, ['index.php', 'operations.php', 'documents.php', 'timeline.php', 'import.php'], TRUE) ? $navPage : 'index.php';
                    $targetUrl = $targetPage . '?vehicle_id=' . $optionId;
                    ?>
                    <a class="vehicle-picker-option<?= $optionId === $navVehicleId ? ' is-selected' : '' ?>" href="<?= h($targetUrl) ?>" role="option" aria-selected="<?= $optionId === $navVehicleId ? 'true' : 'false' ?>">
                        <span class="vehicle-picker-icon"><?= in_array($optionPowertrain, ['BEV','PHEV'], TRUE) ? '⚡' : '⛽' ?></span>
                        <span><b><?= h((string)$option['name']) ?></b><small><?= h(powertrainLabel($optionPowertrain)) ?><?= powertrainHasBattery($optionPowertrain) && !empty($option['battery_kwh']) ? ' · ' . h((string)$option['battery_kwh']) . ' kWh' : '' ?><?= powertrainHasFuel($optionPowertrain) && !empty($option['fuel_tank_l']) ? ' · ' . h((string)$option['fuel_tank_l']) . ' ' . h(fuelUnit($optionPowertrain)) : '' ?><?= !empty($option['registration_plate']) ? ' · ' . h((string)$option['registration_plate']) : '' ?></small></span>
                        <?php if ((int)($navUser['default_vehicle_id'] ?? 0) === $optionId): ?><i title="Výchozí vozidlo">★</i><?php endif; ?>
                        <?php if ($optionId === $navVehicleId): ?><strong>✓</strong><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <a class="vehicle-picker-add" href="index.php?add_vehicle=1"><span>＋</span> Přidat nové vozidlo</a>
            </div>
        </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <small>PŘEHLED</small>
        <a class="<?= $navCurrent('index.php') ?>" href="index.php<?= $navVehicleQuery ?>"><span>⌂</span> Přehled</a>
        <a class="<?= $navCurrent('garage.php') ?>" href="garage.php"><span>◈</span> Moje garáž</a>
        <?php if ($navHasVehicle): ?>
            <small>VOZIDLO</small>
            <a class="<?= $navCurrent('timeline.php') ?>" href="timeline.php?vehicle_id=<?= $navVehicleId ?>"><span>◷</span> Timeline</a>
            <a class="<?= $navCurrent('operations.php') ?>" href="operations.php?vehicle_id=<?= $navVehicleId ?>"><span>↗</span> Provoz & náklady</a>
            <a class="<?= $navCurrent('import.php') ?>" href="import.php?vehicle_id=<?= $navVehicleId ?>"><span>＋</span> Import Hub</a>
            <a class="<?= $navCurrent('documents.php') ?>" href="documents.php?vehicle_id=<?= $navVehicleId ?>"><span>✦</span> Dokumenty & AI</a>
        <?php endif; ?>
        <?php if ($navCanManageVehicles || $navIsAdmin): ?>
            <small>SPRÁVA</small>
            <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('vehicles.php') ?>" href="vehicles.php"><span>⚙</span> Vozidla</a><?php endif; ?>
            <?php if ($navCanManageVehicles): ?><a class="<?= $navCurrent('users.php') ?>" href="users.php"><span>♙</span> Uživatelé</a><?php endif; ?>
            <?php if ($navIsAdmin): ?><a class="<?= $navCurrent('import-monitoring.php') ?>" href="import-monitoring.php"><span>⌁</span> Monitoring importů</a><?php endif; ?>
            <?php if ($navIsAdmin): ?><a class="<?= $navCurrent('update.php') ?>" href="update.php"><span>↻</span> Aktualizace</a><?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <a class="sidebar-profile<?= $navCurrent('profile.php') ?>" href="profile.php"><span class="avatar"><?= h(mb_strtoupper(mb_substr((string)($navUser['name'] ?? 'U'), 0, 1))) ?></span><span><b><?= h((string)($navUser['name'] ?? 'Profil')) ?></b><small>Můj účet</small></span><i>›</i></a>
        <a class="sidebar-logout" href="logout.php">Odhlásit se</a>
    </div>
</aside>

<header class="mobile-topbar">
    <a class="mobile-brand" href="index.php">⚡ <b>EV Stats</b></a>
    <?php if ($navHasVehicle && $navVehicles): ?><a class="mobile-vehicle-link" href="garage.php"><b><?= h((string)$navVehicle['name']) ?></b><small><?= h($navPowertrain) ?></small></a><?php endif; ?>
    <a class="mobile-avatar" href="profile.php"><?= h(mb_strtoupper(mb_substr((string)($navUser['name'] ?? 'U'), 0, 1))) ?></a>
</header>

<nav class="mobile-bottom-nav" aria-label="Mobilní navigace">
    <a class="<?= $navCurrent('index.php') ?>" href="index.php<?= $navVehicleQuery ?>"><span>⌂</span><small>Přehled</small></a>
    <a class="<?= $navCurrent('garage.php') ?>" href="garage.php"><span>◈</span><small>Garáž</small></a>
    <a class="mobile-add" href="<?= $navHasVehicle ? 'import.php?vehicle_id=' . $navVehicleId : 'index.php?add_vehicle=1' ?>"><span>＋</span><small>Přidat</small></a>
    <?php if ($navHasVehicle): ?><a class="<?= $navCurrent('operations.php') ?>" href="operations.php?vehicle_id=<?= $navVehicleId ?>"><span>↗</span><small>Provoz</small></a><?php else: ?><a href="index.php?add_vehicle=1"><span>＋</span><small>Vozidlo</small></a><?php endif; ?>
    <button class="mobile-more-trigger<?= in_array($navPage, ['timeline.php', 'import.php', 'documents.php', 'vehicles.php', 'users.php', 'import-monitoring.php', 'update.php', 'profile.php'], TRUE) ? ' is-active' : '' ?>" type="button" data-mobile-more-open aria-haspopup="dialog" aria-controls="mobileMoreMenu" aria-expanded="false"><span>☰</span><small>Více</small></button>
</nav>

<div class="mobile-more-backdrop" data-mobile-more-backdrop hidden></div>
<section class="mobile-more-menu" id="mobileMoreMenu" data-mobile-more-menu role="dialog" aria-modal="true" aria-label="Další navigace" hidden>
    <header class="mobile-more-head">
        <div><small>EV STATS</small><b>Další nabídka</b></div>
        <button type="button" class="mobile-more-close" data-mobile-more-close aria-label="Zavřít menu">×</button>
    </header>
    <div class="mobile-more-links">
        <?php if ($navHasVehicle): ?>
            <a class="<?= $navCurrent('timeline.php') ?>" href="timeline.php?vehicle_id=<?= $navVehicleId ?>"><span>◷</span><div><b>Timeline</b><small>Historie událostí vozidla</small></div><i>›</i></a>
            <a class="<?= $navCurrent('import.php') ?>" href="import.php?vehicle_id=<?= $navVehicleId ?>"><span>＋</span><div><b>Import Hub</b><small>Import jízd a dat</small></div><i>›</i></a>
            <a class="<?= $navCurrent('documents.php') ?>" href="documents.php?vehicle_id=<?= $navVehicleId ?>"><span>✦</span><div><b>Dokumenty & AI</b><small>Faktury, účtenky a dokumenty</small></div><i>›</i></a>
        <?php endif; ?>
        <?php if ($navCanManageVehicles): ?>
            <a class="<?= $navCurrent('vehicles.php') ?>" href="vehicles.php"><span>⚙</span><div><b>Vozidla</b><small>Správa vozidel a parametrů</small></div><i>›</i></a>
        <?php endif; ?>
        <?php if ($navCanManageVehicles): ?>
            <a class="<?= $navCurrent('users.php') ?>" href="users.php"><span>♙</span><div><b>Uživatelé</b><small>Hierarchie a oprávnění</small></div><i>›</i></a>
        <?php endif; ?>
        <?php if ($navIsAdmin): ?>
            <a class="<?= $navCurrent('import-monitoring.php') ?>" href="import-monitoring.php"><span>⌁</span><div><b>Monitoring importů</b><small>Běhy, chyby a dokumentové importy</small></div><i>›</i></a>
            <a class="<?= $navCurrent('update.php') ?>" href="update.php"><span>↻</span><div><b>Aktualizace</b><small>Verze aplikace a updater</small></div><i>›</i></a>
        <?php endif; ?>
        <a class="<?= $navCurrent('profile.php') ?>" href="profile.php"><span class="avatar-mini"><?= h(mb_strtoupper(mb_substr((string)($navUser['name'] ?? 'U'), 0, 1))) ?></span><div><b>Můj profil</b><small><?= h((string)($navUser['name'] ?? 'Uživatel')) ?></small></div><i>›</i></a>
        <a class="mobile-more-logout" href="logout.php"><span>⇥</span><div><b>Odhlásit se</b><small>Ukončit aktuální relaci</small></div><i>›</i></a>
    </div>
</section>

<script>
document.querySelectorAll('[data-vehicle-picker]').forEach((picker) => {
  const trigger = picker.querySelector('.vehicle-picker-trigger');
  const menu = picker.querySelector('.vehicle-picker-menu');
  if (!trigger || !menu) return;
  trigger.addEventListener('click', () => {
    const open = !menu.hidden;
    document.querySelectorAll('.vehicle-picker-menu').forEach((item) => { item.hidden = true; });
    menu.hidden = open;
    trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
  });
});
document.addEventListener('click', (event) => {
  document.querySelectorAll('[data-vehicle-picker]').forEach((picker) => {
    if (!picker.contains(event.target)) {
      const menu = picker.querySelector('.vehicle-picker-menu');
      const trigger = picker.querySelector('.vehicle-picker-trigger');
      if (menu) menu.hidden = true;
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }
  });
});
const mobileMoreMenu = document.querySelector('[data-mobile-more-menu]');
const mobileMoreBackdrop = document.querySelector('[data-mobile-more-backdrop]');
const mobileMoreOpen = document.querySelector('[data-mobile-more-open]');
const mobileMoreClose = document.querySelector('[data-mobile-more-close]');

const setMobileMoreOpen = (open) => {
  if (!mobileMoreMenu || !mobileMoreBackdrop || !mobileMoreOpen) return;
  mobileMoreMenu.hidden = !open;
  mobileMoreBackdrop.hidden = !open;
  mobileMoreOpen.setAttribute('aria-expanded', open ? 'true' : 'false');
  document.body.classList.toggle('mobile-more-open', open);
  if (open && mobileMoreClose) mobileMoreClose.focus();
};

if (mobileMoreOpen) mobileMoreOpen.addEventListener('click', () => setMobileMoreOpen(true));
if (mobileMoreClose) mobileMoreClose.addEventListener('click', () => setMobileMoreOpen(false));
if (mobileMoreBackdrop) mobileMoreBackdrop.addEventListener('click', () => setMobileMoreOpen(false));
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && mobileMoreMenu && !mobileMoreMenu.hidden) setMobileMoreOpen(false);
});

</script>

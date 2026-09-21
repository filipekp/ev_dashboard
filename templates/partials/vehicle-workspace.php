<?php
/**
 * Společná navigace pracovního prostoru jednoho vozidla.
 *
 * @var array<string,mixed> $vehicle
 */
$workspaceVehicleId = (int)($vehicle['id'] ?? 0);
$workspacePage = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
$workspaceView = (string)($_GET['view'] ?? '');
$workspaceTab = isset($vehicleWorkspaceTab) ? (string)$vehicleWorkspaceTab : '';
if ($workspaceTab === '') {
    if ($workspacePage === 'operations.php') {
        $workspaceTab = $workspaceView === 'costs' ? 'costs' : 'operations';
    } elseif ($workspacePage === 'documents.php') {
        $workspaceTab = 'documents';
    } elseif ($workspacePage === 'timeline.php') {
        $workspaceTab = 'timeline';
    } elseif ($workspacePage === 'vehicles.php') {
        $workspaceTab = 'settings';
    } else {
        $workspaceTab = 'overview';
    }
}
$workspaceCanManage = isset($app, $user) && is_array($user) && $app->auth()->canManageVehicles($user);
if (!$workspaceCanManage && isset($app, $me) && is_array($me)) {
    $workspaceCanManage = $app->auth()->canManageVehicles($me);
}
$workspaceLinks = [
    ['overview', 'Přehled', '⌂', 'index.php?vehicle_id=' . $workspaceVehicleId],
    ['operations', 'Provoz', '↗', 'operations.php?vehicle_id=' . $workspaceVehicleId . '&view=operations'],
    ['costs', 'Náklady', '₭', 'operations.php?vehicle_id=' . $workspaceVehicleId . '&view=costs'],
    ['documents', 'Doklady', '▤', 'documents.php?vehicle_id=' . $workspaceVehicleId],
    ['timeline', 'Timeline', '◷', 'timeline.php?vehicle_id=' . $workspaceVehicleId],
];
if ($workspaceCanManage) {
    $workspaceLinks[] = ['settings', 'Nastavení', '⚙', 'vehicles.php?edit=' . $workspaceVehicleId];
}
?>
<nav class="vehicle-workspace card" aria-label="Sekce vozidla">
    <div class="vehicle-workspace-title">
        <small>VOZIDLO</small>
        <strong><?= h((string)($vehicle['name'] ?? '')) ?></strong>
    </div>
    <div class="vehicle-workspace-tabs" role="tablist" aria-label="Dashboard vozidla">
        <?php foreach ($workspaceLinks as $workspaceLink): ?>
            <a class="vehicle-workspace-tab<?= $workspaceTab === $workspaceLink[0] ? ' is-active' : '' ?>"
               href="<?= h($workspaceLink[3]) ?>"
               <?= $workspaceTab === $workspaceLink[0] ? 'aria-current="page"' : '' ?>>
                <span><?= h($workspaceLink[2]) ?></span><?= h($workspaceLink[1]) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="vehicle-workspace-import btn btn-sm" href="import.php?vehicle_id=<?= $workspaceVehicleId ?>">＋ Import</a>
</nav>

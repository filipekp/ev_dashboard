<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  $me = $app->auth()->requireVehicleManager();
  try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      verifyCsrf();
      $action = (string)($_POST['action'] ?? '');
      if ($action === 'create' || $action === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)$_POST['name']);
        $vin     = strtoupper(trim((string)$_POST['vin']));
        $battery = (float)str_replace(',', '.', (string)$_POST['battery_kwh']);
        $nominal = (float)str_replace(',', '.', (string)($_POST['battery_nominal_kwh'] ?? $battery));
        $sohRaw  = trim((string)($_POST['soh_manual_pct'] ?? ''));
        $soh     = $sohRaw === '' ? NULL : (float)str_replace(',', '.', $sohRaw);
        $home    = trim((string)($_POST['home_label'] ?? ''));
        if ($name === '' || $vin === '' || $battery <= 0 || $nominal <= 0) {
          throw new RuntimeException('Vyplňte název, VIN a kapacitu baterie.');
        }
        if ($soh !== NULL && ($soh < 50 || $soh > 110)) {
          throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
        }
        if ($action === 'create') {
          $q = $pdo->prepare('INSERT INTO vehicles(name,vin,battery_kwh,battery_nominal_kwh,soh_manual_pct,soh_manual_at,home_label) VALUES(?,?,?,?,?,IF(? IS NULL,NULL,NOW()),?)');
          $q->execute([
            $name,
            $vin,
            $battery,
            $nominal,
            $soh,
            $soh,
            $home ?: NULL
          ]);
          flash('Vozidlo bylo přidáno.');
        } else {
          $q = $pdo->prepare('UPDATE vehicles SET name=?,vin=?,battery_kwh=?,battery_nominal_kwh=?,soh_manual_pct=?,soh_manual_at=IF(? IS NULL,NULL,NOW()),home_label=? WHERE id=?');
          $q->execute([
            $name,
            $vin,
            $battery,
            $nominal,
            $soh,
            $soh,
            $home ?: NULL,
            $id
          ]);
          flash('Vozidlo bylo upraveno.');
        }
      } elseif ($action === 'assign') {
        $vehicleId = (int)$_POST['vehicle_id'];
        $userIds   = array_map('intval', $_POST['user_ids'] ?? []);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM user_vehicles WHERE vehicle_id=?')->execute([$vehicleId]);
        $ins = $pdo->prepare('INSERT INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)');
        foreach (array_unique($userIds) as $uid) {
          if ($uid > 0) {
            $ins->execute([
              $uid,
              $vehicleId
            ]);
          }
        }
        // U běžných uživatelů zrušíme výchozí vozidlo, pokud jim bylo právě odebráno.
        $pdo->prepare("UPDATE users u SET u.default_vehicle_id=NULL WHERE u.default_vehicle_id=? AND u.role='user' AND NOT EXISTS (SELECT 1 FROM user_vehicles uv WHERE uv.user_id=u.id AND uv.vehicle_id=?)")->execute([
          $vehicleId,
          $vehicleId
        ]);
        $pdo->commit();
        flash('Přiřazení vozidla bylo uloženo.');
      } elseif ($action === 'delete') {
        if (!$app->auth()->isAdmin($me)) {
          throw new RuntimeException('Vozidlo může smazat pouze administrátor.');
        }
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM vehicles WHERE id=?')->execute([$id]);
        flash('Vozidlo a jeho jízdy byly smazány.');
      }
      redirect('vehicles.php');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    flash($e->getMessage(), 'error');
    redirect('vehicles.php');
  }
  $vehicles = $pdo->query('SELECT v.*, (SELECT COUNT(*) FROM trips t WHERE t.vehicle_id=v.id) trip_count FROM vehicles v ORDER BY v.name')->fetchAll();
  $users    = $pdo->query("SELECT id,name,email FROM users WHERE active=1 ORDER BY name")->fetchAll();
  $assigned = [];
  foreach ($pdo->query('SELECT user_id,vehicle_id FROM user_vehicles') as $r) {
    $assigned[(int)$r['vehicle_id']][] = (int)$r['user_id'];
  }
  $flash = getFlash();
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vozidla – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar"><a class="brand" href="index.php">⚡</a><b>Správa vozidel</b>
  <div class="spacer"></div><?php if ($app->auth()->isAdmin($me)): ?><a class="toplink" href="users.php">Uživatelé</a><a class="toplink" href="update.php">Aktualizace</a><?php endif; ?><a class="toplink"
                                                                                                                          href="profile.php">Můj
    profil</a><a class="toplink" href="index.php">Dashboard</a><a class="toplink" href="logout.php">Odhlásit</a></header>
<main class="wrap narrow"><?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <section class="card admin-card"><h2>➕ Přidat vozidlo</h2>
    <form method="post" class="vehicle-create-grid"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                                          value="create"><label>Název<input
          name="name" placeholder="Škoda Elroq 85" required></label><label>VIN<input name="vin" maxlength="32" required></label><label>Aktuálně
        využitelná kapacita (kWh)<input name="battery_kwh" type="number" step="0.1" min="1" value="77" required></label><label>Nominální kapacita
        nového vozu (kWh)<input name="battery_nominal_kwh" type="number" step="0.1" min="1" value="77" required></label><label>SoH z diagnostiky
        (%)<input name="soh_manual_pct" type="number" step="0.1" min="50" max="110" placeholder="např. 96,5"></label><label>Domácí lokalita<input
          name="home_label" placeholder="Vilémov 173"></label>
      <button class="btn primary">Přidat vozidlo</button>
    </form>
  </section>
  <?php foreach ($vehicles as $v): ?>
    <section class="card admin-card vehicle-admin">
    <div class="admin-title">
      <div><h2>🚙 <?= h($v['name']) ?></h2>
        <p>VIN <?= h($v['vin']) ?> · <?= $v['trip_count'] ?>
          jízd<?php if ($v['soh_manual_pct'] !== NULL): ?> · SoH <?= cz((float)$v['soh_manual_pct'], 1) ?> %<?php endif; ?></p></div>
      <a class="btn" href="index.php?vehicle_id=<?= $v['id'] ?>">Otevřít dashboard</a></div>
    <div class="admin-columns">
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="update"><input type="hidden"
                                                                                                                                    name="id"
                                                                                                                                    value="<?= $v['id'] ?>"><label>Název<input
            name="name" value="<?= h($v['name']) ?>" required></label><label>VIN<input name="vin" value="<?= h($v['vin']) ?>" required></label><label>Aktuálně
          využitelná kapacita (kWh)<input name="battery_kwh" type="number" step="0.1" value="<?= h((string)$v['battery_kwh']) ?>"
                                          required></label><label>Nominální kapacita nového vozu (kWh)<input name="battery_nominal_kwh" type="number"
                                                                                                             step="0.1"
                                                                                                             value="<?= h((string)($v['battery_nominal_kwh'] ?? $v['battery_kwh'])) ?>"
                                                                                                             required></label><label>SoH z diagnostiky
          (%)<input name="soh_manual_pct" type="number" step="0.1" min="50" max="110" value="<?= h((string)($v['soh_manual_pct'] ?? '')) ?>"><small>Volitelné.
            Přesná hodnota z BMS/diagnostiky má přednost před odhadem z jízd.</small></label><label>Domácí lokalita<input name="home_label"
                                                                                                                          value="<?= h($v['home_label']) ?>"></label>
        <button class="btn primary">Uložit údaje</button>
      </form>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="assign"><input type="hidden"
                                                                                                                                    name="vehicle_id"
                                                                                                                                    value="<?= $v['id'] ?>"><b>Přiřazení
          uživatelům</b><?php foreach ($users as $u): ?><label class="check assignment"><input type="checkbox" name="user_ids[]"
                                                                                               value="<?= $u['id'] ?>" <?= in_array((int)$u['id'], $assigned[(int)$v['id']] ?? [], TRUE) ? 'checked' : '' ?>><span><?= h($u['name']) ?><small><?= h($u['email']) ?></small></span>
          </label><?php endforeach; ?>
        <button class="btn primary">Uložit přiřazení</button>
      </form>
    </div>
    <?php if ($app->auth()->isAdmin($me)): ?>
      <form method="post" onsubmit="return confirm('Smazat vozidlo včetně všech importovaných jízd?')"><input type="hidden" name="csrf"
                                                                                                              value="<?= h(csrfToken()) ?>"><input
        type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>">
      <button class="btn danger">Smazat vozidlo</button></form><?php endif; ?></section><?php endforeach; ?></main>
</body>
</html>

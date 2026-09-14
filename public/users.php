<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  $me = requireAdmin($pdo);
  try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      verifyCsrf();
      $action = (string)($_POST['action'] ?? '');
      if ($action === 'create') {
        $name  = trim((string)$_POST['name']);
        $email = strtolower(trim((string)$_POST['email']));
        $pass  = (string)$_POST['password'];
        $role  = in_array($_POST['role'] ?? '', [
          'admin',
          'manager',
          'user'
        ], TRUE) ? $_POST['role'] : 'user';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
          throw new RuntimeException('Zkontrolujte údaje. Heslo musí mít alespoň 8 znaků.');
        }
        $q = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');
        $q->execute([
          $name,
          $email,
          password_hash($pass, PASSWORD_DEFAULT),
          $role
        ]);
        flash('Uživatel byl vytvořen.');
      } elseif ($action === 'update') {
        $id     = (int)$_POST['id'];
        $name   = trim((string)$_POST['name']);
        $email  = strtolower(trim((string)$_POST['email']));
        $role   = in_array($_POST['role'] ?? '', [
          'admin',
          'manager',
          'user'
        ], TRUE) ? $_POST['role'] : 'user';
        $active = isset($_POST['active']) ? 1 : 0;
        if ($id === (int)$me['id'] && !$active) {
          throw new RuntimeException('Nemůžete deaktivovat vlastní účet.');
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
          throw new RuntimeException('Neplatné údaje.');
        }
        $q = $pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=? WHERE id=?');
        $q->execute([
          $name,
          $email,
          $role,
          $active,
          $id
        ]);
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $_POST['vehicle_ids'] ?? []))));
        $pdo->prepare('DELETE FROM user_vehicles WHERE user_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)');
        foreach ($vehicleIds as $vid) {
          $ins->execute([
            $id,
            $vid
          ]);
        }
        // Běžný uživatel nesmí mít jako výchozí vozidlo takové, které už nemá přiřazené.
        if ($role === 'user') {
          if ($vehicleIds) {
            $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
            $params = array_merge([$id], $vehicleIds);
            $pdo->prepare('UPDATE users SET default_vehicle_id=NULL WHERE id=? AND default_vehicle_id IS NOT NULL AND default_vehicle_id NOT IN (' . $placeholders . ')')->execute($params);
          } else {
            $pdo->prepare('UPDATE users SET default_vehicle_id=NULL WHERE id=?')->execute([$id]);
          }
        }
        flash('Uživatel a jeho vozidla byli upraveni.');
      } elseif ($action === 'reset_link') {
        $id = (int)$_POST['id'];
        $q  = $pdo->prepare('SELECT id,name,email,active FROM users WHERE id=?');
        $q->execute([$id]);
        $u = $q->fetch();
        if (!$u || !(int)$u['active']) {
          throw new RuntimeException('Uživatel neexistuje nebo není aktivní.');
        }
        $token = createPasswordResetToken($pdo, $id);
        $url   = resetUrl($token);
        $sent  = sendPasswordResetEmail($u['email'], $u['name'], $url);
        $msg   = $sent ? 'Odkaz pro obnovu hesla byl odeslán.' : 'Resetovací odkaz byl vytvořen, ale e-mail se nepodařilo odeslat.';
        if (!empty($config['app']['debug'])) {
          $msg .= ' ' . $url;
        }
        flash($msg, $sent ? 'ok' : 'error');
      } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === (int)$me['id']) {
          throw new RuntimeException('Nemůžete smazat vlastní účet.');
        }
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        flash('Uživatel byl smazán.');
      }
      redirect('users.php');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    flash($e->getMessage(), 'error');
    redirect('users.php');
  }
  $users    = $pdo->query('SELECT u.*, (SELECT COUNT(*) FROM user_vehicles uv WHERE uv.user_id=u.id) vehicle_count FROM users u ORDER BY u.name')->fetchAll();
  $vehicles = $pdo->query('SELECT id,name,vin FROM vehicles ORDER BY name')->fetchAll();
  $assigned = [];
  foreach ($pdo->query('SELECT user_id,vehicle_id FROM user_vehicles') as $r) {
    $assigned[(int)$r['user_id']][] = (int)$r['vehicle_id'];
  }
  $flash = getFlash();
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Uživatelé – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar"><a class="brand" href="index.php">⚡</a><b>Správa uživatelů</b>
  <div class="spacer"></div>
  <a class="toplink" href="vehicles.php">Vozidla</a><a class="toplink" href="profile.php">Můj profil</a><a class="toplink"
                                                                                                           href="index.php">Dashboard</a><a
    class="toplink" href="logout.php">Odhlásit</a></header>
<main class="wrap narrow"><?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <section class="card admin-card"><h2>➕ Nový uživatel</h2>
    <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                                value="create"><label>Jméno<input
          name="name" required></label><label>E-mail<input name="email" type="email" required></label><label>Heslo<input name="password"
                                                                                                                         type="password" minlength="8"
                                                                                                                         required></label><label>Role<select
          name="role">
          <option value="user">Řidič</option>
          <option value="manager">Správce vozidel</option>
          <option value="admin">Administrátor</option>
        </select></label>
      <button class="btn primary" type="submit">Přidat uživatele</button>
    </form>
  </section>
  <?php foreach ($users as $u): ?>
    <section class="card admin-card">
    <div class="admin-title">
      <div><h2>👤 <?= h($u['name']) ?></h2>
        <p><?= h($u['email']) ?> · <?= $u['vehicle_count'] ?> vozidel · <?= h($u['role']) ?></p></div>
      <span class="pill"><?= (int)$u['active'] ? 'Aktivní' : 'Neaktivní' ?></span></div>
    <form method="post" class="user-detail-grid"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                                       value="update"><input
        type="hidden" name="id" value="<?= $u['id'] ?>">
      <div class="stack"><label>Jméno<input name="name" value="<?= h($u['name']) ?>" required></label><label>E-mail<input name="email" type="email"
                                                                                                                          value="<?= h($u['email']) ?>"
                                                                                                                          required></label><label>Role<select
            name="role">
            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>Řidič</option>
            <option value="manager" <?= $u['role'] === 'manager' ? 'selected' : '' ?>>Správce vozidel</option>
            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrátor</option>
          </select></label><label class="check"><input type="checkbox" name="active" <?= (int)$u['active'] ? 'checked' : '' ?>> aktivní účet</label>
        <button class="btn primary">Uložit profil a přiřazení</button>
      </div>
      <div><b>Přiřazená vozidla</b>
        <div class="vehicle-check-grid"><?php foreach ($vehicles as $v): ?><label class="check assignment"><input type="checkbox" name="vehicle_ids[]"
                                                                                                                  value="<?= $v['id'] ?>" <?= in_array((int)$v['id'], $assigned[(int)$u['id']] ?? [], TRUE) ? 'checked' : '' ?>><span><?= h($v['name']) ?><small><?= h($v['vin']) ?></small></span>
            </label><?php endforeach; ?><?php if (!$vehicles): ?><p>Nejsou založena žádná vozidla.</p><?php endif; ?></div>
      </div>
    </form>
    <div class="admin-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="reset_link"><input
          type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="btn">🔑 Poslat obnovu hesla</button>
      </form><?php if ((int)$u['id'] !== (int)$me['id']): ?>
        <form method="post" onsubmit="return confirm('Opravdu smazat uživatele?')"><input type="hidden" name="csrf"
                                                                                          value="<?= h(csrfToken()) ?>"><input type="hidden"
                                                                                                                               name="action"
                                                                                                                               value="delete"><input
          type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="btn danger">Smazat uživatele</button></form><?php endif; ?></div></section><?php endforeach; ?></main>
</body>
</html>

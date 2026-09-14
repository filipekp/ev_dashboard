<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  $me = requireLogin($pdo);
  try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      verifyCsrf();
      $action = (string)($_POST['action'] ?? '');
      if ($action === 'profile') {
        $name  = trim((string)$_POST['name']);
        $email = strtolower(trim((string)$_POST['email']));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
          throw new RuntimeException('Zkontrolujte jméno a e-mail.');
        }
        $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([
          $name,
          $email,
          (int)$me['id']
        ]);
        flash('Profil byl uložen.');
      } elseif ($action === 'default_vehicle') {
        $vehicleId = (int)($_POST['default_vehicle_id'] ?? 0);
        if ($vehicleId > 0 && !canAccessVehicle($pdo, $me, $vehicleId)) {
          throw new RuntimeException('Toto vozidlo nemáte k dispozici.');
        }
        $pdo->prepare('UPDATE users SET default_vehicle_id=? WHERE id=?')->execute([
          $vehicleId > 0 ? $vehicleId : NULL,
          (int)$me['id']
        ]);
        // Pokud se výchozí vozidlo změnilo, použije se i při příštím otevření dashboardu v této relaci.
        unset($_SESSION['vehicle_id']);
        flash($vehicleId > 0 ? 'Výchozí vozidlo bylo nastaveno.' : 'Výchozí vozidlo bylo zrušeno.');
      } elseif ($action === 'password') {
        $old   = (string)$_POST['old_password'];
        $new   = (string)$_POST['new_password'];
        $again = (string)$_POST['new_password_again'];
        $q     = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $q->execute([(int)$me['id']]);
        $hash = (string)$q->fetchColumn();
        if (!password_verify($old, $hash)) {
          throw new RuntimeException('Současné heslo není správné.');
        }
        if (strlen($new) < 8) {
          throw new RuntimeException('Nové heslo musí mít alespoň 8 znaků.');
        }
        if ($new !== $again) {
          throw new RuntimeException('Nová hesla se neshodují.');
        }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
          password_hash($new, PASSWORD_DEFAULT),
          (int)$me['id']
        ]);
        session_regenerate_id(TRUE);
        flash('Heslo bylo změněno.');
      }
      redirect('profile.php');
    }
  } catch (Throwable $e) {
    flash($e->getMessage(), 'error');
    redirect('profile.php');
  }
  $q = $pdo->prepare('SELECT id,name,email,role,default_vehicle_id FROM users WHERE id=?');
  $q->execute([(int)$me['id']]);
  $me       = $q->fetch();
  $vehicles = allowedVehicles($pdo, $me);
  $flash    = getFlash();
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Můj profil – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar"><a class="brand" href="index.php">⚡</a><b>Můj profil</b>
  <div class="spacer"></div>
  <a class="toplink" href="index.php">Dashboard</a><a class="toplink" href="logout.php">Odhlásit</a></header>
<main class="wrap profile-wrap"><?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="grid2">
    <section class="card"><h2>👤 Profil</h2>
      <p>Role: <?= h($me['role']) ?></p>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="profile"><label>Jméno<input
            name="name" value="<?= h($me['name']) ?>" required></label><label>E-mail<input name="email" type="email" value="<?= h($me['email']) ?>"
                                                                                           required></label>
        <button class="btn primary">Uložit profil</button>
      </form>
    </section>
    <section class="card"><h2>🚙 Výchozí vozidlo</h2>
      <p>Zvolené vozidlo se automaticky otevře po každém přihlášení.</p>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="default_vehicle"><label>Výchozí vozidlo<select name="default_vehicle_id">
            <option value="0">Automaticky (první dostupné)</option>
            <?php foreach ($vehicles as $v): ?><option value="<?= $v['id'] ?>" <?= (int)($me['default_vehicle_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['name']) ?> · <?= h($v['vin']) ?></option><?php endforeach; ?>
          </select></label>
        <?php if (!$vehicles): ?><p>Nemáte k dispozici žádné vozidlo.</p><?php endif; ?>
        <button class="btn primary" <?= !$vehicles ? 'disabled' : '' ?>>Uložit výchozí vozidlo</button>
      </form>
    </section>
    <section class="card"><h2>🔐 Změna hesla</h2>
      <p>Po změně zůstane aktuální relace přihlášena.</p>
      <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action"
                                                                                                              value="password"><label>Současné
          heslo<input type="password" name="old_password" required autocomplete="current-password"></label><label>Nové heslo<input type="password"
                                                                                                                                   name="new_password"
                                                                                                                                   minlength="8"
                                                                                                                                   required
                                                                                                                                   autocomplete="new-password"></label><label>Nové
          heslo znovu<input type="password" name="new_password_again" minlength="8" required autocomplete="new-password"></label>
        <button class="btn primary">Změnit heslo</button>
      </form>
    </section>
  </div>
</main>
</body>
</html>

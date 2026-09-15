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

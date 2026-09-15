<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Uživatelé – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=20260915-digital-garage">
</head>
<body>
<?php $navTitle = 'Správa uživatelů'; require __DIR__ . '/partials/navigation.php'; ?>
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

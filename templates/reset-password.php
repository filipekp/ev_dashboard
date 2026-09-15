<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Nové heslo – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-body">
<main class="auth-card">
  <div class="auth-logo">🔐</div>
  <h1>Nastavení nového hesla</h1><?php if ($error): ?>
    <div class="error"><?= h($error) ?></div><?php endif; ?><?php if (!$row): ?>
    <div class="error">Odkaz je neplatný nebo již vypršel.</div><a class="btn" href="forgot-password.php">Požádat o nový odkaz</a><?php else: ?><p>
    Účet: <?= h($row['email']) ?></p>
    <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="token"
                                                                                                            value="<?= h($token) ?>"><label>Nové heslo<input
        type="password" name="password" minlength="8" required autofocus></label><label>Nové heslo znovu<input type="password" name="password_again"
                                                                                                               minlength="8" required></label>
    <button class="btn primary">Uložit nové heslo</button></form><?php endif; ?></main>
</body>
</html>

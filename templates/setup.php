<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1"><?php require __DIR__ . '/partials/pwa-head.php'; ?>
  <title>První nastavení – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-card">
  <div class="auth-logo">⚡</div>
  <h1>První nastavení</h1>
  <p>Vytvořte první administrátorský účet.</p><?php if ($error): ?>
    <div class="error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><label>Jméno<input name="name" required
                                                                                                                      autocomplete="name"></label><label>E-mail<input
        name="email" type="email" required autocomplete="email"></label><label>Heslo<input name="password" type="password" required minlength="8"
                                                                                           autocomplete="new-password"></label>
    <button class="btn primary" type="submit">Vytvořit administrátora</button>
  </form>
</main>
</body>
</html>

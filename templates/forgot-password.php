<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1"><?php require __DIR__ . '/partials/pwa-head.php'; ?>
  <title>Zapomenuté heslo – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-card">
  <div class="auth-logo">🔑</div>
  <h1>Zapomenuté heslo</h1>
  <p>Zadejte e-mail účtu. Odkaz je platný 60 minut.</p><?php if ($message): ?>
    <div class="notice"><?= h($message) ?></div><?php endif; ?><?php if ($debugUrl): ?>
    <div class="debug-link"><b>DEV reset odkaz:</b><br><a href="<?= h($debugUrl) ?>"><?= h($debugUrl) ?></a></div><?php endif; ?>
  <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><label>E-mail<input name="email" type="email"
                                                                                                                       required autofocus></label>
    <button class="btn primary">Odeslat odkaz</button>
    <a class="auth-link" href="login.php">← Zpět na přihlášení</a></form>
</main>
</body>
</html>

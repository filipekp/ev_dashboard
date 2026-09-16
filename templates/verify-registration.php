<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?php require __DIR__ . '/partials/pwa-head.php'; ?>
  <title>Potvrzení registrace – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-card">
  <div class="auth-logo"><?= $success ? '✓' : '!' ?></div>
  <h1>Potvrzení registrace</h1>
  <?php if ($success): ?>
    <div class="notice"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>
  <a class="btn primary auth-action" href="login.php">Přejít na přihlášení</a>
</main>
</body>
</html>

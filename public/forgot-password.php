<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  if (!$app->auth()->usersExist()) {
    redirect('setup.php');
  }
  $message  = '';
  $debugUrl = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
      verifyCsrf();
      $email = strtolower(trim((string)($_POST['email'] ?? '')));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Zadejte platný e-mail.');
      }
      $q = $pdo->prepare('SELECT id,name,email,active FROM users WHERE email=? LIMIT 1');
      $q->execute([$email]);
      $u = $q->fetch();
      if ($u && (int)$u['active']) {
        $token = $app->auth()->createPasswordResetToken((int)$u['id']);
        $url   = $app->auth()->resetUrl($token);
        $app->auth()->sendPasswordResetEmail($u['email'], $u['name'], $url);
        if (!empty($config['app']['debug'])) {
          $debugUrl = $url;
        }
      }
      $message = 'Pokud účet s tímto e-mailem existuje, poslali jsme odkaz pro nastavení nového hesla.';
    } catch (Throwable $e) {
      $message = $e->getMessage();
    }
  }
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Zapomenuté heslo – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
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

<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  if ($app->auth()->usersExist()) {
    redirect('login.php');
  }
  $error = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
      verifyCsrf();
      $name     = trim((string)($_POST['name'] ?? ''));
      $email    = strtolower(trim((string)($_POST['email'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Vyplňte jméno a platný e-mail.');
      }
      if (strlen($password) < 8) {
        throw new RuntimeException('Heslo musí mít alespoň 8 znaků.');
      }
      $q = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');
      $q->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        'admin'
      ]);
      $_SESSION['user_id'] = (int)$pdo->lastInsertId();
      session_regenerate_id(TRUE);
      redirect('index.php');
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>První nastavení – EV Stats</title>
  <link rel="stylesheet" href="assets/app.css">
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

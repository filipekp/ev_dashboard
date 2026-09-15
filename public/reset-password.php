<?php
  declare(strict_types=1);
  require dirname(__DIR__) . '/src/bootstrap.php';
  $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
  $row   = NULL;
  $error = '';
  if ($token !== '') {
    $q = $pdo->prepare('SELECT prt.id,prt.user_id,u.email FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id WHERE prt.token_hash=? AND prt.used_at IS NULL AND prt.expires_at>NOW() AND u.active=1 LIMIT 1');
    $q->execute([hash('sha256', $token)]);
    $row = $q->fetch();
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
      verifyCsrf();
      if (!$row) {
        throw new RuntimeException('Odkaz je neplatný nebo již vypršel.');
      }
      $pass = (string)$_POST['password'];
      $again = (string)$_POST['password_again'];
      if (strlen($pass) < 8) {
        throw new RuntimeException('Heslo musí mít alespoň 8 znaků.');
      }
      if ($pass !== $again) {
        throw new RuntimeException('Hesla se neshodují.');
      }
      $pdo->beginTransaction();
      $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
        password_hash($pass, PASSWORD_DEFAULT),
        (int)$row['user_id']
      ]);
      $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$row['user_id']]);
      $pdo->commit();
      flash('Heslo bylo změněno. Nyní se můžete přihlásit.');
      redirect('login.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $error = $e->getMessage();
    }
  }
?>
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

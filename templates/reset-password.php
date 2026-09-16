<?php
$pageTitle = 'Nové heslo';
$bodyClass = 'auth-body';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
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

<?php require __DIR__ . '/partials/footer.php'; ?>

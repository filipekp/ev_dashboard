<?php
$pageTitle = 'Zapomenuté heslo';
$bodyClass = 'auth-body';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
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

<?php require __DIR__ . '/partials/footer.php'; ?>

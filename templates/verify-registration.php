<?php
$pageTitle = 'Potvrzení registrace';
$bodyClass = 'auth-body';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
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

<?php require __DIR__ . '/partials/footer.php'; ?>

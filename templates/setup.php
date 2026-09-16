<?php
$pageTitle = 'První nastavení';
$bodyClass = 'auth-body';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
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

<?php require __DIR__ . '/partials/footer.php'; ?>

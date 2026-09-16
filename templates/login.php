<?php
$pageTitle = 'Přihlášení';
$bodyClass = 'auth-body';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
<main class="auth-card"><div class="auth-logo">⚡</div><h1>EV Stats</h1><p>Přihlaste se ke svému přehledu vozidel.</p><?php if($flash):?><div class="notice"><?=h($flash['message'])?></div><?php endif;?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif;?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrfToken())?>"><label>E-mail<input name="email" type="email" required autocomplete="email" autofocus></label><label>Heslo<input name="password" type="password" required autocomplete="current-password"></label><button class="btn primary" type="submit">Přihlásit se</button><a class="auth-link" href="forgot-password.php">Zapomenuté heslo?</a><a class="auth-link" href="register.php">Nemáte účet? Zaregistrovat se</a></form></main>

<?php require __DIR__ . '/partials/footer.php'; ?>

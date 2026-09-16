<?php
$pageTitle = 'Registrace';
$bodyClass = 'auth-body';
$showNavigation = false;
$pageHead = '';
if ($recaptchaSiteKey !== '') {
    $pageHead = '<script src="https://www.google.com/recaptcha/api.js?render=' . h($recaptchaSiteKey) . '"></script>';
}
require __DIR__ . '/partials/header.php';
?>
<main class="auth-card auth-card-wide">
  <div class="auth-logo">👤</div>
  <h1>Vytvořit účet</h1>
  <p>Po registraci vám pošleme e-mail s odkazem pro aktivaci účtu.</p>

  <?php if ($success): ?>
    <div class="notice"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($debugUrl): ?>
    <div class="debug-link"><b>DEV potvrzovací odkaz:</b><br><a href="<?= h($debugUrl) ?>"><?= h($debugUrl) ?></a></div>
  <?php endif; ?>

  <?php if (!$success): ?>
    <form method="post" class="stack" id="registrationForm">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="recaptcha_token" id="recaptchaToken" value="">

      <div class="registration-honeypot" aria-hidden="true">
        <label>Web<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <label>Jméno a příjmení
        <input name="name" type="text" required maxlength="120" autocomplete="name" autofocus value="<?= h((string)$values['name']) ?>">
      </label>
      <label>E-mail
        <input name="email" type="email" required maxlength="190" autocomplete="email" value="<?= h((string)$values['email']) ?>">
      </label>
      <label>Heslo
        <input name="password" type="password" required minlength="8" autocomplete="new-password">
      </label>
      <label>Heslo znovu
        <input name="password_again" type="password" required minlength="8" autocomplete="new-password">
      </label>

      <button class="btn primary" type="submit" id="registerButton">Vytvořit účet</button>
      <a class="auth-link" href="login.php">← Zpět na přihlášení</a>
    </form>

    <?php if ($recaptchaSiteKey !== ''): ?>
      <p class="recaptcha-note">Tento web je chráněn službou reCAPTCHA a platí zásady ochrany soukromí a smluvní podmínky Google.</p>
      <script nonce="<?= h(cspNonce()) ?>">
        (() => {
          const form = document.getElementById('registrationForm');
          const tokenInput = document.getElementById('recaptchaToken');
          const button = document.getElementById('registerButton');
          const siteKey = <?= json_encode($recaptchaSiteKey, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
          let submitting = false;

          form.addEventListener('submit', (event) => {
            if (submitting) return;
            event.preventDefault();
            button.disabled = true;
            button.textContent = 'Ověřuji…';

            grecaptcha.ready(() => {
              grecaptcha.execute(siteKey, {action: 'register'}).then((token) => {
                tokenInput.value = token;
                submitting = true;
                form.submit();
              }).catch(() => {
                button.disabled = false;
                button.textContent = 'Vytvořit účet';
                alert('Ověření reCAPTCHA se nepodařilo. Zkuste to prosím znovu.');
              });
            });
          });
        })();
      </script>
    <?php endif; ?>
  <?php else: ?>
    <a class="btn primary auth-action" href="login.php">Přejít na přihlášení</a>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

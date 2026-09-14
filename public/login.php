<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (!usersExist($pdo)) redirect('setup.php');
if (currentUser($pdo)) redirect('index.php');
$error=''; $flash=getFlash();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        verifyCsrf();
        $email=strtolower(trim((string)($_POST['email']??'')));
        $password=(string)($_POST['password']??'');
        $q=$pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $q->execute([$email]); $u=$q->fetch();
        if (!$u || !(int)$u['active'] || !password_verify($password,$u['password_hash'])) throw new RuntimeException('Neplatný e-mail nebo heslo.');
        $_SESSION['user_id']=(int)$u['id']; unset($_SESSION['vehicle_id']); session_regenerate_id(true); redirect('index.php');
    } catch(Throwable $e){$error=$e->getMessage();}
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Přihlášení – EV Stats</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-body"><main class="auth-card"><div class="auth-logo">⚡</div><h1>EV Stats</h1><p>Přihlaste se ke svému přehledu vozidel.</p><?php if($flash):?><div class="notice"><?=h($flash['message'])?></div><?php endif;?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif;?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrfToken())?>"><label>E-mail<input name="email" type="email" required autocomplete="email" autofocus></label><label>Heslo<input name="password" type="password" required autocomplete="current-password"></label><button class="btn primary" type="submit">Přihlásit se</button><a class="auth-link" href="forgot-password.php">Zapomenuté heslo?</a></form></main></body></html>

<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Přihlášení uživatele s ochranou proti brute-force útokům.
 */
final class LoginController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        if (!$this->app->auth()->usersExist()) {
            Http::redirect('setup.php');
        }
        if ($this->app->auth()->currentUser()) {
            Http::redirect('index.php');
        }

        $error = '';
        $flash = $this->app->session()->pullFlash();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->app->session()->verifyCsrf();
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $rateKey = 'login-ip:' . RateLimiter::clientIp();
                $limit = max(3, (int)$this->app->config()->get('security.login_attempts', 10));
                $window = max(60, (int)$this->app->config()->get('security.login_window_seconds', 900));

                if (!$this->app->rateLimiter()->consume($rateKey, $limit, $window)) {
                    throw new RuntimeException('Příliš mnoho pokusů o přihlášení. Zkuste to později.');
                }

                $query = $this->app->pdo()->prepare(
                    'SELECT id,password_hash,active FROM users WHERE email=? LIMIT 1'
                );
                $query->execute([$email]);
                $user = $query->fetch();

                if (
                    !$user
                    || !(int)$user['active']
                    || !password_verify($password, (string)$user['password_hash'])
                ) {
                    throw new RuntimeException('Neplatný e-mail nebo heslo.');
                }

                $this->app->rateLimiter()->clear($rateKey);
                if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                    $this->app->pdo()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
                        password_hash($password, PASSWORD_DEFAULT),
                        (int)$user['id'],
                    ]);
                }
                session_regenerate_id(true);
                $this->app->session()->rotateCsrf();
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['_last_regenerated'] = time();
                unset($_SESSION['vehicle_id']);
                Http::redirect('index.php');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->app->template()->render('login', [
            'app' => $this->app,
            'error' => $error,
            'flash' => $flash,
        ]);
    }
}

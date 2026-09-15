<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Handles user login requests.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
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
                $q = $this->app->pdo()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
                $q->execute([$email]);
                $u = $q->fetch();
                if (!$u || !(int)$u['active'] || !password_verify($password, $u['password_hash'])) {
                    throw new RuntimeException('Neplatný e-mail nebo heslo.');
                }
                $_SESSION['user_id'] = (int)$u['id'];
                unset($_SESSION['vehicle_id']);
                session_regenerate_id(true);
                Http::redirect('index.php');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $this->app->template()->render('login', ['app' => $this->app, 'error' => $error, 'flash' => $flash]);
    }
}

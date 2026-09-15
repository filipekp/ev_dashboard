<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Handles first-run administrator setup.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class SetupController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        if ($this->app->auth()->usersExist()) {
            Http::redirect('login.php');
        }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->app->session()->verifyCsrf();
                $name = trim((string)($_POST['name'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Vyplňte jméno a platný e-mail.');
                }
                if (strlen($password) < 8) {
                    throw new RuntimeException('Heslo musí mít alespoň 8 znaků.');
                }
                $q = $this->app->pdo()->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');
                $q->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
                $_SESSION['user_id'] = (int)$this->app->pdo()->lastInsertId();
                session_regenerate_id(true);
                Http::redirect('index.php');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $this->app->template()->render('setup', ['app' => $this->app, 'error' => $error]);
    }
}

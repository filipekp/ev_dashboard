<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Jednorázové založení prvního administrátorského účtu.
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
                $minimumLength = max(8, (int)$this->app->config()->get('security.minimum_password_length', 12));

                if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Vyplňte jméno a platný e-mail.');
                }
                if (strlen($password) < $minimumLength) {
                    throw new RuntimeException('Heslo musí mít alespoň ' . $minimumLength . ' znaků.');
                }

                $query = $this->app->pdo()->prepare(
                    'INSERT INTO users(name,email,password_hash,role,active,email_verified_at) '
                    . 'VALUES(?,?,?,?,1,NOW())'
                );
                $query->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    'admin',
                ]);

                session_regenerate_id(true);
                $this->app->session()->rotateCsrf();
                $_SESSION['user_id'] = (int)$this->app->pdo()->lastInsertId();
                $_SESSION['_last_regenerated'] = time();
                Http::redirect('index.php');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->app->template()->render('setup', [
            'app' => $this->app,
            'error' => $error,
        ]);
    }
}

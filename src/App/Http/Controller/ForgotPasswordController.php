<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\RateLimiter;
use Throwable;

/**
 * Zahájení obnovy hesla bez prozrazení existence účtu.
 */
final class ForgotPasswordController
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

        $message = '';
        $debugUrl = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->app->session()->verifyCsrf();
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $rateKey = 'password-reset-ip:' . RateLimiter::clientIp();
                $limit = max(2, (int)$this->app->config()->get('security.password_reset_attempts', 5));
                $window = max(300, (int)$this->app->config()->get('security.password_reset_window_seconds', 3600));

                if ($this->app->rateLimiter()->consume($rateKey, $limit, $window) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $query = $this->app->pdo()->prepare(
                        'SELECT id,name,email,active FROM users WHERE email=? LIMIT 1'
                    );
                    $query->execute([$email]);
                    $user = $query->fetch();

                    if ($user && (int)$user['active']) {
                        $token = $this->app->auth()->createPasswordResetToken((int)$user['id']);
                        $url = $this->app->auth()->resetUrl($token);
                        $this->app->auth()->sendPasswordResetEmail(
                            (string)$user['email'],
                            (string)$user['name'],
                            $url
                        );
                        if ((bool)$this->app->config()->get('app.debug', false)) {
                            $debugUrl = $url;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Veřejná odpověď je záměrně stejná pro neexistující účet,
                // rate-limit i interní chybu, aby endpoint nešel použít k enumeraci účtů.
            }

            $message = 'Pokud účet s tímto e-mailem existuje, poslali jsme odkaz pro nastavení nového hesla.';
        }

        $this->app->template()->render('forgot-password', [
            'app' => $this->app,
            'message' => $message,
            'debugUrl' => $debugUrl,
        ]);
    }
}

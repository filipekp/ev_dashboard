<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Veřejný registrační formulář.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class RegisterController
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
        $success = '';
        $debugUrl = '';
        $values = [
            'name' => '',
            'email' => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->app->session()->verifyCsrf();

                // Jednoduchý honeypot pro roboty, který běžný uživatel nevidí.
                if (trim((string)($_POST['website'] ?? '')) !== '') {
                    throw new RuntimeException('Registraci se nepodařilo ověřit.');
                }

                $values['name'] = trim((string)($_POST['name'] ?? ''));
                $values['email'] = strtolower(trim((string)($_POST['email'] ?? '')));

                $result = $this->app->registration()->register(
                    $values['name'],
                    $values['email'],
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['password_again'] ?? ''),
                    (string)($_POST['recaptcha_token'] ?? ''),
                    $this->clientIp()
                );

                $success = 'Registrace byla vytvořena. Na váš e-mail jsme poslali potvrzovací odkaz. Účet se aktivuje až po jeho otevření.';
                $values = ['name' => '', 'email' => ''];

                if ((bool)$this->app->config()->get('app.debug', false)) {
                    $debugUrl = (string)$result['verification_url'];
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->app->template()->render('register', [
            'app' => $this->app,
            'error' => $error,
            'success' => $success,
            'debugUrl' => $debugUrl,
            'values' => $values,
            'recaptchaSiteKey' => (string)$this->app->config()->get('recaptcha.site_key', ''),
        ]);
    }

    private function clientIp(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}

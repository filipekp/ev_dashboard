<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;

/** Ukončení uživatelské relace a odstranění session cookie. */
final class LogoutController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $pwaContext = (string)($_GET['pwa'] ?? '') === '1';

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string)$params['path'],
                'domain' => (string)$params['domain'],
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => (string)($params['samesite'] ?? 'Lax'),
            ]);
        }
        session_destroy();
        Http::redirect($pwaContext ? 'login.php?pwa=1' : 'index.php');
    }
}

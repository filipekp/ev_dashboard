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
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                (string)$params['path'],
                (string)$params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        session_destroy();
        Http::redirect('index.php');
    }
}

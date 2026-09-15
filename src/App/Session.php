<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Manages the application session, flash messages and CSRF tokens.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class Session
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string)$this->config->get('app.session_name', 'ev_stats'));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $isHttps, 'path' => '/', ]);
        session_start();
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public function verifyCsrf(?string $token = null): void
    {
        $sessionToken = (string)($_SESSION['csrf'] ?? '');
        $requestToken = $token !== null?$token:(string)($_POST['csrf'] ?? '');
        if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
            throw new RuntimeException('Neplatný bezpečnostní token.');
        }
    }

    public function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    /** @return array<string,string>|null */
    public function pullFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_array($flash)?$flash:null;
    }
}

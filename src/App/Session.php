<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Třída Session.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class Session
{
    /** @var Config */
    private $config;

    /** @var string|null */
    private $cspNonce;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = SecurityHeaders::isHttps();
        $sessionLifetime = max(2592000, (int)$this->config->get('security.session_lifetime_seconds', 2592000));

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
        ini_set('session.cookie_lifetime', (string)$sessionLifetime);

        session_name((string)$this->config->get('app.session_name', 'ev_stats'));
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isHttps,
            'path' => '/',
            'lifetime' => $sessionLifetime,
        ]);
        session_start();

        $now = time();
        $lastActivity = (int)($_SESSION['_last_activity'] ?? $now);
        // Požadovaná relace má vydržet alespoň 30 dní. Starší instalace mohou
        // stále obsahovat SESSION_IDLE_SECONDS=43200; proto nesmí tento legacy
        // parametr zkrátit novou minimální životnost relace.
        $configuredIdleTimeout = (int)$this->config->get('security.session_idle_seconds', $sessionLifetime);
        $idleTimeout = max($sessionLifetime, $configuredIdleTimeout);
        if (($now - $lastActivity) > $idleTimeout) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_activity'] = $now;

        $lastRegenerated = (int)($_SESSION['_last_regenerated'] ?? 0);
        if ($lastRegenerated === 0 || ($now - $lastRegenerated) > 1800) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerated'] = $now;
        }

        // Obnovujeme expiraci při každém aktivním requestu. PWA tak zůstane
        // přihlášená 30 dní od posledního použití, ne pouze 30 dní od loginu.
        if (!headers_sent()) {
            setcookie(session_name(), session_id(), [
                'expires' => $now + $sessionLifetime,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function cspNonce(): string
    {
        if ($this->cspNonce === null) {
            $this->cspNonce = base64_encode(random_bytes(24));
        }

        return $this->cspNonce;
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public function rotateCsrf(): string
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return (string)$_SESSION['csrf'];
    }

    public function verifyCsrf(?string $token = null): void
    {
        $sessionToken = (string)($_SESSION['csrf'] ?? '');
        $requestToken = $token !== null ? $token : (string)($_POST['csrf'] ?? '');
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
        return is_array($flash) ? $flash : null;
    }
}

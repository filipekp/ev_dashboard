<?php
    
    declare(strict_types=1);
    
    $config = require __DIR__ . '/config.php';
    
    /*
     * --------------------------------------------------------------------------
     * Error handling
     * --------------------------------------------------------------------------
     */
    
    $appEnv   = $config['app']['env'] ?? 'production';
    $appDebug = (bool)($config['app']['debug'] ?? false);
    
    error_reporting(E_ALL);
    
    if ($appDebug === true || $appEnv !== 'production') {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
    } else {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
    }
    
    /*
     * --------------------------------------------------------------------------
     * Session
     * --------------------------------------------------------------------------
     */
    
    if (session_status() !== PHP_SESSION_ACTIVE) {
        
        $sessionName = $config['app']['session_name'] ?? 'ev_stats';
        
        session_name($sessionName);
        
        $isHttps =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $isHttps,
            'path'     => '/',
        ]);
        
        session_start();
    }
    
    /*
     * --------------------------------------------------------------------------
     * Database
     * --------------------------------------------------------------------------
     */
    
    $dbHost = (string)($config['db']['host'] ?? '');
    $dbPort = (int)($config['db']['port'] ?? 3306);
    $dbName = (string)($config['db']['name'] ?? '');
    $dbUser = (string)($config['db']['user'] ?? '');
    $dbPass = (string)($config['db']['pass'] ?? '');
    
    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        throw new RuntimeException(
            'Chybí konfigurace databáze. Zkontrolujte soubor .env.'
        );
    }
    
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    );
    
    try {
        
        $pdo = new PDO(
            $dsn,
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        
    } catch (PDOException $e) {
        
        /*
         * Skutečnou chybu zapíšeme do PHP error logu.
         * Návštěvníkovi produkčního webu ji ale nezobrazujeme.
         */
        error_log(
            'EV Stats database connection error: ' . $e->getMessage()
        );
        
        if ($appDebug === true || $appEnv !== 'production') {
            throw $e;
        }
        
        http_response_code(500);
        
        exit(
            'Nepodařilo se připojit k databázi. ' .
            'Zkontrolujte prosím konfiguraci databáze.'
        );
    }
    
    /*
     * --------------------------------------------------------------------------
     * Helper functions
     * --------------------------------------------------------------------------
     */
    
    function h(?string $value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
    
    function cz($n, int $dec = 1): string
    {
        return number_format(
            (float)$n,
            $dec,
            ',',
            ' '
        );
    }
    
    function shortAddress(string $s): string
    {
        return preg_replace(
            '/,\s*Czechia$/u',
            '',
            $s
        ) ?? $s;
    }
    
    function routeKey(string $from, string $to): string
    {
        return shortAddress($from)
            . ' → '
            . shortAddress($to);
    }
    
    function displayRoute(?string $from, ?string $to): string
    {
        $from = trim((string)$from);
        $to   = trim((string)$to);
        
        if ($from === '' && $to === '') {
            return 'Bez údajů o trase';
        }
        
        return routeKey(
            $from !== '' ? $from : '—',
            $to !== '' ? $to : '—'
        );
    }
    
    function parseCoord(?string $s): array
    {
        if (!$s || strpos($s, ',') === false) {
            return [
                null,
                null,
            ];
        }
        
        [$a, $b] = array_map(
            'trim',
            explode(',', $s, 2)
        );
        
        return [
            is_numeric($a) ? (float)$a : null,
            is_numeric($b) ? (float)$b : null,
        ];
    }
    
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
    
    function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['flash'] = [
            'message' => $message,
            'type'    => $type,
        ];
    }
    
    function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        
        unset($_SESSION['flash']);
        
        return $flash;
    }
    
    function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(
                random_bytes(32)
            );
        }
        
        return $_SESSION['csrf'];
    }
    
    function verifyCsrf(): void
    {
        $sessionToken = (string)($_SESSION['csrf'] ?? '');
        $requestToken = (string)($_POST['csrf'] ?? '');
        
        if (
            $sessionToken === ''
            || $requestToken === ''
            || !hash_equals($sessionToken, $requestToken)
        ) {
            throw new RuntimeException(
                'Neplatný bezpečnostní token.'
            );
        }
    }
    
    /*
     * --------------------------------------------------------------------------
     * Authentication
     * --------------------------------------------------------------------------
     */
    
    require_once __DIR__ . '/auth.php';
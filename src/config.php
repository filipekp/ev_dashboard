<?php

declare(strict_types=1);

/**
 * Loads KEY=VALUE pairs from a simple .env file.
 *
 * @return array<string, string>
 */
function loadEnv(string $file): array
{
    $env = [];

    if (!is_file($file)) {
        return $env;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $env;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

$envFile = dirname(__DIR__) . '/.env';
$env = loadEnv($envFile);

/**
 * Returns an application environment value with server-level fallbacks.
 *
 * @param mixed $default
 * @return mixed
 */
function env(string $key, $default = null)
{
    global $env;

    if (isset($env[$key])) {
        return $env[$key];
    }

    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    return $default;
}

return [
    'app' => [
        'env' => env('APP_ENV', 'production'),
        'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
        'session_name' => env('SESSION_NAME', 'ev_stats'),
        'name' => env('APP_NAME', 'EV Stats'),
        'base_url' => env('APP_BASE_URL', ''),
    ],
    'db' => [
        'host' => env('DB_HOST', ''),
        'port' => (int)env('DB_PORT', '3306'),
        'name' => env('DB_NAME', ''),
        'user' => env('DB_USER', ''),
        'pass' => env('DB_PASS', ''),
    ],
    'mail' => [
        'from' => env('MAIL_FROM', ''),
    ],
    'update' => [
        'repository' => env('UPDATE_REPOSITORY', 'filipekp/ev_dashboard'),
        'channel' => strtolower((string)env('UPDATE_CHANNEL', 'release')),
        'branch' => env('UPDATE_BRANCH', 'dev'),
    ],
];

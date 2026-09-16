<?php

declare(strict_types=1);

/** @return array<string,string> */
function loadEnv(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $env = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
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
        if ($key === '') {
            continue;
        }

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }

    return $env;
}

$env = loadEnv(dirname(__DIR__) . '/.env');

/** @return mixed */
function env(string $key, $default = null)
{
    global $env;

    if (array_key_exists($key, $env)) {
        return $env[$key];
    }
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }

    return $default;
}

$appEnvironment = strtolower((string)env('APP_ENV', 'production'));
$appDebug = filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
if ($appEnvironment === 'production') {
    // Debug výstupy mohou obsahovat interní informace nebo jednorázové odkazy.
    $appDebug = false;
}

return [
    'app' => [
        'env' => $appEnvironment,
        'debug' => $appDebug,
        'session_name' => env('SESSION_NAME', 'ev_stats'),
        'name' => env('APP_NAME', 'EV Stats'),
        'base_url' => env('APP_BASE_URL', ''),
    ],
    'security' => [
        'session_idle_seconds' => (int)env('SESSION_IDLE_SECONDS', '43200'),
        'login_attempts' => (int)env('LOGIN_RATE_LIMIT_ATTEMPTS', '10'),
        'login_window_seconds' => (int)env('LOGIN_RATE_LIMIT_WINDOW', '900'),
        'password_reset_attempts' => (int)env('PASSWORD_RESET_RATE_LIMIT_ATTEMPTS', '5'),
        'password_reset_window_seconds' => (int)env('PASSWORD_RESET_RATE_LIMIT_WINDOW', '3600'),
        'minimum_password_length' => (int)env('MINIMUM_PASSWORD_LENGTH', '12'),
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
    'demo' => [
        'enabled' => filter_var(env('DEMO_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'email' => strtolower((string)env('DEMO_USER_EMAIL', 'demo@evstats.local')),
    ],
    'registration' => [
        'parent_admin_id' => (int)env('REGISTRATION_PARENT_ADMIN_ID', '1'),
        'admin_notify_email' => env('REGISTRATION_ADMIN_NOTIFY_EMAIL', ''),
        'verification_minutes' => (int)env('REGISTRATION_VERIFICATION_MINUTES', '1440'),
    ],
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY', ''),
        'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
        'minimum_score' => (float)env('RECAPTCHA_MINIMUM_SCORE', '0.5'),
        'expected_hostname' => env('RECAPTCHA_EXPECTED_HOSTNAME', ''),
    ],
    'update' => [
        'repository' => env('UPDATE_REPOSITORY', 'filipekp/ev_dashboard'),
        'channel' => strtolower((string)env('UPDATE_CHANNEL', 'release')),
        'branch' => env('UPDATE_BRANCH', 'dev'),
    ],
    'ai' => [
        'provider' => strtolower((string)env('AI_PROVIDER', 'none')),
        'openai_api_key' => env('OPENAI_API_KEY', ''),
        'openai_model' => env('OPENAI_MODEL', 'gpt-5.6-luna'),
        'gemini_api_key' => env('GEMINI_API_KEY', ''),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    ],
];

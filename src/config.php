<?php
    
    function loadEnv($file)
    {
        $env = array();
        
        if (!is_file($file)) {
            return $env;
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Prázdný řádek nebo komentář
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            
            $pos = strpos($line, '=');
            
            if ($pos === false) {
                continue;
            }
            
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            
            // Odstranění případných uvozovek
            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && substr($value, -1) === '"') ||
                    ($value[0] === "'" && substr($value, -1) === "'")
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
    
    function env($key, $default = null)
    {
        global $env;
        
        if (isset($env[$key])) {
            return $env[$key];
        }
        
        // Umožní fungování i tam, kde jsou proměnné nastavené
        // klasickým způsobem serverem.
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
            'debug' => filter_var(
                env('APP_DEBUG', 'false'),
                FILTER_VALIDATE_BOOLEAN
            ),
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
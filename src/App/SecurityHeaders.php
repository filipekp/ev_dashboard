<?php

declare(strict_types=1);

namespace App;

/**
 * Odesílá bezpečnostní HTTP hlavičky pro každou HTML odpověď aplikace.
 */
final class SecurityHeaders
{
    public static function send(Config $config, Session $session): void
    {
        if (headers_sent()) {
            return;
        }

        $nonce = $session->cspNonce();
        $isHttps = self::isHttps();
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://www.googletagmanager.com https://www.google.com https://www.gstatic.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://www.google-analytics.com",
            "font-src 'self' data:",
            "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://www.google.com https://www.gstatic.com",
            "frame-src https://www.google.com https://www.recaptcha.net",
            "manifest-src 'self'",
            "worker-src 'self'",
        ];

        if ($isHttps && (string)$config->get('app.env', 'production') === 'production') {
            $directives[] = 'upgrade-insecure-requests';
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header('Content-Security-Policy: ' . implode('; ', $directives));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }
}

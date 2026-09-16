<?php

declare(strict_types=1);

namespace App;

/**
 * Společné HTTP utility pro redirecty a bezpečné hlavičky souborových odpovědí.
 */
final class Http
{
    public static function redirect(string $url): void
    {
        // Redirecty v aplikaci jsou relativní. CR/LF by umožnilo header injection.
        if (preg_match('/[\r\n]/', $url)) {
            http_response_code(400);
            exit('Neplatná URL.');
        }

        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function contentDisposition(string $disposition, string $filename): string
    {
        $disposition = $disposition === 'inline' ? 'inline' : 'attachment';
        $filename = UploadValidator::safeOriginalName($filename, 'soubor');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        if (!is_string($ascii) || $ascii === '') {
            $ascii = 'soubor';
        }
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $ascii) ?: 'soubor';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return $disposition
            . '; filename="' . $ascii . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename);
    }

    public static function sendStoredFile(
        string $path,
        string $mime,
        string $filename,
        string $disposition = 'attachment',
        bool $privateCache = false
    ): void {
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit('Soubor nebyl nalezen.');
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: ' . self::contentDisposition($disposition, $filename));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox; default-src \'none\'');
        header($privateCache
            ? 'Cache-Control: private, max-age=86400'
            : 'Cache-Control: private, no-store, max-age=0');

        readfile($path);
        exit;
    }
}

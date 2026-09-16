<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Sdílená validace HTTP uploadů podle skutečného obsahu souboru.
 */
final class UploadValidator
{
    /** @param array<string,mixed> $file */
    public static function uploadedPath(array $file, int $maxBytes): string
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Soubor se nepodařilo nahrát.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Soubor je prázdný nebo překračuje povolenou velikost.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Neplatný HTTP upload.');
        }

        return $tmp;
    }

    public static function mime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string)($finfo->file($path) ?: 'application/octet-stream');
    }

    public static function safeOriginalName(string $name, string $fallback = 'file'): string
    {
        $name = basename(str_replace("\0", '', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: '';
        if ($name === '') {
            return $fallback;
        }

        return mb_substr($name, 0, 180);
    }

    public static function assertImageDimensions(string $path, int $maxPixels = 40000000): void
    {
        $size = @getimagesize($path);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            throw new RuntimeException('Obrázek je poškozený nebo má neplatný formát.');
        }

        if ((int)$size[0] * (int)$size[1] > $maxPixels) {
            throw new RuntimeException('Obrázek má příliš vysoké rozlišení.');
        }
    }
}

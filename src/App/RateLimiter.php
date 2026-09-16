<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Jednoduchý server-side rate limiter s atomickým file lockem.
 * Klíče jsou ukládány pouze jako SHA-256 hash, nikoli v čitelné podobě.
 */
final class RateLimiter
{
    /** @var string */
    private $directory;

    public function __construct(string $storageRoot)
    {
        $this->directory = rtrim($storageRoot, '/\\') . '/rate-limits';
    }

    public function consume(string $key, int $limit, int $windowSeconds): bool
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);

        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Nelze inicializovat bezpečnostní rate limiter.');
        }

        $this->cleanupExpiredFiles(max(86400, $windowSeconds * 2));

        $path = $this->directory . '/' . hash('sha256', $key) . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Nelze inicializovat bezpečnostní rate limiter.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Nelze uzamknout bezpečnostní rate limiter.');
            }

            $raw = stream_get_contents($handle);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $now = time();
            $startedAt = is_array($data) ? (int)($data['started_at'] ?? 0) : 0;
            $attempts = is_array($data) ? (int)($data['attempts'] ?? 0) : 0;

            if ($startedAt <= 0 || ($now - $startedAt) >= $windowSeconds) {
                $startedAt = $now;
                $attempts = 0;
            }

            ++$attempts;
            $allowed = $attempts <= $limit;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode([
                'started_at' => $startedAt,
                'attempts' => $attempts,
            ], JSON_UNESCAPED_SLASHES));
            fflush($handle);
            @chmod($path, 0640);
            flock($handle, LOCK_UN);

            return $allowed;
        } finally {
            fclose($handle);
        }
    }

    public function clear(string $key): void
    {
        $path = $this->directory . '/' . hash('sha256', $key) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function cleanupExpiredFiles(int $maxAgeSeconds): void
    {
        // Přibližně 1 % požadavků uklidí staré soubory bez potřeby cronu.
        try {
            if (random_int(1, 100) !== 1) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $threshold = time() - $maxAgeSeconds;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $modifiedAt = @filemtime($file);
            if (is_int($modifiedAt) && $modifiedAt < $threshold) {
                @unlink($file);
            }
        }
    }

    public static function clientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }
}

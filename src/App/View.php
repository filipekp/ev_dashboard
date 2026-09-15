<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /** @param int|float|string|null $number */
    public static function cz($number, int $decimals = 1): string
    {
        return number_format((float)$number, $decimals, ',', ' ');
    }

    public static function shortAddress(string $value): string
    {
        return preg_replace('/,\s*Czechia$/u', '', $value) ?? $value;
    }

    public static function routeKey(string $from, string $to): string
    {
        return self::shortAddress($from) . ' → ' . self::shortAddress($to);
    }

    public static function displayRoute(?string $from, ?string $to): string
    {
        $from = trim((string)$from);
        $to = trim((string)$to);
        if ($from === '' && $to === '') {
            return 'Bez údajů o trase';
        }
        return self::routeKey($from !== '' ? $from : '—', $to !== '' ? $to : '—');
    }

    /** @return array{0:?float,1:?float} */
    public static function parseCoord(?string $value): array
    {
        if (!$value || strpos($value, ',') === false) {
            return [null, null];
        }
        [$a, $b] = array_map('trim', explode(',', $value, 2));
        return [is_numeric($a) ? (float)$a : null, is_numeric($b) ? (float)$b : null];
    }
}

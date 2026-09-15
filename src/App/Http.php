<?php

declare(strict_types=1);

namespace App;
/**
 * Contains shared HTTP response helpers.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class Http
{
    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

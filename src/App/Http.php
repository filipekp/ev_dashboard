<?php

declare(strict_types=1);

namespace App;

final class Http
{
    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

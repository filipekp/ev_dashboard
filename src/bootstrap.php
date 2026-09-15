<?php

declare(strict_types=1);

use App\Application;
use App\Http;
use App\View;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/App/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$config = require __DIR__ . '/config.php';
$app = new Application($config, dirname(__DIR__));
$pdo = $app->pdo();

/*
 * Tenká kompatibilní vrstva pro existující šablony. Veškerá aplikační logika
 * je nyní ve třídách v src/App; tyto funkce pouze delegují, aby refaktor
 * nemusel měnit desítky výpisů v HTML najednou.
 */
function h(?string $value): string
{
    return View::h($value);
}

function cz($value, int $dec = 1): string
{
    return View::cz($value, $dec);
}

function shortAddress(string $value): string
{
    return View::shortAddress($value);
}

function routeKey(string $from, string $to): string
{
    return View::routeKey($from, $to);
}

function displayRoute(?string $from, ?string $to): string
{
    return View::displayRoute($from, $to);
}

/** @return array{0: float|null, 1: float|null} */
function parseCoord(?string $value): array
{
    return View::parseCoord($value);
}

function redirect(string $url): void
{
    Http::redirect($url);
}

function flash(string $message, string $type = 'ok'): void
{
    global $app;

    $app->session()->flash($message, $type);
}

/** @return array<string, mixed>|null */
function getFlash(): ?array
{
    global $app;

    return $app->session()->pullFlash();
}

function csrfToken(): string
{
    global $app;

    return $app->session()->csrfToken();
}

function verifyCsrf(): void
{
    global $app;

    $app->session()->verifyCsrf();
}

function usersExist(PDO $pdo = null): bool
{
    global $app;

    return $app->auth()->usersExist();
}

/** @return array<string, mixed>|null */
function currentUser(PDO $pdo = null): ?array
{
    global $app;

    return $app->auth()->currentUser();
}

/** @return array<string, mixed> */
function requireLogin(PDO $pdo = null): array
{
    global $app;

    return $app->auth()->requireLogin();
}

/** @return array<string, mixed> */
function requireAdmin(PDO $pdo = null): array
{
    global $app;

    return $app->auth()->requireAdmin();
}

/** @return array<string, mixed> */
function requireVehicleManager(PDO $pdo = null): array
{
    global $app;

    return $app->auth()->requireVehicleManager();
}

/** @param array<string, mixed> $user */
function isAdmin(array $user): bool
{
    global $app;

    return $app->auth()->isAdmin($user);
}

/** @param array<string, mixed> $user */
function canManageVehicles(array $user): bool
{
    global $app;

    return $app->auth()->canManageVehicles($user);
}

/**
 * @param array<string, mixed> $user
 * @return array<int, array<string, mixed>>
 */
function allowedVehicles(PDO $pdo, array $user): array
{
    global $app;

    return $app->auth()->allowedVehicles($user);
}

/** @param array<string, mixed> $user */
function canAccessVehicle(PDO $pdo, array $user, int $vehicleId): bool
{
    global $app;

    return $app->auth()->canAccessVehicle($user, $vehicleId);
}

/**
 * @param array<string, mixed> $user
 * @return array<string, mixed>|null
 */
function selectVehicle(PDO $pdo, array $user): ?array
{
    global $app;

    return $app->auth()->selectVehicle($user);
}

function createPasswordResetToken(PDO $pdo, int $userId, int $minutes = 60): string
{
    global $app;

    return $app->auth()->createPasswordResetToken($userId, $minutes);
}

function resetUrl(string $token): string
{
    global $app;

    return $app->auth()->resetUrl($token);
}

function sendPasswordResetEmail(string $to, string $name, string $url): bool
{
    global $app;

    return $app->auth()->sendPasswordResetEmail($to, $name, $url);
}

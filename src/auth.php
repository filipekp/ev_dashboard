<?php

declare(strict_types=1);

function usersExist(PDO $pdo): bool
{
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (PDOException $e) {
        return FALSE;
    }
}

function currentUser(PDO $pdo): ?array
{
    static $cache = NULL, $loaded = FALSE;
    if ($loaded) {
        return $cache;
    }
    $loaded = TRUE;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id) {
        return NULL;
    }
    $q = $pdo->prepare('SELECT id,name,email,role,active,default_vehicle_id FROM users WHERE id=?');
    $q->execute([$id]);
    $u = $q->fetch();
    if (!$u || !(int)$u['active']) {
        unset($_SESSION['user_id']);
        return NULL;
    }
    return $cache = $u;
}

function requireLogin(PDO $pdo): array
{
    $u = currentUser($pdo);
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function requireAdmin(PDO $pdo): array
{
    $u = requireLogin($pdo);
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Přístup odepřen.');
    }
    return $u;
}

function requireVehicleManager(PDO $pdo): array
{
    $u = requireLogin($pdo);
    if (!in_array($u['role'], ['admin', 'manager'], TRUE)) {
        http_response_code(403);
        exit('Přístup odepřen.');
    }
    return $u;
}

function isAdmin(array $u): bool
{
    return ($u['role'] ?? '') === 'admin';
}

function canManageVehicles(array $u): bool
{
    return in_array(($u['role'] ?? ''), ['admin', 'manager'], TRUE);
}

function allowedVehicles(PDO $pdo, array $u): array
{
    if (canManageVehicles($u)) {
        return $pdo->query('SELECT * FROM vehicles ORDER BY name, id')->fetchAll();
    }
    $q = $pdo->prepare('SELECT v.* FROM vehicles v JOIN user_vehicles uv ON uv.vehicle_id=v.id WHERE uv.user_id=? ORDER BY v.name,v.id');
    $q->execute([(int)$u['id']]);
    return $q->fetchAll();
}

function canAccessVehicle(PDO $pdo, array $u, int $vehicleId): bool
{
    if (canManageVehicles($u)) {
        $q = $pdo->prepare('SELECT 1 FROM vehicles WHERE id=?');
        $q->execute([$vehicleId]);
        return (bool)$q->fetchColumn();
    }
    $q = $pdo->prepare('SELECT 1 FROM user_vehicles WHERE user_id=? AND vehicle_id=?');
    $q->execute([(int)$u['id'], $vehicleId]);
    return (bool)$q->fetchColumn();
}

function selectVehicle(PDO $pdo, array $u): ?array
{
    $vehicles = allowedVehicles($pdo, $u);
    if (!$vehicles) {
        return NULL;
    }
    // Explicitní volba v URL má nejvyšší prioritu, potom vozidlo uložené v relaci.
    // Po novém přihlášení je relace vozidla prázdná, proto se použije výchozí vozidlo uživatele.
    $requested = (int)($_GET['vehicle_id'] ?? $_SESSION['vehicle_id'] ?? 0);
    if ($requested <= 0) {
        $requested = (int)($u['default_vehicle_id'] ?? 0);
    }
    foreach ($vehicles as $v) {
        if ((int)$v['id'] === $requested) {
            $_SESSION['vehicle_id'] = $requested;
            return $v;
        }
    }
    $_SESSION['vehicle_id'] = (int)$vehicles[0]['id'];
    return $vehicles[0];
}

function createPasswordResetToken(PDO $pdo, int $userId, int $minutes = 60): string
{
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable())->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');
    $q = $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)');
    $q->execute([$userId, $hash, $expires]);
    return $token;
}

function resetUrl(string $token): string
{
    global $config;
    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')?'https':'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $base = $scheme . '://' . $host . $path;
    }
    return $base . '/reset-password.php?token=' . rawurlencode($token);
}

function sendPasswordResetEmail(string $to, string $name, string $url): bool
{
    global $config;
    $from = (string)($config['mail']['from'] ?? '');
    if ($from === '') {
        return FALSE;
    }
    $subject = 'Obnova hesla – ' . ($config['app']['name'] ?? 'EV Stats');
    $body = "Dobrý den {$name},\n\npro nastavení nového hesla otevřete tento odkaz:\n{$url}\n\nOdkaz je platný 60 minut. Pokud jste o změnu nežádali, zprávu ignorujte.\n";
    $headers = ['From: ' . $from, 'Content-Type: text/plain; charset=UTF-8'];
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Třída AuthService.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class AuthService
{
    /** @var PDO */ private $pdo;
    /** @var Config */ private $config;
    /** @var array<string,mixed>|null */ private $currentUser;
    /** @var bool */ private $currentUserLoaded = false;

    public function __construct(PDO $pdo, Config $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function usersExist(): bool
    {
        try {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function currentUser(): ?array
    {
        if ($this->currentUserLoaded) {
            return $this->currentUser;
        }
        $this->currentUserLoaded = true;
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $q = $this->pdo->prepare('SELECT id,name,email,role,active,default_vehicle_id FROM users WHERE id=?');
        $q->execute([$id]);
        $user = $q->fetch();
        if (!$user || !(int)$user['active']) {
            unset($_SESSION['user_id']);
            return null;
        }
        $this->currentUser = $user;
        return $user;
    }

    /** @return array<string,mixed> */
    public function requireLogin(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            Http::redirect('login.php');
        }
        return $user;
    }

    /** @return array<string,mixed> */
    public function requireAdmin(): array
    {
        $user = $this->requireLogin();
        if (!$this->isAdmin($user)) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }
        return $user;
    }

    /** @return array<string,mixed> */
    public function requireVehicleManager(): array
    {
        $user = $this->requireLogin();
        if (!$this->canManageVehicles($user)) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }
        return $user;
    }

    /** @param array<string,mixed> $user */
    public function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /** @param array<string,mixed> $user */
    public function canManageVehicles(array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
    }

    /** @param array<string,mixed> $user @return array<int,array<string,mixed>> */
    public function allowedVehicles(array $user): array
    {
        if ($this->canManageVehicles($user)) {
            return $this->pdo->query('SELECT * FROM vehicles ORDER BY name,id')->fetchAll();
        }
        $q = $this->pdo->prepare('SELECT v.* FROM vehicles v JOIN user_vehicles uv ON uv.vehicle_id=v.id WHERE uv.user_id=? ORDER BY v.name,v.id');
        $q->execute([(int)$user['id']]);
        return $q->fetchAll();
    }

    /** @param array<string,mixed> $user */
    public function canAccessVehicle(array $user, int $vehicleId): bool
    {
        if ($this->canManageVehicles($user)) {
            $q = $this->pdo->prepare('SELECT 1 FROM vehicles WHERE id=?');
            $q->execute([$vehicleId]);
            return (bool)$q->fetchColumn();
        }
        $q = $this->pdo->prepare('SELECT 1 FROM user_vehicles WHERE user_id=? AND vehicle_id=?');
        $q->execute([(int)$user['id'], $vehicleId]);
        return (bool)$q->fetchColumn();
    }

    /** @param array<string,mixed> $user @return array<string,mixed>|null */
    public function selectVehicle(array $user): ?array
    {
        $vehicles = $this->allowedVehicles($user);
        if (!$vehicles) {
            return null;
        }
        $requested = (int)($_GET['vehicle_id'] ?? $_SESSION['vehicle_id'] ?? 0);
        if ($requested <= 0) {
            $requested = (int)($user['default_vehicle_id'] ?? 0);
        }
        foreach ($vehicles as $vehicle) {
            if ((int)$vehicle['id'] === $requested) {
                $_SESSION['vehicle_id'] = $requested;
                return $vehicle;
            }
        }
        $_SESSION['vehicle_id'] = (int)$vehicles[0]['id'];
        return $vehicles[0];
    }

    public function createPasswordResetToken(int $userId, int $minutes = 60): string
    {
        $this->pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = (new DateTimeImmutable())->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');
        $q = $this->pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)');
        $q->execute([$userId, $hash, $expires]);
        return $token;
    }

    public function resetUrl(string $token): string
    {
        $base = rtrim((string)$this->config->get('app.base_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $base = $scheme . '://' . $host . $path;
        }
        return $base . '/reset-password.php?token=' . rawurlencode($token);
    }

    public function sendPasswordResetEmail(string $to, string $name, string $url): bool
    {
        $from = (string)$this->config->get('mail.from', '');
        if ($from === '') {
            return false;
        }
        $subject = 'Obnova hesla – ' . (string)$this->config->get('app.name', 'EV Stats');
        $body = "Dobrý den {$name},\n\npro nastavení nového hesla otevřete tento odkaz:\n{$url}\n\nOdkaz je platný 60 minut. Pokud jste o změnu nežádali, zprávu ignorujte.\n";
        $headers = ['From: ' . $from, 'Content-Type: text/plain; charset=UTF-8'];
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}

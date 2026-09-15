<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Handles profile and password changes for the current user.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class ProfileController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $pdo = $this->app->pdo();
        $me = $this->app->auth()->requireLogin();
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->app->session()->verifyCsrf();
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'profile') {
                    $name = trim((string)$_POST['name']);
                    $email = strtolower(trim((string)$_POST['email']));
                    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Zkontrolujte jméno a e-mail.');
                    }
                    $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, (int)$me['id']]);
                    $this->app->session()->flash('Profil byl uložen.');
                } elseif ($action === 'default_vehicle') {
                    $vehicleId = (int)($_POST['default_vehicle_id'] ?? 0);
                    if ($vehicleId > 0 && !$this->app->auth()->canAccessVehicle($me, $vehicleId)) {
                        throw new RuntimeException('Toto vozidlo nemáte k dispozici.');
                    }
                    $pdo->prepare('UPDATE users SET default_vehicle_id=? WHERE id=?')->execute([$vehicleId > 0?$vehicleId:null, (int)$me['id']]);
                    unset($_SESSION['vehicle_id']);
                    $this->app->session()->flash($vehicleId > 0?'Výchozí vozidlo bylo nastaveno.':'Výchozí vozidlo bylo zrušeno.');
                } elseif ($action === 'password') {
                    $old = (string)$_POST['old_password'];
                    $new = (string)$_POST['new_password'];
                    $again = (string)$_POST['new_password_again'];
                    $q = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
                    $q->execute([(int)$me['id']]);
                    if (!password_verify($old, (string)$q->fetchColumn())) {
                        throw new RuntimeException('Současné heslo není správné.');
                    }
                    if (strlen($new) < 8) {
                        throw new RuntimeException('Nové heslo musí mít alespoň 8 znaků.');
                    }
                    if ($new !== $again) {
                        throw new RuntimeException('Nová hesla se neshodují.');
                    }
                    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$me['id']]);
                    session_regenerate_id(true);
                    $this->app->session()->flash('Heslo bylo změněno.');
                }
                Http::redirect('profile.php');
            }
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('profile.php');
        }
        $q = $pdo->prepare('SELECT id,name,email,role,default_vehicle_id FROM users WHERE id=?');
        $q->execute([(int)$me['id']]);
        $me = $q->fetch();
        $vehicles = $this->app->auth()->allowedVehicles($me);
        $flash = $this->app->session()->pullFlash();
        $this->app->template()->render('profile', ['app' => $this->app, 'me' => $me, 'vehicles' => $vehicles, 'flash' => $flash]);
    }
}

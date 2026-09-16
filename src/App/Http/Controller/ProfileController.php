<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Správa vlastního profilu, výchozího vozidla a hesla.
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
                    $this->updateProfile($me);
                } elseif ($action === 'default_vehicle') {
                    $this->updateDefaultVehicle($me);
                } elseif ($action === 'password') {
                    $this->updatePassword($me);
                } else {
                    throw new RuntimeException('Neznámá operace.');
                }

                Http::redirect('profile.php');
            }
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('profile.php');
        }

        $query = $pdo->prepare('SELECT id,name,email,role,default_vehicle_id FROM users WHERE id=?');
        $query->execute([(int)$me['id']]);
        $me = $query->fetch();
        if (!$me) {
            http_response_code(404);
            exit('Uživatel nebyl nalezen.');
        }

        $this->app->template()->render('profile', [
            'app' => $this->app,
            'me' => $me,
            'vehicles' => $this->app->auth()->allowedVehicles($me),
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }

    /** @param array<string,mixed> $me */
    private function updateProfile(array $me): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Zkontrolujte jméno a e-mail.');
        }

        $this->app->pdo()->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([
            $name,
            $email,
            (int)$me['id'],
        ]);
        $this->app->session()->flash('Profil byl uložen.');
    }

    /** @param array<string,mixed> $me */
    private function updateDefaultVehicle(array $me): void
    {
        $vehicleId = (int)($_POST['default_vehicle_id'] ?? 0);
        if ($vehicleId > 0 && !$this->app->auth()->canAccessVehicle($me, $vehicleId)) {
            throw new RuntimeException('Toto vozidlo nemáte k dispozici.');
        }

        $this->app->pdo()->prepare('UPDATE users SET default_vehicle_id=? WHERE id=?')->execute([
            $vehicleId > 0 ? $vehicleId : null,
            (int)$me['id'],
        ]);
        unset($_SESSION['vehicle_id']);
        $this->app->session()->flash(
            $vehicleId > 0 ? 'Výchozí vozidlo bylo nastaveno.' : 'Výchozí vozidlo bylo zrušeno.'
        );
    }

    /** @param array<string,mixed> $me */
    private function updatePassword(array $me): void
    {
        $oldPassword = (string)($_POST['old_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordAgain = (string)($_POST['new_password_again'] ?? '');

        $query = $this->app->pdo()->prepare('SELECT password_hash FROM users WHERE id=?');
        $query->execute([(int)$me['id']]);
        if (!password_verify($oldPassword, (string)$query->fetchColumn())) {
            throw new RuntimeException('Současné heslo není správné.');
        }

        $minimumLength = max(8, (int)$this->app->config()->get('security.minimum_password_length', 12));
        if (strlen($newPassword) < $minimumLength) {
            throw new RuntimeException('Nové heslo musí mít alespoň ' . $minimumLength . ' znaků.');
        }
        if ($newPassword !== $newPasswordAgain) {
            throw new RuntimeException('Nová hesla se neshodují.');
        }

        $this->app->pdo()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            (int)$me['id'],
        ]);
        $this->app->pdo()->prepare(
            'UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL'
        )->execute([(int)$me['id']]);

        session_regenerate_id(true);
        $this->app->session()->rotateCsrf();
        $_SESSION['_last_regenerated'] = time();
        $this->app->session()->flash('Heslo bylo změněno.');
    }
}

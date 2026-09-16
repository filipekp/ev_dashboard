<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Správa uživatelů a jejich přiřazení k vozidlům.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 */
final class UsersController
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
        $me = $this->app->auth()->requireAdmin();

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->app->session()->verifyCsrf();
                $this->handlePost($me);
                Http::redirect('users.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('users.php');
        }

        $users = $pdo->query(
            'SELECT u.*, (SELECT COUNT(*) FROM user_vehicles uv WHERE uv.user_id=u.id) vehicle_count
             FROM users u
             ORDER BY u.name'
        )->fetchAll();
        $vehicles = $pdo->query('SELECT id, name, vin FROM vehicles ORDER BY name')->fetchAll();

        $assigned = [];
        foreach ($pdo->query('SELECT user_id, vehicle_id FROM user_vehicles') as $row) {
            $assigned[(int)$row['user_id']][] = (int)$row['vehicle_id'];
        }

        $this->app->template()->render('users', [
            'app' => $this->app,
            'me' => $me,
            'users' => $users,
            'vehicles' => $vehicles,
            'assigned' => $assigned,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }

    /** @param array<string,mixed> $me */
    private function handlePost(array $me): void
    {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $this->createUser();
            return;
        }
        if ($action === 'update') {
            $this->updateUser($me);
            return;
        }
        if ($action === 'reset_link') {
            $this->sendResetLink();
            return;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$me['id']) {
                throw new RuntimeException('Nemůžete smazat vlastní účet.');
            }
            $this->app->pdo()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            $this->app->session()->flash('Uživatel byl smazán.');
            return;
        }

        throw new RuntimeException('Neznámá operace.');
    }

    private function createUser(): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $role = $this->role((string)($_POST['role'] ?? 'user'));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException('Zkontrolujte údaje. Heslo musí mít alespoň 8 znaků.');
        }

        $pdo = $this->app->pdo();
        $pdo->beginTransaction();
        $query = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');
        $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $userId = (int)$pdo->lastInsertId();
        $this->saveVehicleAssignments($userId, $_POST['vehicle_ids'] ?? []);
        $pdo->commit();

        $this->app->session()->flash('Uživatel byl vytvořen.');
    }

    /** @param array<string,mixed> $me */
    private function updateUser(array $me): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $role = $this->role((string)($_POST['role'] ?? 'user'));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($id <= 0) {
            throw new RuntimeException('Uživatel nebyl nalezen.');
        }
        if ($id === (int)$me['id'] && !$active) {
            throw new RuntimeException('Nemůžete deaktivovat vlastní účet.');
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Neplatné údaje uživatele.');
        }

        $vehicleIds = $this->normaliseIds($_POST['vehicle_ids'] ?? []);
        $pdo = $this->app->pdo();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=? WHERE id=?')
            ->execute([$name, $email, $role, $active, $id]);
        $this->saveVehicleAssignments($id, $vehicleIds);

        if ($role === 'user') {
            if ($vehicleIds) {
                $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
                $pdo->prepare(
                    'UPDATE users SET default_vehicle_id=NULL
                     WHERE id=? AND default_vehicle_id IS NOT NULL
                       AND default_vehicle_id NOT IN (' . $placeholders . ')'
                )->execute(array_merge([$id], $vehicleIds));
            } else {
                $pdo->prepare('UPDATE users SET default_vehicle_id=NULL WHERE id=?')->execute([$id]);
            }
        }
        $pdo->commit();

        $this->app->session()->flash('Uživatel a jeho vozidla byli upraveni.');
    }

    private function sendResetLink(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $query = $this->app->pdo()->prepare('SELECT id,name,email,active FROM users WHERE id=?');
        $query->execute([$id]);
        $user = $query->fetch();

        if (!$user || !(int)$user['active']) {
            throw new RuntimeException('Uživatel neexistuje nebo není aktivní.');
        }

        $token = $this->app->auth()->createPasswordResetToken($id);
        $url = $this->app->auth()->resetUrl($token);
        $sent = $this->app->auth()->sendPasswordResetEmail($user['email'], $user['name'], $url);
        $message = $sent
            ? 'Odkaz pro obnovu hesla byl odeslán.'
            : 'Resetovací odkaz byl vytvořen, ale e-mail se nepodařilo odeslat.';

        if ((bool)$this->app->config()->get('app.debug', false)) {
            $message .= ' ' . $url;
        }
        $this->app->session()->flash($message, $sent ? 'ok' : 'error');
    }

    /** @param mixed $ids */
    private function saveVehicleAssignments(int $userId, $ids): void
    {
        $vehicleIds = $this->normaliseIds($ids);
        $pdo = $this->app->pdo();
        $pdo->prepare('DELETE FROM user_vehicles WHERE user_id=?')->execute([$userId]);
        $insert = $pdo->prepare('INSERT INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)');
        foreach ($vehicleIds as $vehicleId) {
            $insert->execute([$userId, $vehicleId]);
        }
    }

    private function role(string $role): string
    {
        return in_array($role, ['admin', 'manager', 'user'], true) ? $role : 'user';
    }

    /** @param mixed $ids @return int[] */
    private function normaliseIds($ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}

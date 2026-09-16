<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/** Správa hierarchie uživatelů a jejich časově omezených přístupů k vozidlům. */
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
        $me = $this->app->auth()->requireVehicleManager();

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

        $users = $this->app->userAccess()->manageableUsers($me);
        $vehicles = $this->app->userAccess()->assignableVehicles($me);
        $managers = $this->app->auth()->isAdmin($me)
            ? $pdo->query("SELECT id,name,email FROM users WHERE role='manager' AND active=1 ORDER BY name")->fetchAll()
            : [];

        $assigned = [];
        if ($users) {
            $ids = array_map(static function (array $user): int {
                return (int)$user['id'];
            }, $users);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = $pdo->prepare('SELECT user_id,vehicle_id FROM user_vehicles WHERE user_id IN (' . $placeholders . ')');
            $query->execute($ids);
            foreach ($query->fetchAll() as $row) {
                $assigned[(int)$row['user_id']][] = (int)$row['vehicle_id'];
            }
        }

        $this->app->template()->render('users', [
            'app' => $this->app,
            'me' => $me,
            'users' => $users,
            'vehicles' => $vehicles,
            'managers' => $managers,
            'assigned' => $assigned,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }

    /** @param array<string,mixed> $me */
    private function handlePost(array $me): void
    {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $this->createUser($me);
            return;
        }
        if ($action === 'update') {
            $this->updateUser($me);
            return;
        }
        if ($action === 'reset_link') {
            $this->sendResetLink($me);
            return;
        }
        if ($action === 'delete') {
            $this->deleteUser($me);
            return;
        }
        throw new RuntimeException('Neznámá operace.');
    }

    /** @param array<string,mixed> $me */
    private function createUser(array $me): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $role = $this->app->auth()->isAdmin($me) ? $this->role((string)($_POST['role'] ?? 'user')) : 'user';
        $parentId = $this->nullableInt($_POST['parent_user_id'] ?? null);
        $parentId = $this->app->userAccess()->validateParent($me, $role, $parentId);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException('Zkontrolujte údaje. Heslo musí mít alespoň 8 znaků.');
        }

        $pdo = $this->app->pdo();
        $pdo->beginTransaction();
        $query = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,parent_user_id,active,email_verified_at) VALUES(?,?,?,?,?,1,NOW())');
        $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $parentId]);
        $userId = (int)$pdo->lastInsertId();
        $this->app->userAccess()->syncAssignments(
            $me,
            $userId,
            $this->normaliseIds($_POST['vehicle_ids'] ?? []),
            (string)($_POST['assignment_effective_date'] ?? '')
        );
        $pdo->commit();
        $this->app->session()->flash('Uživatel byl vytvořen a zařazen do hierarchie.');
    }

    /** @param array<string,mixed> $me */
    private function updateUser(array $me): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || !$this->app->userAccess()->canManageUser($me, $id)) {
            throw new RuntimeException('Tento uživatelský účet nemůžete spravovat.');
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Neplatné údaje uživatele.');
        }

        $current = $this->app->pdo()->prepare('SELECT role,parent_user_id FROM users WHERE id=?');
        $current->execute([$id]);
        $existing = $current->fetch();
        if (!$existing) {
            throw new RuntimeException('Uživatel nebyl nalezen.');
        }

        $role = $this->app->auth()->isAdmin($me)
            ? $this->role((string)($_POST['role'] ?? $existing['role']))
            : 'user';
        $parentId = $this->app->auth()->isAdmin($me)
            ? $this->nullableInt($_POST['parent_user_id'] ?? null)
            : (int)$me['id'];
        $parentId = $this->app->userAccess()->validateParent($me, $role, $parentId);
        $active = isset($_POST['active']) ? 1 : 0;

        $vehicleIds = $this->normaliseIds($_POST['vehicle_ids'] ?? []);
        $pdo = $this->app->pdo();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET name=?,email=?,role=?,parent_user_id=?,active=? WHERE id=?')
            ->execute([$name, $email, $role, $parentId, $active, $id]);
        $this->app->userAccess()->syncAssignments(
            $me,
            $id,
            $vehicleIds,
            (string)($_POST['assignment_effective_date'] ?? '')
        );
        $this->clearInvalidDefaultVehicle($id, $vehicleIds);
        $pdo->commit();
        $this->app->session()->flash('Uživatel, hierarchie a přístupy byly upraveny.');
    }

    /** @param array<string,mixed> $me */
    private function sendResetLink(array $me): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if (!$this->app->userAccess()->canManageUser($me, $id)) {
            throw new RuntimeException('Tento uživatelský účet nemůžete spravovat.');
        }
        $query = $this->app->pdo()->prepare('SELECT id,name,email,active FROM users WHERE id=?');
        $query->execute([$id]);
        $user = $query->fetch();
        if (!$user || !(int)$user['active']) {
            throw new RuntimeException('Uživatel neexistuje nebo není aktivní.');
        }
        $token = $this->app->auth()->createPasswordResetToken($id);
        $url = $this->app->auth()->resetUrl($token);
        $sent = $this->app->auth()->sendPasswordResetEmail($user['email'], $user['name'], $url);
        $message = $sent ? 'Odkaz pro obnovu hesla byl odeslán.' : 'Resetovací odkaz byl vytvořen, ale e-mail se nepodařilo odeslat.';
        if ((bool)$this->app->config()->get('app.debug', false)) {
            $message .= ' ' . $url;
        }
        $this->app->session()->flash($message, $sent ? 'ok' : 'error');
    }

    /** @param array<string,mixed> $me */
    private function deleteUser(array $me): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id'] || !$this->app->userAccess()->canManageUser($me, $id)) {
            throw new RuntimeException('Tento účet nelze smazat.');
        }
        $q = $this->app->pdo()->prepare('SELECT COUNT(*) FROM users WHERE parent_user_id=?');
        $q->execute([$id]);
        if ((int)$q->fetchColumn() > 0) {
            throw new RuntimeException('Uživatel má podřízené účty. Nejprve je přesuňte pod jiného správce.');
        }
        $this->app->pdo()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        $this->app->session()->flash('Uživatel byl smazán.');
    }

    /** @param int[] $vehicleIds */
    private function clearInvalidDefaultVehicle(int $userId, array $vehicleIds): void
    {
        if (!$vehicleIds) {
            $this->app->pdo()->prepare('UPDATE users SET default_vehicle_id=NULL WHERE id=?')->execute([$userId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $this->app->pdo()->prepare(
            'UPDATE users SET default_vehicle_id=NULL WHERE id=? AND default_vehicle_id IS NOT NULL '
            . 'AND default_vehicle_id NOT IN (' . $placeholders . ')'
        )->execute(array_merge([$userId], $vehicleIds));
    }

    private function role(string $role): string
    {
        return in_array($role, ['admin', 'manager', 'user'], true) ? $role : 'user';
    }

    /** @param mixed $value */
    private function nullableInt($value): ?int
    {
        $id = (int)$value;
        return $id > 0 ? $id : null;
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

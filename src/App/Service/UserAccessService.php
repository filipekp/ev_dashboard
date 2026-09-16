<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Řídí hierarchii uživatelů a časově omezená přiřazení vozidel.
 *
 * user_vehicles zůstává rychlou tabulkou aktuálních oprávnění. Historii a
 * hranice soukromí drží user_vehicle_access.
 */
final class UserAccessService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @param array<string,mixed> $actor */
    public function canManageUser(array $actor, int $targetUserId): bool
    {
        if ((string)$actor['role'] === 'admin') {
            return true;
        }
        if ((string)$actor['role'] !== 'manager') {
            return false;
        }
        $q = $this->pdo->prepare("SELECT 1 FROM users WHERE id=? AND parent_user_id=? AND role='user'");
        $q->execute([$targetUserId, (int)$actor['id']]);
        return (bool)$q->fetchColumn();
    }

    /** @param array<string,mixed> $actor @return array<int,array<string,mixed>> */
    public function manageableUsers(array $actor): array
    {
        if ((string)$actor['role'] === 'admin') {
            return $this->pdo->query(
                'SELECT u.*, p.name parent_name, (SELECT COUNT(*) FROM user_vehicles uv WHERE uv.user_id=u.id) vehicle_count '
                . 'FROM users u LEFT JOIN users p ON p.id=u.parent_user_id ORDER BY FIELD(u.role,\'admin\',\'manager\',\'user\'), p.name, u.name'
            )->fetchAll();
        }
        $q = $this->pdo->prepare(
            "SELECT u.*, p.name parent_name, (SELECT COUNT(*) FROM user_vehicles uv WHERE uv.user_id=u.id) vehicle_count "
            . "FROM users u LEFT JOIN users p ON p.id=u.parent_user_id WHERE u.parent_user_id=? AND u.role='user' ORDER BY u.name"
        );
        $q->execute([(int)$actor['id']]);
        return $q->fetchAll();
    }

    /** @param array<string,mixed> $actor @return array<int,array<string,mixed>> */
    public function assignableVehicles(array $actor): array
    {
        if ((string)$actor['role'] === 'admin') {
            return $this->pdo->query('SELECT * FROM vehicles ORDER BY name,id')->fetchAll();
        }
        $q = $this->pdo->prepare(
            'SELECT v.* FROM vehicles v JOIN user_vehicles uv ON uv.vehicle_id=v.id WHERE uv.user_id=? ORDER BY v.name,v.id'
        );
        $q->execute([(int)$actor['id']]);
        return $q->fetchAll();
    }

    /** @param array<string,mixed> $actor */
    public function validateParent(array $actor, string $role, ?int $parentUserId): ?int
    {
        if ($role === 'admin') {
            if ((string)$actor['role'] !== 'admin') {
                throw new RuntimeException('Administrátora může vytvářet pouze administrátor.');
            }
            return null;
        }
        if ($role === 'manager') {
            if ((string)$actor['role'] !== 'admin') {
                throw new RuntimeException('Správce vozidel může vytvářet pouze administrátor.');
            }
            return (int)$actor['id'];
        }
        if ((string)$actor['role'] === 'manager') {
            return (int)$actor['id'];
        }
        if ($parentUserId === null || $parentUserId <= 0) {
            throw new RuntimeException('Řidič musí mít přiřazeného správce vozidel.');
        }
        $q = $this->pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='manager' AND active=1");
        $q->execute([$parentUserId]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('Vybraný nadřízený není aktivní správce vozidel.');
        }
        return $parentUserId;
    }

    /**
     * Uloží aktuální přiřazení a uzavře/otevře historické intervaly.
     *
     * @param array<string,mixed> $actor
     * @param int[] $requestedVehicleIds
     */
    public function syncAssignments(array $actor, int $userId, array $requestedVehicleIds, string $effectiveDate): void
    {
        if (!$this->canManageUser($actor, $userId) && (int)$actor['id'] !== $userId && (string)$actor['role'] !== 'admin') {
            throw new RuntimeException('Tento uživatelský účet nemůžete spravovat.');
        }

        $effectiveAt = $this->normaliseDate($effectiveDate);
        $targetQuery = $this->pdo->prepare('SELECT role,parent_user_id FROM users WHERE id=?');
        $targetQuery->execute([$userId]);
        $target = $targetQuery->fetch();
        if (!$target) {
            throw new RuntimeException('Uživatel nebyl nalezen.');
        }

        $allowed = [];
        if ((string)$target['role'] === 'user' && (int)($target['parent_user_id'] ?? 0) > 0) {
            $parentVehicles = $this->pdo->prepare('SELECT vehicle_id FROM user_vehicles WHERE user_id=?');
            $parentVehicles->execute([(int)$target['parent_user_id']]);
            foreach ($parentVehicles->fetchAll(PDO::FETCH_COLUMN) as $vehicleId) {
                $allowed[(int)$vehicleId] = true;
            }
        } else {
            foreach ($this->assignableVehicles($actor) as $vehicle) {
                $allowed[(int)$vehicle['id']] = true;
            }
        }
        $requested = array_values(array_unique(array_filter(array_map('intval', $requestedVehicleIds))));
        foreach ($requested as $vehicleId) {
            if (!isset($allowed[$vehicleId])) {
                throw new RuntimeException('Nelze přiřadit vozidlo mimo váš vozový park.');
            }
        }

        $q = $this->pdo->prepare('SELECT vehicle_id FROM user_vehicles WHERE user_id=?');
        $q->execute([$userId]);
        $current = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $toRemove = array_diff($current, $requested);
        $toAdd = array_diff($requested, $current);

        $delete = $this->pdo->prepare('DELETE FROM user_vehicles WHERE user_id=? AND vehicle_id=?');
        $close = $this->pdo->prepare(
            'UPDATE user_vehicle_access SET valid_to=? WHERE user_id=? AND vehicle_id=? AND valid_to IS NULL'
        );
        foreach ($toRemove as $vehicleId) {
            $delete->execute([$userId, $vehicleId]);
            $close->execute([$effectiveAt, $userId, $vehicleId]);

            // Když administrátor odebere vozidlo správci, stejné oprávnění
            // nemůže zůstat žádnému řidiči pod tímto správcem.
            if ((string)$target['role'] === 'manager') {
                $children = $this->pdo->prepare("SELECT id FROM users WHERE parent_user_id=? AND role='user'");
                $children->execute([$userId]);
                foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                    $delete->execute([(int)$childId, $vehicleId]);
                    $close->execute([$effectiveAt, (int)$childId, $vehicleId]);
                    $this->pdo->prepare('UPDATE users SET default_vehicle_id=NULL WHERE id=? AND default_vehicle_id=?')
                        ->execute([(int)$childId, $vehicleId]);
                }
            }
        }

        $insertCurrent = $this->pdo->prepare('INSERT IGNORE INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)');
        $insertHistory = $this->pdo->prepare(
            'INSERT INTO user_vehicle_access(user_id,vehicle_id,assigned_by_user_id,valid_from,valid_to) VALUES(?,?,?,?,NULL)'
        );
        foreach ($toAdd as $vehicleId) {
            $insertCurrent->execute([$userId, $vehicleId]);
            $insertHistory->execute([$userId, $vehicleId, (int)$actor['id'], $effectiveAt]);
        }
    }

    /**
     * Přiřadí právě založené vozidlo jeho správci a založí historii přístupu.
     *
     * Tato metoda je určena pro samoobslužné založení vozidla managerem. Nové
     * vozidlo ještě není součástí jeho vozového parku, proto zde nelze použít
     * obecný syncAssignments(), který záměrně povoluje jen již dostupná auta.
     *
     * @param array<string,mixed> $actor
     */
    public function assignCreatedVehicle(array $actor, int $vehicleId, string $effectiveDate): void
    {
        if ((string)($actor['role'] ?? '') !== 'manager') {
            throw new RuntimeException('Automatické přiřazení nového vozidla je určeno správci vozidel.');
        }
        if ($vehicleId <= 0) {
            throw new RuntimeException('Neplatné vozidlo pro přiřazení.');
        }

        $userId = (int)$actor['id'];
        $effectiveAt = $this->normaliseDate($effectiveDate);

        $vehicleQuery = $this->pdo->prepare('SELECT 1 FROM vehicles WHERE id=?');
        $vehicleQuery->execute([$vehicleId]);
        if (!$vehicleQuery->fetchColumn()) {
            throw new RuntimeException('Vozidlo pro přiřazení nebylo nalezeno.');
        }

        $insertCurrent = $this->pdo->prepare(
            'INSERT IGNORE INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)'
        );
        $insertCurrent->execute([$userId, $vehicleId]);

        $activeAccess = $this->pdo->prepare(
            'SELECT 1 FROM user_vehicle_access WHERE user_id=? AND vehicle_id=? AND valid_to IS NULL LIMIT 1'
        );
        $activeAccess->execute([$userId, $vehicleId]);
        if (!$activeAccess->fetchColumn()) {
            $insertHistory = $this->pdo->prepare(
                'INSERT INTO user_vehicle_access(user_id,vehicle_id,assigned_by_user_id,valid_from,valid_to) '
                . 'VALUES(?,?,?,?,NULL)'
            );
            $insertHistory->execute([$userId, $vehicleId, $userId, $effectiveAt]);
        }

        $this->pdo->prepare(
            'UPDATE users SET default_vehicle_id=COALESCE(default_vehicle_id, ?) WHERE id=?'
        )->execute([$vehicleId, $userId]);
    }

    /** @param array<string,mixed> $user @return array{from:?string,to:?string,restricted:bool} */
    public function vehicleDetailScope(array $user, int $vehicleId): array
    {
        if ((string)$user['role'] === 'admin') {
            return ['from' => null, 'to' => null, 'restricted' => false];
        }
        $q = $this->pdo->prepare(
            'SELECT valid_from,valid_to FROM user_vehicle_access WHERE user_id=? AND vehicle_id=? AND valid_to IS NULL ORDER BY valid_from DESC LIMIT 1'
        );
        $q->execute([(int)$user['id'], $vehicleId]);
        $row = $q->fetch();
        if (!$row) {
            return ['from' => null, 'to' => null, 'restricted' => true];
        }
        return ['from' => (string)$row['valid_from'], 'to' => null, 'restricted' => true];
    }

    /** @param array<string,mixed> $user */
    public function canReadDetailAt(array $user, int $vehicleId, ?string $dateTime): bool
    {
        if ((string)$user['role'] === 'admin') {
            return true;
        }
        if ($dateTime === null || trim($dateTime) === '') {
            return false;
        }
        $scope = $this->vehicleDetailScope($user, $vehicleId);
        if (empty($scope['from'])) {
            return false;
        }
        $value = strtotime($dateTime);
        $from = strtotime((string)$scope['from']);
        return $value !== false && $from !== false && $value >= $from;
    }

    private function normaliseDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return date('Y-m-d H:i:s');
        }
        $timestamp = strtotime($date . (strlen($date) === 10 ? ' 00:00:00' : ''));
        if ($timestamp === false) {
            throw new RuntimeException('Neplatné datum účinnosti přiřazení.');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}

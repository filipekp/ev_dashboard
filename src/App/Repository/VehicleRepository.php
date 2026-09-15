<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Repository vozidel.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class VehicleRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM vehicles WHERE id=?');
        $query->execute([$id]);
        $row = $query->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByVin(string $vin): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM vehicles WHERE UPPER(vin)=? LIMIT 1');
        $query->execute([strtoupper($vin)]);
        $row = $query->fetch();

        return $row ?: null;
    }

    /** @param array<string,mixed> $meta */
    public function createFromImport(string $vin, array $meta): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicles
                (name, vin, powertrain_type, battery_kwh, battery_nominal_kwh)
             VALUES (?, ?, ?, ?, ?)'
        );
        $query->execute([
            (string)$meta['suggested_name'],
            $vin,
            (string)($meta['powertrain_type'] ?? 'BEV'),
            (float)$meta['battery_kwh'],
            (float)$meta['battery_nominal_kwh'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function assignUser(int $userId, int $vehicleId): void
    {
        $query = $this->pdo->prepare(
            'INSERT IGNORE INTO user_vehicles(user_id, vehicle_id) VALUES(?, ?)'
        );
        $query->execute([$userId, $vehicleId]);
    }

    public function delete(int $id): void
    {
        $query = $this->pdo->prepare('DELETE FROM vehicles WHERE id=?');
        $query->execute([$id]);
    }
}

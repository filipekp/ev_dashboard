<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

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
                (name, vin, manufacturer, powertrain_type, battery_kwh, battery_nominal_kwh)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $query->execute([
            (string)$meta['suggested_name'],
            $vin,
            strtoupper((string)($meta['manufacturer'] ?? '')),
            (string)($meta['powertrain_type'] ?? 'BEV'),
            (float)$meta['battery_kwh'],
            (float)$meta['battery_nominal_kwh'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Založí vozidlo a atomicky jej přiřadí konkrétnímu uživateli.
     *
     * Používá se pro samoobslužné založení vozidla řidičem. Uživatel tak
     * nikdy nezíská přístup k jinému existujícímu vozidlu pouze zadáním VIN.
     *
     * @param array<string,mixed> $vehicle
     */
    public function createForUser(int $userId, array $vehicle): int
    {
        $this->pdo->beginTransaction();

        try {
            $query = $this->pdo->prepare(
                'INSERT INTO vehicles
                    (name, vin, manufacturer, powertrain_type, battery_kwh, battery_nominal_kwh, fuel_tank_l,
                     registration_plate, first_registration_date, odometer_km, soh_manual_pct, soh_manual_at, home_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL, NULL, NOW()), ?)'
            );
            $query->execute([
                (string)$vehicle['name'],
                (string)$vehicle['vin'],
                (string)$vehicle['manufacturer'],
                (string)$vehicle['powertrain_type'],
                (float)$vehicle['battery_kwh'],
                $vehicle['battery_nominal_kwh'],
                $vehicle['fuel_tank_l'],
                $vehicle['registration_plate'],
                $vehicle['first_registration_date'],
                $vehicle['odometer_km'],
                $vehicle['soh_manual_pct'],
                $vehicle['soh_manual_pct'],
                $vehicle['home_label'],
            ]);

            $vehicleId = (int)$this->pdo->lastInsertId();
            $this->assignUser($userId, $vehicleId);

            $this->pdo->commit();

            return $vehicleId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

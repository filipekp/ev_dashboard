<?php

declare(strict_types=1);
namespace App\Repository;

use PDO;

final class VehicleRepository
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array { $q=$this->pdo->prepare('SELECT * FROM vehicles WHERE id=?'); $q->execute([$id]); $r=$q->fetch(); return $r ?: null; }
    /** @return array<string,mixed>|null */
    public function findByVin(string $vin): ?array { $q=$this->pdo->prepare('SELECT * FROM vehicles WHERE UPPER(vin)=? LIMIT 1'); $q->execute([strtoupper($vin)]); $r=$q->fetch(); return $r ?: null; }
    /** @param array<string,mixed> $meta */
    public function createFromImport(string $vin, array $meta): int
    {
        $q=$this->pdo->prepare('INSERT INTO vehicles(name,vin,battery_kwh,battery_nominal_kwh) VALUES(?,?,?,?)');
        $q->execute([(string)$meta['suggested_name'],$vin,(float)$meta['battery_kwh'],(float)$meta['battery_nominal_kwh']]); return (int)$this->pdo->lastInsertId();
    }
    public function assignUser(int $userId,int $vehicleId): void { $q=$this->pdo->prepare('INSERT IGNORE INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)'); $q->execute([$userId,$vehicleId]); }
    public function delete(int $id): void { $q=$this->pdo->prepare('DELETE FROM vehicles WHERE id=?'); $q->execute([$id]); }
}

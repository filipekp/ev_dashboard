<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Repository fotografií a dalších médií vozidel. */
final class VehicleMediaRepository
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @param array<string,mixed> $data */
    public function add(int $vehicleId, int $userId, array $data): int
    {
        $this->pdo->beginTransaction();
        if (!empty($data['is_primary'])) {
            $q = $this->pdo->prepare('UPDATE vehicle_media SET is_primary=0 WHERE vehicle_id=?');
            $q->execute([$vehicleId]);
        }
        $q = $this->pdo->prepare(
            'INSERT INTO vehicle_media(vehicle_id,user_id,media_type,original_name,stored_name,mime_type,file_size,caption,is_primary)
             VALUES(?,?,\'photo\',?,?,?,?,?,?)'
        );
        $q->execute([$vehicleId,$userId,$data['original_name'],$data['stored_name'],$data['mime_type'],$data['file_size'],$data['caption'],!empty($data['is_primary']) ? 1 : 0]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->commit();
        return $id;
    }

    public function setPrimary(int $vehicleId, int $mediaId): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE vehicle_media SET is_primary=0 WHERE vehicle_id=?')->execute([$vehicleId]);
        $this->pdo->prepare('UPDATE vehicle_media SET is_primary=1 WHERE id=? AND vehicle_id=?')->execute([$mediaId,$vehicleId]);
        $this->pdo->commit();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForVehicle(int $vehicleId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM vehicle_media WHERE vehicle_id=? ORDER BY is_primary DESC,id DESC');
        $q->execute([$vehicleId]);
        return $q->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM vehicle_media WHERE id=?');
        $q->execute([$id]);
        $row = $q->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function primaryForVehicle(int $vehicleId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM vehicle_media WHERE vehicle_id=? ORDER BY is_primary DESC,id DESC LIMIT 1');
        $q->execute([$vehicleId]);
        $row = $q->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> indexed by vehicle_id */
    public function primaries(array $vehicleIds): array
    {
        $result = [];
        foreach ($vehicleIds as $vehicleId) {
            $row = $this->primaryForVehicle((int)$vehicleId);
            if ($row) $result[(int)$vehicleId] = $row;
        }
        return $result;
    }
}

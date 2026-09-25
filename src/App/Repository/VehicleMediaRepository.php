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
    public function listForVehicle(int $vehicleId, ?string $from = null): array
    {
        $sql = 'SELECT * FROM vehicle_media WHERE vehicle_id=?';
        $params = [$vehicleId];
        if ($from !== null) {
            $sql .= ' AND created_at>=?';
            $params[] = $from;
        }
        $sql .= ' ORDER BY is_primary DESC,id DESC';
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll();
    }

    /**
     * Odstraní fotografii a pokud byla hlavní, zvolí jako novou hlavní
     * nejnovější zbývající fotografii stejného vozidla.
     */
    public function delete(int $vehicleId, int $mediaId): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $q = $this->pdo->prepare(
                'SELECT id,is_primary FROM vehicle_media WHERE id=? AND vehicle_id=? LIMIT 1 FOR UPDATE'
            );
            $q->execute([$mediaId, $vehicleId]);
            $row = $q->fetch();
            if (!$row) {
                throw new \RuntimeException('Fotografie nebyla nalezena.');
            }

            $delete = $this->pdo->prepare('DELETE FROM vehicle_media WHERE id=? AND vehicle_id=?');
            $delete->execute([$mediaId, $vehicleId]);
            if ($delete->rowCount() !== 1) {
                throw new \RuntimeException('Fotografii se nepodařilo smazat.');
            }

            if (!empty($row['is_primary'])) {
                $next = $this->pdo->prepare(
                    'SELECT id FROM vehicle_media WHERE vehicle_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $next->execute([$vehicleId]);
                $nextId = (int)$next->fetchColumn();
                if ($nextId > 0) {
                    $this->pdo->prepare(
                        'UPDATE vehicle_media SET is_primary=1 WHERE id=? AND vehicle_id=?'
                    )->execute([$nextId, $vehicleId]);
                }
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Persistence archivovaných vzorků neznámých importních formátů.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class UnknownImportRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO unknown_import_samples (
                token,
                user_id,
                vehicle_id,
                original_name,
                stored_name,
                mime_type,
                file_size,
                sha256,
                file_format,
                headers_json,
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $query->execute([
            $data['token'],
            $data['user_id'],
            $data['vehicle_id'],
            $data['original_name'],
            $data['stored_name'],
            $data['mime_type'],
            $data['file_size'],
            $data['sha256'],
            $data['file_format'],
            $data['headers_json'],
            'pending_mapping',
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findByTokenForUser(string $token, int $userId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT * FROM unknown_import_samples WHERE token=? AND user_id=? LIMIT 1'
        );
        $query->execute([$token, $userId]);
        $row = $query->fetch();

        return $row ?: null;
    }

    /** @param array<string,mixed> $mapping */
    public function markImported(
        int $id,
        array $mapping,
        int $runId,
        int $imported,
        int $skipped
    ): void {
        $query = $this->pdo->prepare(
            "UPDATE unknown_import_samples
             SET status='imported',
                 mapping_json=?,
                 integration_import_run_id=?,
                 imported_count=?,
                 skipped_count=?,
                 error_message=NULL,
                 mapped_at=NOW()
             WHERE id=?"
        );
        $query->execute([
            json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
            $imported,
            $skipped,
            $id,
        ]);
    }

    public function markFailed(int $id, string $error): void
    {
        $query = $this->pdo->prepare(
            "UPDATE unknown_import_samples SET status='failed', error_message=? WHERE id=?"
        );
        $query->execute([$error, $id]);
    }

    /** @param array<string,mixed> $profile */
    public function saveProfile(int $userId, string $name, array $profile): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO csv_mapping_profiles(user_id, name, mapping_json) VALUES(?, ?, ?)'
        );
        $query->execute([
            $userId,
            $name,
            json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Persistence historie běhů importních integrací.
 *
 * Každý běh je nejprve založen ve stavu running a po dokončení se aktualizuje
 * na success nebo failed. Repository neřeší rozpoznání zdroje ani oprávnění
 * uživatele; tyto odpovědnosti zůstávají ve service vrstvě.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class IntegrationImportRunRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function start(
        int $userId,
        int $vehicleId,
        string $sourceType,
        ?string $sourceLabel = null
    ): int {
        $query = $this->pdo->prepare(
            'INSERT INTO integration_import_runs (
                user_id,
                vehicle_id,
                source_type,
                source_label,
                status,
                imported_count,
                skipped_count,
                started_at
            ) VALUES (?, ?, ?, ?, ?, 0, 0, NOW())'
        );
        $query->execute([
            $userId,
            $vehicleId,
            $this->limit($sourceType, 64),
            $sourceLabel !== null ? $this->limit($sourceLabel, 190) : null,
            'running',
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function completeSuccess(
        int $runId,
        int $importedCount,
        int $skippedCount,
        ?string $cursorValue = null
    ): void {
        $query = $this->pdo->prepare(
            'UPDATE integration_import_runs
             SET status = ?,
                 imported_count = ?,
                 skipped_count = ?,
                 cursor_value = ?,
                 error_message = NULL,
                 finished_at = NOW()
             WHERE id = ?'
        );
        $query->execute([
            'success',
            max(0, $importedCount),
            max(0, $skippedCount),
            $cursorValue !== null ? $this->limit($cursorValue, 500) : null,
            $runId,
        ]);
    }

    public function completeFailure(int $runId, string $errorMessage): void
    {
        $query = $this->pdo->prepare(
            'UPDATE integration_import_runs
             SET status = ?,
                 error_message = ?,
                 finished_at = NOW()
             WHERE id = ?'
        );
        $query->execute([
            'failed',
            $this->limit($errorMessage, 65535),
            $runId,
        ]);
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }
}

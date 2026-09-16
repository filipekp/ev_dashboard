<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Read-only datová vrstva pro administrátorský monitoring importů.
 *
 * Sjednocuje provozní pohled nad importy jízd, dokumentů a neznámých formátů.
 * Veškeré dotazy jsou globální napříč uživateli a vozidly, proto smí být
 * repository používáno pouze za admin autorizací v controlleru.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class AdminImportMonitoringRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string,int> */
    public function summary(?string $from): array
    {
        $integrationWhere = $from !== null ? ' WHERE r.started_at >= ?' : '';
        $documentWhere = $from !== null ? ' WHERE r.created_at >= ?' : '';
        $unknownWhere = $from !== null ? ' WHERE s.created_at >= ?' : '';

        $integration = $this->fetchOne(
            'SELECT
                COUNT(*) total,
                SUM(r.status = \'running\') running,
                SUM(r.status = \'success\') success,
                SUM(r.status = \'failed\') failed,
                COALESCE(SUM(r.imported_count), 0) imported,
                COALESCE(SUM(r.skipped_count), 0) skipped
             FROM integration_import_runs r' . $integrationWhere,
            $from !== null ? [$from] : []
        );

        $documents = $this->fetchOne(
            'SELECT
                COUNT(*) total,
                SUM(r.status = \'uploaded\') uploaded,
                SUM(r.status = \'processing\') processing,
                SUM(r.status = \'review\') review,
                SUM(r.status = \'confirmed\') confirmed,
                SUM(r.status = \'error\') errors
             FROM document_import_runs r' . $documentWhere,
            $from !== null ? [$from] : []
        );

        $unknown = $this->fetchOne(
            'SELECT
                COUNT(*) total,
                SUM(s.status = \'pending_mapping\') pending,
                SUM(s.status = \'imported\') imported,
                SUM(s.status = \'failed\') failed
             FROM unknown_import_samples s' . $unknownWhere,
            $from !== null ? [$from] : []
        );

        return [
            'integration_total' => (int)($integration['total'] ?? 0),
            'integration_running' => (int)($integration['running'] ?? 0),
            'integration_success' => (int)($integration['success'] ?? 0),
            'integration_failed' => (int)($integration['failed'] ?? 0),
            'rows_imported' => (int)($integration['imported'] ?? 0),
            'rows_skipped' => (int)($integration['skipped'] ?? 0),
            'document_total' => (int)($documents['total'] ?? 0),
            'document_uploaded' => (int)($documents['uploaded'] ?? 0),
            'document_processing' => (int)($documents['processing'] ?? 0),
            'document_review' => (int)($documents['review'] ?? 0),
            'document_confirmed' => (int)($documents['confirmed'] ?? 0),
            'document_errors' => (int)($documents['errors'] ?? 0),
            'unknown_total' => (int)($unknown['total'] ?? 0),
            'unknown_pending' => (int)($unknown['pending'] ?? 0),
            'unknown_imported' => (int)($unknown['imported'] ?? 0),
            'unknown_failed' => (int)($unknown['failed'] ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function integrationRuns(?string $from, ?string $status, ?string $sourceType, int $limit = 150): array
    {
        $conditions = [];
        $params = [];
        if ($from !== null) {
            $conditions[] = 'r.started_at >= ?';
            $params[] = $from;
        }
        if ($status !== null) {
            $conditions[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($sourceType !== null) {
            $conditions[] = 'r.source_type = ?';
            $params[] = $sourceType;
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $query = $this->pdo->prepare(
            'SELECT r.*, u.name user_name, u.email user_email,
                    v.name vehicle_name, v.vin vehicle_vin, v.registration_plate
             FROM integration_import_runs r
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN vehicles v ON v.id = r.vehicle_id' .
             $where .
             ' ORDER BY r.started_at DESC, r.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function failedIntegrationRuns(?string $from, int $limit = 100): array
    {
        return $this->integrationRuns($from, 'failed', null, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function documentRuns(?string $from, ?string $status, int $limit = 150): array
    {
        $conditions = [];
        $params = [];
        if ($from !== null) {
            $conditions[] = 'r.created_at >= ?';
            $params[] = $from;
        }
        if ($status !== null) {
            $conditions[] = 'r.status = ?';
            $params[] = $status;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $query = $this->pdo->prepare(
            'SELECT r.*, d.original_name, d.document_type, d.provider,
                    u.name user_name, u.email user_email,
                    v.name vehicle_name, v.vin vehicle_vin, v.registration_plate
             FROM document_import_runs r
             JOIN vehicle_documents d ON d.id = r.document_id
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN vehicles v ON v.id = r.vehicle_id' .
             $where .
             ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function unknownImports(?string $from, int $limit = 100): array
    {
        $where = $from !== null ? ' WHERE s.created_at >= ?' : '';
        $query = $this->pdo->prepare(
            'SELECT s.*, u.name user_name, u.email user_email,
                    v.name vehicle_name, v.vin vehicle_vin, v.registration_plate
             FROM unknown_import_samples s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN vehicles v ON v.id = s.vehicle_id' .
             $where .
             ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $query->execute($from !== null ? [$from] : []);

        return $query->fetchAll();
    }

    /** @return array<int,string> */
    public function sourceTypes(): array
    {
        $query = $this->pdo->query(
            'SELECT DISTINCT source_type FROM integration_import_runs
             WHERE source_type IS NOT NULL AND source_type <> \'\'
             ORDER BY source_type'
        );

        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<int,mixed> $params @return array<string,mixed> */
    private function fetchOne(string $sql, array $params): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $row = $query->fetch();

        return $row ?: [];
    }
}

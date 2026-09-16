<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Datová vrstva dokumentového centra. */
final class DocumentRepository
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @param array<string,mixed> $data */
    public function createDocument(int $vehicleId, int $userId, array $data): int
    {
        $q = $this->pdo->prepare(
            'INSERT INTO vehicle_documents
                (vehicle_id,user_id,document_type,original_name,stored_name,mime_type,file_size,sha256,provider,document_date)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $q->execute([
            $vehicleId, $userId, $data['document_type'], $data['original_name'], $data['stored_name'],
            $data['mime_type'], $data['file_size'], $data['sha256'], $data['provider'], $data['document_date'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findDuplicate(int $vehicleId, string $sha256): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM vehicle_documents WHERE vehicle_id=? AND sha256=? LIMIT 1');
        $q->execute([$vehicleId, $sha256]);
        $row = $q->fetch();
        return $row ?: null;
    }

    public function createRun(int $documentId, int $vehicleId, int $userId): int
    {
        $q = $this->pdo->prepare('INSERT INTO document_import_runs(document_id,vehicle_id,user_id,status) VALUES(?,?,?,\'uploaded\')');
        $q->execute([$documentId, $vehicleId, $userId]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function saveExtraction(int $runId, string $extractor, array $data): void
    {
        $q = $this->pdo->prepare(
            'UPDATE document_import_runs SET extractor=?,status=\'review\',confidence=?,extracted_json=?,error_message=NULL WHERE id=?'
        );
        $q->execute([
            $extractor,
            isset($data['confidence']) ? (float)$data['confidence'] : null,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
        ]);
    }

    public function markProcessing(int $runId, string $extractor): void
    {
        $q = $this->pdo->prepare('UPDATE document_import_runs SET status=\'processing\',extractor=? WHERE id=?');
        $q->execute([$extractor, $runId]);
    }

    public function markError(int $runId, string $message): void
    {
        $q = $this->pdo->prepare('UPDATE document_import_runs SET status=\'error\',error_message=? WHERE id=?');
        $q->execute([$message, $runId]);
    }

    public function markConfirmed(int $runId): void
    {
        $q = $this->pdo->prepare('UPDATE document_import_runs SET status=\'confirmed\',confirmed_at=NOW() WHERE id=? AND status=\'review\'');
        $q->execute([$runId]);
    }

    /** @param array<string,mixed> $data */
    public function updateDocumentMetadata(int $documentId, array $data): void
    {
        $q = $this->pdo->prepare('UPDATE vehicle_documents SET document_type=?,provider=?,document_date=? WHERE id=?');
        $q->execute([$data['document_type'], $data['provider'], $data['document_date'], $documentId]);
    }

    /** @return array<string,mixed>|null */
    public function run(int $runId): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT r.*, d.original_name,d.stored_name,d.mime_type,d.file_size,d.document_type,d.provider,d.document_date,d.sha256
             FROM document_import_runs r JOIN vehicle_documents d ON d.id=r.document_id WHERE r.id=?'
        );
        $q->execute([$runId]);
        $row = $q->fetch();
        if (!$row) return null;
        $row['extracted'] = $row['extracted_json'] ? json_decode((string)$row['extracted_json'], true) : null;
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForVehicle(int $vehicleId, int $limit = 100, ?string $from = null): array
    {
        $q = $this->pdo->prepare(
            'SELECT d.*, r.id import_run_id,r.status import_status,r.extractor,r.confidence,r.error_message,r.confirmed_at
             FROM vehicle_documents d
             LEFT JOIN document_import_runs r ON r.id=(SELECT MAX(r2.id) FROM document_import_runs r2 WHERE r2.document_id=d.id)
             WHERE d.vehicle_id=?' . ($from !== null ? ' AND d.created_at>=?' : '') . ' ORDER BY d.created_at DESC,d.id DESC LIMIT ' . (int)$limit
        );
        $params = [$vehicleId];
        if ($from !== null) {
            $params[] = $from;
        }
        $q->execute($params);
        return $q->fetchAll();
    }

    /** @return array<string,mixed>|null */
    /**
     * Propojí zdrojový dokument s provozním záznamem vytvořeným jeho importem.
     */
    public function linkOperation(
        int $documentId,
        int $vehicleId,
        string $operationType,
        int $operationId
    ): void {
        if (!in_array($operationType, ['energy', 'service', 'expense'], true)) {
            throw new \InvalidArgumentException('Neplatný typ provozního záznamu.');
        }

        $query = $this->pdo->prepare(
            'INSERT INTO document_operation_links
                (document_id, vehicle_id, operation_type, operation_id)
             VALUES (?, ?, ?, ?)'
        );
        $query->execute([$documentId, $vehicleId, $operationType, $operationId]);
    }

    public function document(int $documentId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM vehicle_documents WHERE id=?');
        $q->execute([$documentId]);
        $row = $q->fetch();
        return $row ?: null;
    }
}

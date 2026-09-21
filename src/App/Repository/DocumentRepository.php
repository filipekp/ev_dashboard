<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Datová vrstva dokumentového centra. */
final class DocumentRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @param array<string,mixed> $data */
    public function createDocument(int $vehicleId, int $userId, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_documents
                (vehicle_id,user_id,document_type,original_name,stored_name,mime_type,file_size,sha256,provider,document_date)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $query->execute([
            $vehicleId,
            $userId,
            $data['document_type'],
            $data['original_name'],
            $data['stored_name'],
            $data['mime_type'],
            $data['file_size'],
            $data['sha256'],
            $data['provider'],
            $data['document_date'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findDuplicate(int $vehicleId, string $sha256): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT * FROM vehicle_documents WHERE vehicle_id=? AND sha256=? LIMIT 1'
        );
        $query->execute([$vehicleId, $sha256]);
        $row = $query->fetch();

        return $row ?: null;
    }

    public function createRun(int $documentId, int $vehicleId, int $userId): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO document_import_runs(document_id,vehicle_id,user_id,status)
             VALUES(?,?,?,\'uploaded\')'
        );
        $query->execute([$documentId, $vehicleId, $userId]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function saveExtraction(int $runId, string $extractor, array $data): void
    {
        $query = $this->pdo->prepare(
            'UPDATE document_import_runs
             SET extractor=?,status=\'review\',confidence=?,extracted_json=?,error_message=NULL
             WHERE id=?'
        );
        $query->execute([
            $extractor,
            isset($data['confidence']) ? (float)$data['confidence'] : null,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
        ]);
    }

    public function markProcessing(int $runId, string $extractor): void
    {
        $query = $this->pdo->prepare(
            'UPDATE document_import_runs SET status=\'processing\',extractor=? WHERE id=?'
        );
        $query->execute([$extractor, $runId]);
    }

    public function markError(int $runId, string $message): void
    {
        $query = $this->pdo->prepare(
            'UPDATE document_import_runs SET status=\'error\',error_message=? WHERE id=?'
        );
        $query->execute([$message, $runId]);
    }

    public function markConfirmed(int $runId): void
    {
        $query = $this->pdo->prepare(
            'UPDATE document_import_runs
             SET status=\'confirmed\',confirmed_at=NOW()
             WHERE id=? AND status=\'review\''
        );
        $query->execute([$runId]);
    }

    /** @param array<string,mixed> $data */
    public function updateDocumentMetadata(int $documentId, array $data): void
    {
        $query = $this->pdo->prepare(
            'UPDATE vehicle_documents SET document_type=?,provider=?,document_date=? WHERE id=?'
        );
        $query->execute([
            $data['document_type'],
            $data['provider'],
            $data['document_date'],
            $documentId,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function run(int $runId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT r.*, d.original_name,d.stored_name,d.mime_type,d.file_size,
                    d.document_type,d.provider,d.document_date,d.sha256
             FROM document_import_runs r
             JOIN vehicle_documents d ON d.id=r.document_id
             WHERE r.id=?'
        );
        $query->execute([$runId]);
        $row = $query->fetch();
        if (!$row) {
            return null;
        }

        $row['extracted'] = $row['extracted_json']
            ? json_decode((string)$row['extracted_json'], true)
            : null;

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function runForUpdate(int $runId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT r.*, d.original_name,d.stored_name,d.mime_type,d.file_size,
                    d.document_type,d.provider,d.document_date,d.sha256
             FROM document_import_runs r
             JOIN vehicle_documents d ON d.id=r.document_id
             WHERE r.id=?
             FOR UPDATE'
        );
        $query->execute([$runId]);
        $row = $query->fetch();
        if (!$row) {
            return null;
        }

        $row['extracted'] = $row['extracted_json']
            ? json_decode((string)$row['extracted_json'], true)
            : null;

        return $row;
    }

    public function isLatestRun(int $documentId, int $runId): bool
    {
        $query = $this->pdo->prepare(
            'SELECT id FROM document_import_runs WHERE document_id=? ORDER BY id DESC LIMIT 1'
        );
        $query->execute([$documentId]);

        return (int)$query->fetchColumn() === $runId;
    }

    /** @return array<int,array<string,mixed>> */
    public function runsForDocument(int $documentId, ?string $from = null, int $limit = 25): array
    {
        $sql = 'SELECT id,document_id,vehicle_id,user_id,extractor,status,confidence,
                       error_message,confirmed_at,created_at,updated_at
                FROM document_import_runs
                WHERE document_id=?';
        $params = [$documentId];

        if ($from !== null) {
            $sql .= ' AND created_at>=?';
            $params[] = $from;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, (int)$limit);
        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForVehicle(int $vehicleId, int $limit = 100, ?string $from = null): array
    {
        $query = $this->pdo->prepare(
            'SELECT d.*, r.id import_run_id,r.status import_status,r.extractor,
                    r.confidence,r.error_message,r.confirmed_at
             FROM vehicle_documents d
             LEFT JOIN document_import_runs r
               ON r.id=(
                    SELECT MAX(r2.id)
                    FROM document_import_runs r2
                    WHERE r2.document_id=d.id
               )
             WHERE d.vehicle_id=?' . ($from !== null ? ' AND d.created_at>=?' : '') . '
             ORDER BY d.created_at DESC,d.id DESC
             LIMIT ' . (int)$limit
        );
        $params = [$vehicleId];
        if ($from !== null) {
            $params[] = $from;
        }
        $query->execute($params);

        return $query->fetchAll();
    }

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
            throw new InvalidArgumentException('Neplatný typ provozního záznamu.');
        }

        $query = $this->pdo->prepare(
            'INSERT INTO document_operation_links
                (document_id, vehicle_id, operation_type, operation_id)
             VALUES (?, ?, ?, ?)'
        );
        $query->execute([$documentId, $vehicleId, $operationType, $operationId]);
    }

    public function linkedOperationCount(int $documentId, int $vehicleId): int
    {
        $query = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM document_operation_links
             WHERE document_id=? AND vehicle_id=?'
        );
        $query->execute([$documentId, $vehicleId]);

        return (int)$query->fetchColumn();
    }

    /**
     * Odstraní pouze provozní položky, které vznikly potvrzením daného dokumentu.
     *
     * Volající musí tuto metodu používat uvnitř transakce; vazby se zamknou přes
     * FOR UPDATE, aby souběžné potvrzení nemohlo vytvořit duplicitní stav.
     *
     * @return int Počet odstraněných propojených provozních položek.
     */
    public function removeLinkedOperations(int $documentId, int $vehicleId): int
    {
        $query = $this->pdo->prepare(
            'SELECT operation_type,operation_id
             FROM document_operation_links
             WHERE document_id=? AND vehicle_id=?
             FOR UPDATE'
        );
        $query->execute([$documentId, $vehicleId]);
        $links = $query->fetchAll();

        if (!$links) {
            return 0;
        }

        $energySource = $this->pdo->prepare(
            'SELECT source,price_source FROM vehicle_energy_entries WHERE id=? AND vehicle_id=? FOR UPDATE'
        );
        $deleteEnergy = $this->pdo->prepare(
            'DELETE FROM vehicle_energy_entries WHERE id=? AND vehicle_id=?'
        );
        $detachEnergyPrice = $this->pdo->prepare(
            "UPDATE vehicle_energy_entries e
             JOIN vehicles v ON v.id=e.vehicle_id
             SET e.unit_price=v.default_electricity_price_per_kwh,
                 e.total_price=CASE
                     WHEN v.default_electricity_price_per_kwh IS NULL THEN NULL
                     ELSE ROUND(e.quantity * v.default_electricity_price_per_kwh, 2)
                 END,
                 e.currency=COALESCE(NULLIF(v.default_energy_currency,''),e.currency),
                 e.price_source=CASE WHEN v.default_electricity_price_per_kwh IS NULL THEN 'none' ELSE 'default' END
             WHERE e.id=? AND e.vehicle_id=? AND e.price_source='document'"
        );
        $deleteService = $this->pdo->prepare(
            'DELETE FROM vehicle_service_records WHERE id=? AND vehicle_id=?'
        );
        $serviceAttachmentCount = $this->pdo->prepare(
            'SELECT COUNT(*) FROM vehicle_service_attachments WHERE service_record_id=?'
        );
        $deleteExpense = $this->pdo->prepare(
            'DELETE FROM vehicle_expenses WHERE id=? AND vehicle_id=?'
        );

        foreach ($links as $link) {
            $operationId = (int)$link['operation_id'];
            switch ((string)$link['operation_type']) {
                case 'energy':
                    $energySource->execute([$operationId, $vehicleId]);
                    $energy = $energySource->fetch();
                    if ($energy && (string)($energy['source'] ?? '') === 'document') {
                        $deleteEnergy->execute([$operationId, $vehicleId]);
                    } else {
                        // Telemetrický záznam existoval už před fakturou; při novém vytěžení
                        // odstraníme pouze cenu z dokumentu, nikoli samotnou nabíjecí relaci.
                        $detachEnergyPrice->execute([$operationId, $vehicleId]);
                    }
                    break;
                case 'service':
                    $serviceAttachmentCount->execute([$operationId]);
                    if ((int)$serviceAttachmentCount->fetchColumn() > 0) {
                        throw new RuntimeException(
                            'Původní servisní záznam vytvořený tímto dokumentem má vlastní přílohy. '
                            . 'Kvůli ochraně dat jej nelze automaticky nahradit.'
                        );
                    }
                    $deleteService->execute([$operationId, $vehicleId]);
                    break;
                case 'expense':
                    $deleteExpense->execute([$operationId, $vehicleId]);
                    break;
            }
        }

        $deleteLinks = $this->pdo->prepare(
            'DELETE FROM document_operation_links WHERE document_id=? AND vehicle_id=?'
        );
        $deleteLinks->execute([$documentId, $vehicleId]);

        return count($links);
    }

    /** @return array<string,mixed>|null */
    public function document(int $documentId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM vehicle_documents WHERE id=?');
        $query->execute([$documentId]);
        $row = $query->fetch();

        return $row ?: null;
    }
}

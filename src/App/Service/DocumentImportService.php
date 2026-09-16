<?php

declare(strict_types=1);

namespace App\Service;

use App\Document\Ai\AiDocumentExtractorInterface;
use App\Document\Parser\DocumentParserInterface;
use App\Document\Parser\DocumentParserRegistry;
use App\Repository\DocumentRepository;
use App\Repository\VehicleOperationRepository;
use App\UploadValidator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Orchestrátor dokumentového importu.
 *
 * Nový dokument se nejprve zkusí vytěžit lokálním parserem a teprve poté AI.
 * U již uloženého dokumentu lze vytěžení zopakovat automaticky, vynutit lokální
 * parser nebo vynutit AI. Každé spuštění vytváří nový document_import_run, takže
 * zůstává zachována historie pokusů i použitých extraktorů.
 */
final class DocumentImportService
{
    public const EXTRACTION_AUTO = 'auto';
    public const EXTRACTION_LOCAL = 'local';
    public const EXTRACTION_AI = 'ai';

    private const MAX_DOCUMENT_SIZE = 20971520;

    /** @var PDO */
    private $pdo;

    /** @var DocumentRepository */
    private $documents;

    /** @var VehicleOperationRepository */
    private $operations;

    /** @var DocumentParserRegistry */
    private $parsers;

    /** @var AiDocumentExtractorInterface|null */
    private $ai;

    /** @var string */
    private $storageRoot;

    public function __construct(
        PDO $pdo,
        DocumentRepository $documents,
        VehicleOperationRepository $operations,
        DocumentParserRegistry $parsers,
        ?AiDocumentExtractorInterface $ai,
        string $storageRoot
    ) {
        $this->pdo = $pdo;
        $this->documents = $documents;
        $this->operations = $operations;
        $this->parsers = $parsers;
        $this->ai = $ai;
        $this->storageRoot = rtrim($storageRoot, '/\\');
    }

    /**
     * Uloží nový dokument a spustí výchozí automatické vytěžení.
     *
     * @param array<string,mixed> $file
     */
    public function uploadAndExtract(int $vehicleId, int $userId, array $file): int
    {
        $tmp = UploadValidator::uploadedPath($file, self::MAX_DOCUMENT_SIZE);
        $size = (int)$file['size'];
        $mime = UploadValidator::mime($tmp);
        $allowed = $this->allowedMimeTypes();

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Podporujeme PDF, JPG, PNG, WebP, JSON a TXT.');
        }

        if (strpos($mime, 'image/') === 0) {
            UploadValidator::assertImageDimensions($tmp);
        }

        if ($mime === 'application/pdf') {
            $this->assertPdfMagic($tmp);
        }

        $sha = hash_file('sha256', $tmp) ?: '';
        if ($sha === '') {
            throw new RuntimeException('Nelze ověřit kontrolní součet dokumentu.');
        }

        if ($this->documents->findDuplicate($vehicleId, $sha)) {
            throw new RuntimeException('Tento dokument už je u vozidla uložen. Použijte u něj akci „Vytěžit znovu“.');
        }

        $dir = $this->storageRoot . '/documents';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit úložiště dokumentů.');
        }

        $stored = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $storedPath = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $storedPath)) {
            throw new RuntimeException('Dokument nelze uložit.');
        }
        @chmod($storedPath, 0640);

        $originalName = UploadValidator::safeOriginalName(
            (string)($file['name'] ?? ''),
            'document'
        );

        try {
            $documentId = $this->documents->createDocument($vehicleId, $userId, [
                'document_type' => 'unknown',
                'original_name' => $originalName,
                'stored_name' => $stored,
                'mime_type' => $mime,
                'file_size' => $size,
                'sha256' => $sha,
                'provider' => null,
                'document_date' => null,
            ]);
        } catch (Throwable $e) {
            @unlink($storedPath);
            throw $e;
        }

        $runId = $this->documents->createRun($documentId, $vehicleId, $userId);
        $this->executeExtraction(
            $runId,
            $documentId,
            $storedPath,
            $mime,
            $originalName,
            self::EXTRACTION_AUTO
        );

        return $runId;
    }

    /**
     * Znovu vytěží originální soubor již uloženého dokumentu.
     *
     * Původní import run ani případné potvrzené provozní záznamy se v této fázi
     * nemění. K jejich nahrazení dojde až po ručním potvrzení nového vytěžení.
     */
    public function reExtract(
        int $vehicleId,
        int $userId,
        int $documentId,
        string $mode = self::EXTRACTION_AUTO
    ): int {
        $mode = $this->normalizeExtractionMode($mode);
        $document = $this->documents->document($documentId);

        if (!$document || (int)$document['vehicle_id'] !== $vehicleId) {
            throw new RuntimeException('Dokument nebyl nalezen.');
        }

        $runId = $this->documents->createRun($documentId, $vehicleId, $userId);

        try {
            $path = $this->storedDocumentPath($document);
            $this->assertStoredDocumentIntegrity($document, $path);
            $this->executeExtraction(
                $runId,
                $documentId,
                $path,
                (string)$document['mime_type'],
                (string)$document['original_name'],
                $mode
            );
        } catch (Throwable $e) {
            $this->documents->markError($runId, $e->getMessage());
        }

        return $runId;
    }

    /**
     * Potvrdí nejnovější vytěžení dokumentu.
     *
     * Pokud byl dokument potvrzen už dříve, pouze provozní položky vytvořené
     * tímto konkrétním dokumentem se v jedné DB transakci nahradí novými.
     * Ručně vytvořené položky ani položky jiných dokumentů se nemažou.
     *
     * @return int Počet dříve propojených položek, které byly nahrazeny.
     */
    public function confirm(int $runId, int $vehicleId): int
    {
        $replaced = 0;

        $this->pdo->beginTransaction();
        try {
            // Zámek nad importním během (a jeho dokumentem přes JOIN) chrání před
            // dvojitým potvrzením ze dvou souběžných HTTP requestů.
            $run = $this->documents->runForUpdate($runId);
            if (!$run || (int)$run['vehicle_id'] !== $vehicleId) {
                throw new RuntimeException('Import nebyl nalezen.');
            }

            if ((string)$run['status'] === 'confirmed') {
                throw new RuntimeException('Toto vytěžení už bylo potvrzeno.');
            }

            if ((string)$run['status'] !== 'review' || !is_array($run['extracted'])) {
                throw new RuntimeException('Dokument není připraven k potvrzení.');
            }

            $documentId = (int)$run['document_id'];
            if (!$this->documents->isLatestRun($documentId, $runId)) {
                throw new RuntimeException(
                    'Toto vytěžení už není aktuální. Otevřete nejnovější běh dokumentu a potvrďte ten.'
                );
            }

            $data = $this->normalize($run['extracted']);
            $currency = (string)$data['currency'];
            $replaced = $this->documents->removeLinkedOperations($documentId, $vehicleId);

            foreach ($data['energy_entries'] as $row) {
                $energyType = (string)$row['energy_type'];
                $operationId = $this->operations->addEnergyEntry($vehicleId, [
                    'occurred_at' => $row['occurred_at'],
                    'entry_type' => $row['entry_type'],
                    'energy_type' => $energyType,
                    'quantity' => $row['quantity'],
                    'unit' => $energyType === 'electricity'
                        ? 'kWh'
                        : ($energyType === 'cng' ? 'kg' : 'l'),
                    'unit_price' => $row['unit_price'],
                    'total_price' => $row['total_price'],
                    'currency' => $currency,
                    'odometer_km' => $row['odometer_km'],
                    'station' => $row['station'],
                    'note' => $row['note'],
                ]);
                $this->documents->linkOperation($documentId, $vehicleId, 'energy', $operationId);
            }

            foreach ($data['service_records'] as $row) {
                $operationId = $this->operations->addServiceRecord($vehicleId, [
                    'serviced_at' => $row['serviced_at'],
                    'category' => $row['category'],
                    'title' => $row['title'],
                    'provider' => $row['provider'],
                    'odometer_km' => $row['odometer_km'],
                    'cost' => $row['cost'],
                    'currency' => $currency,
                    'note' => $row['note'],
                ]);
                $this->documents->linkOperation($documentId, $vehicleId, 'service', $operationId);
            }

            foreach ($data['expenses'] as $row) {
                $operationId = $this->operations->addExpense($vehicleId, [
                    'occurred_at' => $row['occurred_at'],
                    'category' => $row['category'],
                    'title' => $row['title'],
                    'amount' => $row['amount'],
                    'currency' => $currency,
                    'odometer_km' => $row['odometer_km'],
                    'note' => $row['note'],
                ]);
                $this->documents->linkOperation($documentId, $vehicleId, 'expense', $operationId);
            }

            $this->documents->markConfirmed($runId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $replaced;
    }

    private function executeExtraction(
        int $runId,
        int $documentId,
        string $path,
        string $mimeType,
        string $originalName,
        string $mode
    ): void {
        try {
            list($extractorName, $data) = $this->extract(
                $runId,
                $path,
                $mimeType,
                $originalName,
                $mode
            );

            $normalized = $this->normalize($data);
            $this->documents->saveExtraction($runId, $extractorName, $normalized);
            $this->documents->updateDocumentMetadata($documentId, [
                'document_type' => $normalized['document_type'],
                'provider' => $normalized['provider'],
                'document_date' => $normalized['document_date'],
            ]);
        } catch (Throwable $e) {
            $this->documents->markError($runId, $e->getMessage());
        }
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function extract(
        int $runId,
        string $path,
        string $mimeType,
        string $originalName,
        string $mode
    ): array {
        $mode = $this->normalizeExtractionMode($mode);

        if ($mode === self::EXTRACTION_AI) {
            if ($this->ai === null) {
                throw new RuntimeException('AI extraktor není nakonfigurován.');
            }

            $this->documents->markProcessing($runId, $this->ai->name());
            return [
                $this->ai->name(),
                $this->ai->extract($path, $mimeType, $originalName),
            ];
        }

        $parser = $this->parsers->resolve($path, $mimeType, $originalName);
        if ($parser instanceof DocumentParserInterface) {
            $this->documents->markProcessing($runId, $parser->name());
            return [
                $parser->name(),
                $parser->parse($path, $mimeType, $originalName),
            ];
        }

        if ($mode === self::EXTRACTION_LOCAL) {
            throw new RuntimeException('Pro tento dokument není dostupný žádný lokální parser.');
        }

        if ($this->ai === null) {
            throw new RuntimeException(
                'Pro tento typ dokumentu není dostupný lokální parser a AI extraktor není nakonfigurován.'
            );
        }

        $this->documents->markProcessing($runId, $this->ai->name());
        return [
            $this->ai->name(),
            $this->ai->extract($path, $mimeType, $originalName),
        ];
    }

    /** @param array<string,mixed> $document */
    private function storedDocumentPath(array $document): string
    {
        $storedName = (string)($document['stored_name'] ?? '');
        if ($storedName === '' || basename($storedName) !== $storedName) {
            throw new RuntimeException('Uložený název dokumentu je neplatný.');
        }

        return $this->storageRoot . '/documents/' . $storedName;
    }

    /** @param array<string,mixed> $document */
    private function assertStoredDocumentIntegrity(array $document, string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Originální soubor dokumentu už není v úložišti dostupný.');
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_DOCUMENT_SIZE) {
            throw new RuntimeException('Uložený dokument má neplatnou velikost.');
        }

        $expectedSha = strtolower(trim((string)($document['sha256'] ?? '')));
        $actualSha = hash_file('sha256', $path) ?: '';
        if ($expectedSha === '' || $actualSha === '' || !hash_equals($expectedSha, strtolower($actualSha))) {
            throw new RuntimeException('Kontrolní součet uloženého dokumentu nesouhlasí. Vytěžení bylo zastaveno.');
        }

        $mimeType = (string)($document['mime_type'] ?? '');
        if (!isset($this->allowedMimeTypes()[$mimeType])) {
            throw new RuntimeException('Uložený dokument má nepodporovaný MIME typ.');
        }

        if ($mimeType === 'application/pdf') {
            $this->assertPdfMagic($path);
        }
    }

    private function assertPdfMagic(string $path): void
    {
        $handle = fopen($path, 'rb');
        $magic = $handle ? fread($handle, 5) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($magic !== '%PDF-') {
            throw new RuntimeException('Soubor není platný PDF dokument.');
        }
    }

    /** @return array<string,string> */
    private function allowedMimeTypes(): array
    {
        return [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/json' => 'json',
            'text/plain' => 'txt',
        ];
    }

    private function normalizeExtractionMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::EXTRACTION_AUTO, self::EXTRACTION_LOCAL, self::EXTRACTION_AI], true)) {
            throw new RuntimeException('Neplatný režim vytěžení dokumentu.');
        }

        return $mode;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize(array $data): array
    {
        $types = [
            'unknown',
            'fuel_receipt',
            'charging_invoice',
            'service_invoice',
            'expense_receipt',
            'insurance',
            'inspection',
            'registration',
            'other',
        ];
        $type = in_array((string)($data['document_type'] ?? 'unknown'), $types, true)
            ? (string)$data['document_type']
            : 'unknown';

        return [
            'document_type' => $type,
            'provider' => $this->nullable($data['provider'] ?? null),
            'document_date' => $this->date($data['document_date'] ?? null),
            'currency' => strtoupper(trim((string)($data['currency'] ?? 'CZK'))) ?: 'CZK',
            'confidence' => max(0, min(1, (float)($data['confidence'] ?? 0))),
            'energy_entries' => $this->normalizeEnergy(
                is_array($data['energy_entries'] ?? null) ? $data['energy_entries'] : []
            ),
            'service_records' => $this->normalizeService(
                is_array($data['service_records'] ?? null) ? $data['service_records'] : []
            ),
            'expenses' => $this->normalizeExpenses(
                is_array($data['expenses'] ?? null) ? $data['expenses'] : []
            ),
        ];
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function normalizeEnergy(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (float)($row['quantity'] ?? 0) <= 0) {
                continue;
            }

            $energyType = (string)($row['energy_type'] ?? 'electricity');
            if (!in_array($energyType, ['electricity', 'petrol', 'diesel', 'lpg', 'cng'], true)) {
                continue;
            }

            $entryType = (string)($row['entry_type'] ?? ($energyType === 'electricity' ? 'charging' : 'fueling'));
            if (!in_array($entryType, ['charging', 'fueling'], true)) {
                $entryType = $energyType === 'electricity' ? 'charging' : 'fueling';
            }

            $out[] = [
                'occurred_at' => $this->dateTime($row['occurred_at'] ?? null),
                'entry_type' => $entryType,
                'energy_type' => $energyType,
                'quantity' => (float)$row['quantity'],
                'unit_price' => $this->number($row['unit_price'] ?? null),
                'total_price' => $this->number($row['total_price'] ?? null),
                'odometer_km' => $this->number($row['odometer_km'] ?? null),
                'station' => $this->nullable($row['station'] ?? null),
                'note' => $this->nullable($row['note'] ?? null),
            ];
        }

        return $out;
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function normalizeService(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row['title'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'serviced_at' => $this->date($row['serviced_at'] ?? null) ?: date('Y-m-d'),
                'category' => trim((string)($row['category'] ?? 'servis')) ?: 'servis',
                'title' => trim((string)$row['title']),
                'provider' => $this->nullable($row['provider'] ?? null),
                'odometer_km' => $this->number($row['odometer_km'] ?? null),
                'cost' => $this->number($row['cost'] ?? null),
                'note' => $this->nullable($row['note'] ?? null),
            ];
        }

        return $out;
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function normalizeExpenses(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || trim((string)($row['title'] ?? '')) === ''
                || !is_numeric($row['amount'] ?? null)
            ) {
                continue;
            }

            $out[] = [
                'occurred_at' => $this->date($row['occurred_at'] ?? null) ?: date('Y-m-d'),
                'category' => trim((string)($row['category'] ?? 'ostatni')) ?: 'ostatni',
                'title' => trim((string)$row['title']),
                'amount' => (float)$row['amount'],
                'odometer_km' => $this->number($row['odometer_km'] ?? null),
                'note' => $this->nullable($row['note'] ?? null),
            ];
        }

        return $out;
    }

    /** @param mixed $value */
    private function nullable($value): ?string
    {
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value)
            ? null
            : (float)$value;
    }

    /** @param mixed $value */
    private function date($value): ?string
    {
        $string = trim((string)$value);
        if ($string === '') {
            return null;
        }

        $timestamp = strtotime($string);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /** @param mixed $value */
    private function dateTime($value): string
    {
        $string = trim((string)$value);
        $timestamp = $string === '' ? false : strtotime($string);
        return $timestamp === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $timestamp);
    }
}

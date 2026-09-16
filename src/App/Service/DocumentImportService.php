<?php

declare(strict_types=1);

namespace App\Service;

use App\Document\Ai\AiDocumentExtractorInterface;
use App\Document\Parser\DocumentParserRegistry;
use App\Repository\DocumentRepository;
use App\UploadValidator;
use App\Repository\VehicleOperationRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Orchestrátor dokumentového importu.
 *
 * Pořadí je záměrně deterministický parser -> AI fallback -> ruční potvrzení.
 */
final class DocumentImportService
{
    /** @var PDO */ private $pdo;
    /** @var DocumentRepository */ private $documents;
    /** @var VehicleOperationRepository */ private $operations;
    /** @var DocumentParserRegistry */ private $parsers;
    /** @var AiDocumentExtractorInterface|null */ private $ai;
    /** @var string */ private $storageRoot;

    public function __construct(PDO $pdo, DocumentRepository $documents, VehicleOperationRepository $operations, DocumentParserRegistry $parsers, ?AiDocumentExtractorInterface $ai, string $storageRoot)
    {
        $this->pdo=$pdo; $this->documents=$documents; $this->operations=$operations; $this->parsers=$parsers; $this->ai=$ai; $this->storageRoot=rtrim($storageRoot,'/\\');
    }

    /** @param array<string,mixed> $file */
    public function uploadAndExtract(int $vehicleId, int $userId, array $file): int
    {
        $tmp = UploadValidator::uploadedPath($file, 20 * 1024 * 1024);
        $size = (int)$file['size'];
        $mime = UploadValidator::mime($tmp);
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/json' => 'json',
            'text/plain' => 'txt',
        ];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Podporujeme PDF, JPG, PNG, WebP, JSON a TXT.');
        }
        if (strpos($mime, 'image/') === 0) {
            UploadValidator::assertImageDimensions($tmp);
        }
        if ($mime === 'application/pdf') {
            $handle = fopen($tmp, 'rb');
            $magic = $handle ? fread($handle, 5) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($magic !== '%PDF-') {
                throw new RuntimeException('Soubor není platný PDF dokument.');
            }
        }
        $sha=hash_file('sha256',$tmp) ?: ''; if ($sha==='') throw new RuntimeException('Nelze ověřit kontrolní součet dokumentu.');
        $duplicate=$this->documents->findDuplicate($vehicleId,$sha);
        if ($duplicate) throw new RuntimeException('Tento dokument už je u vozidla uložen.');
        $dir=$this->storageRoot . '/documents'; if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Nelze vytvořit úložiště dokumentů.');
        $stored=bin2hex(random_bytes(20)).'.'.$allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) throw new RuntimeException('Dokument nelze uložit.');
        @chmod($dir . '/' . $stored, 0640);
        $documentId=$this->documents->createDocument($vehicleId,$userId,[
            'document_type'=>'unknown','original_name'=>UploadValidator::safeOriginalName((string)($file['name'] ?? ''), 'document'),'stored_name'=>$stored,'mime_type'=>$mime,'file_size'=>$size,'sha256'=>$sha,'provider'=>null,'document_date'=>null,
        ]);
        $runId=$this->documents->createRun($documentId,$vehicleId,$userId);
        $path=$dir.'/'.$stored; $name=(string)($file['name'] ?? 'document');
        try {
            $parser=$this->parsers->resolve($path,$mime,$name);
            if ($parser) { $this->documents->markProcessing($runId,$parser->name()); $data=$parser->parse($path,$mime,$name); $extractor=$parser->name(); }
            elseif ($this->ai) { $this->documents->markProcessing($runId,$this->ai->name()); $data=$this->ai->extract($path,$mime,$name); $extractor=$this->ai->name(); }
            else { throw new RuntimeException('Pro tento typ dokumentu není dostupný lokální parser a AI extraktor není nakonfigurován.'); }
            $data=$this->normalize($data);
            $this->documents->saveExtraction($runId,$extractor,$data);
            $this->documents->updateDocumentMetadata($documentId,[
                'document_type'=>$data['document_type'],'provider'=>$data['provider'],'document_date'=>$data['document_date'],
            ]);
        } catch (Throwable $e) { $this->documents->markError($runId,$e->getMessage()); }
        return $runId;
    }

    public function confirm(int $runId, int $vehicleId): void
    {
        $run=$this->documents->run($runId);
        if (!$run || (int)$run['vehicle_id']!==$vehicleId) throw new RuntimeException('Import nebyl nalezen.');
        if ((string)$run['status']==='confirmed') throw new RuntimeException('Tento dokument už byl potvrzen.');
        if ((string)$run['status']!=='review' || !is_array($run['extracted'])) throw new RuntimeException('Dokument není připraven k potvrzení.');
        $data=$this->normalize($run['extracted']);
        $currency=(string)$data['currency']; $documentId=(int)$run['document_id'];
        $this->pdo->beginTransaction();
        try {
            foreach ($data['energy_entries'] as $row) {
                $energyType=(string)$row['energy_type'];
                $operationId=$this->operations->addEnergyEntry($vehicleId,[
                    'occurred_at'=>$row['occurred_at'],'entry_type'=>$row['entry_type'],'energy_type'=>$energyType,'quantity'=>$row['quantity'],
                    'unit'=>$energyType==='electricity' ? 'kWh' : ($energyType==='cng' ? 'kg' : 'l'),'unit_price'=>$row['unit_price'],'total_price'=>$row['total_price'],
                    'currency'=>$currency,'odometer_km'=>$row['odometer_km'],'station'=>$row['station'],'note'=>$row['note'],
                ]);
                $this->documents->linkOperation($documentId,$vehicleId,'energy',$operationId);
            }
            foreach ($data['service_records'] as $row) {
                $operationId=$this->operations->addServiceRecord($vehicleId,[
                    'serviced_at'=>$row['serviced_at'],'category'=>$row['category'],'title'=>$row['title'],'provider'=>$row['provider'],'odometer_km'=>$row['odometer_km'],
                    'cost'=>$row['cost'],'currency'=>$currency,'note'=>$row['note'],
                ]);
                $this->documents->linkOperation($documentId,$vehicleId,'service',$operationId);
            }
            foreach ($data['expenses'] as $row) {
                $operationId=$this->operations->addExpense($vehicleId,[
                    'occurred_at'=>$row['occurred_at'],'category'=>$row['category'],'title'=>$row['title'],'amount'=>$row['amount'],'currency'=>$currency,'odometer_km'=>$row['odometer_km'],
                    'note'=>$row['note'],
                ]);
                $this->documents->linkOperation($documentId,$vehicleId,'expense',$operationId);
            }
            $this->documents->markConfirmed($runId);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalize(array $data): array
    {
        $types=['unknown','fuel_receipt','charging_invoice','service_invoice','expense_receipt','insurance','inspection','registration','other'];
        $type=in_array((string)($data['document_type'] ?? 'unknown'),$types,true) ? (string)$data['document_type'] : 'unknown';
        return [
            'document_type'=>$type,
            'provider'=>$this->nullable($data['provider'] ?? null),
            'document_date'=>$this->date($data['document_date'] ?? null),
            'currency'=>strtoupper(trim((string)($data['currency'] ?? 'CZK'))) ?: 'CZK',
            'confidence'=>max(0,min(1,(float)($data['confidence'] ?? 0))),
            'energy_entries'=>$this->normalizeEnergy(is_array($data['energy_entries'] ?? null) ? $data['energy_entries'] : []),
            'service_records'=>$this->normalizeService(is_array($data['service_records'] ?? null) ? $data['service_records'] : []),
            'expenses'=>$this->normalizeExpenses(is_array($data['expenses'] ?? null) ? $data['expenses'] : []),
        ];
    }

    private function normalizeEnergy(array $rows): array
    {
        $out=[]; foreach ($rows as $r) { if (!is_array($r) || (float)($r['quantity'] ?? 0)<=0) continue; $et=(string)($r['energy_type'] ?? 'electricity'); if (!in_array($et,['electricity','petrol','diesel','lpg','cng'],true)) continue; $out[]=[
            'occurred_at'=>$this->dateTime($r['occurred_at'] ?? null),'entry_type'=>(string)($r['entry_type'] ?? ($et==='electricity'?'charging':'fueling')),'energy_type'=>$et,'quantity'=>(float)$r['quantity'],
            'unit_price'=>$this->number($r['unit_price'] ?? null),'total_price'=>$this->number($r['total_price'] ?? null),'odometer_km'=>$this->number($r['odometer_km'] ?? null),'station'=>$this->nullable($r['station'] ?? null),'note'=>$this->nullable($r['note'] ?? null),
        ]; } return $out;
    }
    private function normalizeService(array $rows): array
    {
        $out=[]; foreach ($rows as $r) { if (!is_array($r) || trim((string)($r['title'] ?? ''))==='') continue; $out[]=['serviced_at'=>$this->date($r['serviced_at'] ?? null) ?: date('Y-m-d'),'category'=>trim((string)($r['category'] ?? 'servis')) ?: 'servis','title'=>trim((string)$r['title']),'provider'=>$this->nullable($r['provider'] ?? null),'odometer_km'=>$this->number($r['odometer_km'] ?? null),'cost'=>$this->number($r['cost'] ?? null),'note'=>$this->nullable($r['note'] ?? null)]; } return $out;
    }
    private function normalizeExpenses(array $rows): array
    {
        $out=[]; foreach ($rows as $r) { if (!is_array($r) || trim((string)($r['title'] ?? ''))==='' || !is_numeric($r['amount'] ?? null)) continue; $out[]=['occurred_at'=>$this->date($r['occurred_at'] ?? null) ?: date('Y-m-d'),'category'=>trim((string)($r['category'] ?? 'ostatni')) ?: 'ostatni','title'=>trim((string)$r['title']),'amount'=>(float)$r['amount'],'odometer_km'=>$this->number($r['odometer_km'] ?? null),'note'=>$this->nullable($r['note'] ?? null)]; } return $out;
    }
    private function nullable($v): ?string { $s=trim((string)$v); return $s==='' ? null : $s; }
    private function number($v): ?float { return $v===null || $v==='' || !is_numeric($v) ? null : (float)$v; }
    private function date($v): ?string { $s=trim((string)$v); if ($s==='') return null; $t=strtotime($s); return $t===false ? null : date('Y-m-d',$t); }
    private function dateTime($v): string { $s=trim((string)$v); $t=$s==='' ? false : strtotime($s); return $t===false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s',$t); }
}

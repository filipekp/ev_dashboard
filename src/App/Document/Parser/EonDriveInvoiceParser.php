<?php

declare(strict_types=1);

namespace App\Document\Parser;

use App\Document\Pdf\PdfTextExtractor;
use RuntimeException;

/**
 * Lokální parser měsíčních faktur E.ON Drive.
 *
 * Detailní relace čte z části "Přehled jednotlivých dobíjení". Hrubou cenu
 * faktury rozděluje mezi jednotlivé relace poměrem jejich cen bez DPH.
 */
final class EonDriveInvoiceParser implements DocumentParserInterface
{
    /** @var PdfTextExtractor */
    private $pdfTextExtractor;

    /** @var array<string,string> */
    private $textCache = [];

    public function __construct(?PdfTextExtractor $pdfTextExtractor = null)
    {
        $this->pdfTextExtractor = $pdfTextExtractor ?? new PdfTextExtractor();
    }

    public function name(): string
    {
        return 'eon_drive_invoice';
    }

    public function supports(string $path, string $mimeType, string $originalName): bool
    {
        if ($mimeType !== 'application/pdf') {
            return false;
        }

        try {
            $text = $this->text($path);
        } catch (RuntimeException $e) {
            return false;
        }

        return strpos($text, 'E.ON Drive') !== false
            && strpos($text, 'E.ON Energie, a.s.') !== false;
    }

    /**
     * @return array<string,mixed>
     */
    public function parse(string $path, string $mimeType, string $originalName): array
    {
        $text = $this->text($path);
        if (strpos($text, 'E.ON Drive') === false) {
            throw new RuntimeException('Dokument nevypadá jako faktura E.ON Drive.');
        }

        $flat = $this->flat($text);
        $invoiceNumber = $this->match('/\b(EFA[0-9A-Z]+)\b/u', $text);
        $documentDate = $this->extractInvoiceDate($flat);
        $grossTotal = $this->decimal($this->match('/Faktura\s+celkem\s+[0-9]+(?:,[0-9]+)?\s+([0-9]+(?:,[0-9]+)?)/ui', $flat));
        $rfid = $this->match('/RFID:\s*([0-9A-F]+)/ui', $flat);

        $sessions = $this->extractSessions($text, $invoiceNumber, $rfid, $grossTotal);
        if ($sessions === []) {
            throw new RuntimeException('Ve faktuře E.ON Drive nebyla nalezena žádná detailní nabíjecí relace.');
        }

        return [
            'document_type' => 'charging_invoice',
            'provider' => 'E.ON Drive',
            'document_date' => $documentDate,
            'currency' => 'CZK',
            'confidence' => 1.0,
            'energy_entries' => $sessions,
            'service_records' => [],
            'expenses' => [],
            'metadata' => [
                'invoice_number' => $invoiceNumber,
                'rfid' => $rfid,
                'source' => 'local_parser',
                'parser' => $this->name(),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractSessions(string $text, ?string $invoiceNumber, ?string $rfid, ?float $grossTotal): array
    {
        $clean = preg_replace('/[\x00-\x1F]+/', "\n", $text) ?? $text;
        $clean = preg_replace('/\n{2,}/', "\n", $clean) ?? $clean;

        $pattern = '/(\d{2}\.\d{2}\.\d{4})\s*\n(\d{2}:\d{2}:\d{2})\s*\n(\d{2}\.\d{2}\.\d{4})\s*\n(\d{2}:\d{2}:\d{2})\s*\n([A-Z]{2}\*[A-Z0-9]+\*[A-Z0-9]+)\s*\n([0-9]+,[0-9]+)\s*\n([0-9]+,[0-9]+)/u';
        if (!preg_match_all($pattern, $clean, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $netTotal = 0.0;
        foreach ($matches as $match) {
            $netTotal += $this->decimal($match[7]) ?? 0.0;
        }

        $sessions = [];
        $allocated = 0.0;
        $lastIndex = count($matches) - 1;
        foreach ($matches as $index => $match) {
            $quantity = $this->decimal($match[6]);
            $netPrice = $this->decimal($match[7]);
            $startedAt = $this->czechDateTime($match[1] . ' ' . $match[2]);
            $endedAt = $this->czechDateTime($match[3] . ' ' . $match[4]);
            if ($quantity === null || $quantity <= 0 || $netPrice === null || $startedAt === null) {
                continue;
            }

            $totalPrice = $netPrice;
            if ($grossTotal !== null && $grossTotal > 0 && $netTotal > 0) {
                if ($index === $lastIndex) {
                    $totalPrice = round($grossTotal - $allocated, 2);
                } else {
                    $totalPrice = round($grossTotal * ($netPrice / $netTotal), 2);
                    $allocated += $totalPrice;
                }
            }

            $notes = ['EVSE ID: ' . $match[5]];
            if ($rfid !== null) {
                $notes[] = 'RFID: ' . $rfid;
            }
            if ($endedAt !== null) {
                $notes[] = 'Konec nabíjení: ' . $endedAt;
            }
            $notes[] = 'Cena bez DPH: ' . number_format($netPrice, 2, ',', ' ') . ' CZK';
            if ($invoiceNumber !== null) {
                $notes[] = 'Faktura: ' . $invoiceNumber;
            }

            $sessions[] = [
                'occurred_at' => $startedAt,
                'entry_type' => 'charging',
                'energy_type' => 'electricity',
                'quantity' => $quantity,
                'unit_price' => round($totalPrice / $quantity, 4),
                'total_price' => $totalPrice,
                'odometer_km' => null,
                'station' => $match[5],
                'note' => implode('; ', $notes),
            ];
        }

        return $sessions;
    }

    private function extractInvoiceDate(string $flat): ?string
    {
        if (preg_match('/\b(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\s+Datum\s+vystaven/ui', $flat, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return null;
    }

    private function text(string $path): string
    {
        if (!isset($this->textCache[$path])) {
            $text = $this->pdfTextExtractor->extract($path);
            $text = str_replace("\xC2\xA0", ' ', $text);
            $this->textCache[$path] = trim($text);
        }
        return $this->textCache[$path];
    }

    private function flat(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F]+/', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function match(string $pattern, string $subject): ?string
    {
        if (!preg_match($pattern, $subject, $match)) {
            return null;
        }
        $value = trim((string)($match[1] ?? ''));
        return $value === '' ? null : $value;
    }

    private function decimal(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        return is_numeric($value) ? (float)$value : null;
    }

    private function czechDateTime(string $value): ?string
    {
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $value, $m)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[3], (int)$m[2], (int)$m[1], (int)$m[4], (int)$m[5], (int)$m[6]);
    }
}

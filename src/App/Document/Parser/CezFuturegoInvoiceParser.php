<?php

declare(strict_types=1);

namespace App\Document\Parser;

use App\Document\Pdf\PdfTextExtractor;
use RuntimeException;

/**
 * Lokální parser daňových dokladů ČEZ Futurego.
 *
 * Veškerá provider-specific logika je izolovaná v této třídě. Parser nepoužívá
 * AI ani externí API a výstup převádí do společného dokumentového DTO.
 */
final class CezFuturegoInvoiceParser implements DocumentParserInterface
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
        return 'cez_futurego_invoice';
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

        return strpos($text, 'CZ45274649') !== false
            && strpos($text, 'Dobíjecí relace') !== false
            && strpos($text, 'Tarif:') !== false;
    }

    /**
     * @return array<string,mixed>
     */
    public function parse(string $path, string $mimeType, string $originalName): array
    {
        $text = $this->text($path);
        if (strpos($text, 'CZ45274649') === false || strpos($text, 'Dobíjecí relace') === false) {
            throw new RuntimeException('Dokument nevypadá jako faktura ČEZ Futurego.');
        }

        $invoiceNumber = $this->match('/Číslo\s+faktury:\s*([0-9]+)/ui', $text);
        $documentDate = $this->czechDate($this->match('/Datum\s+vystavení:\s*(\d{2}\.\d{2}\.\d{4})/ui', $text));
        $tariff = $this->match('/Tarif:\s*([^\n]+)/ui', $text);
        $startedAt = $this->czechDateTime($this->match('/Začátek\s+(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2})/ui', $text));
        $endedAt = $this->czechDateTime($this->match('/Konec\s+(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2})/ui', $text));
        $quantity = $this->decimal($this->match('/(?:Energie|nergie):\s*([0-9]+(?:,[0-9]+)?)\s*kWh/ui', $text));
        $totalPrice = $this->decimal($this->match('/Celkový\s+součet\s*([0-9]+(?:,[0-9]+)?)\s*Kč/ui', $this->flat($text)));

        if ($startedAt === null || $quantity === null || $quantity <= 0 || $totalPrice === null) {
            throw new RuntimeException('Z faktury ČEZ Futurego se nepodařilo načíst povinné údaje nabíjecí relace.');
        }

        $stationCode = $this->match('/\n(R[0-9A-Za-z_-]+)\n(?:Type|CCS|CHAdeMO)/u', $text);
        $connector = $this->match('/\n(?:R[0-9A-Za-z_-]+)\n([^\n]+)\n/u', $text);
        $location = $this->match('/Lokalita\s+([^\n]+)/ui', $text);
        $address = $this->match('/(?:Type\s*2|CCS|CHAdeMO)\s*\n([^\n]+)\n([^\n]+)\nLokalita/ui', $text);
        $station = $this->buildStation($stationCode, $location, $address);

        $notes = [];
        if ($tariff !== null) {
            $notes[] = 'Tarif: ' . $tariff;
        }
        if ($stationCode !== null) {
            $notes[] = 'Stanice: ' . $stationCode;
        }
        if ($connector !== null) {
            $notes[] = 'Konektor: ' . trim($connector);
        }
        if ($endedAt !== null) {
            $notes[] = 'Konec nabíjení: ' . $endedAt;
        }
        if ($invoiceNumber !== null) {
            $notes[] = 'Faktura: ' . $invoiceNumber;
        }

        return [
            'document_type' => 'charging_invoice',
            'provider' => 'ČEZ Futurego',
            'document_date' => $documentDate,
            'currency' => 'CZK',
            'confidence' => 1.0,
            'energy_entries' => [[
                'occurred_at' => $startedAt,
                'entry_type' => 'charging',
                'energy_type' => 'electricity',
                'quantity' => $quantity,
                'unit_price' => round($totalPrice / $quantity, 4),
                'total_price' => $totalPrice,
                'odometer_km' => null,
                'station' => $station,
                'note' => $notes !== [] ? implode('; ', $notes) : null,
            ]],
            'service_records' => [],
            'expenses' => [],
            'metadata' => [
                'invoice_number' => $invoiceNumber,
                'source' => 'local_parser',
                'parser' => $this->name(),
            ],
        ];
    }

    private function text(string $path): string
    {
        if (!isset($this->textCache[$path])) {
            $text = $this->pdfTextExtractor->extract($path);
            $text = str_replace("\xC2\xA0", ' ', $text);
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
            $this->textCache[$path] = trim($text);
        }

        return $this->textCache[$path];
    }

    private function flat(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function buildStation(?string $code, ?string $location, ?string $address): ?string
    {
        $parts = [];
        foreach ([$code, $location, $address] as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        return $parts === [] ? null : implode(' – ', $parts);
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
        $value = str_replace([' ', ','], ['', '.'], $value);
        return is_numeric($value) ? (float)$value : null;
    }

    private function czechDate(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    private function czechDateTime(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})$/', $value, $m)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:00', (int)$m[3], (int)$m[2], (int)$m[1], (int)$m[4], (int)$m[5]);
    }
}

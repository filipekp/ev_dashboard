<?php

declare(strict_types=1);

namespace App\Document\Parser;

use App\Document\Pdf\PdfTextExtractor;
use RuntimeException;

/**
 * Lokální parser nabíjecích faktur Powerpass / Elli.
 *
 * Parser nepoužívá AI ani externí API. Fakturu převádí na jednotný dokumentový
 * formát používaný DocumentImportService a podporuje i více nabíjecích relací.
 */
final class PowerpassElliInvoiceParser implements DocumentParserInterface
{
    /** @var PdfTextExtractor */
    private $pdfTextExtractor;

    public function __construct(?PdfTextExtractor $pdfTextExtractor = null)
    {
        $this->pdfTextExtractor = $pdfTextExtractor ?? new PdfTextExtractor();
    }

    public function name(): string
    {
        return 'powerpass_elli_invoice';
    }

    public function supports(string $path, string $mimeType, string $originalName): bool
    {
        if ($mimeType !== 'application/pdf') {
            return false;
        }

        $header = file_get_contents($path, false, null, 0, 262144);
        if ($header === false) {
            return false;
        }

        return stripos($header, 'elli.eco') !== false
            || stripos($header, 'skoda-auto.support@elli.eco') !== false
            || stripos($header, 'Volkswagen Group Charging') !== false;
    }

    /**
     * @return array<string,mixed>
     */
    public function parse(string $path, string $mimeType, string $originalName): array
    {
        $text = $this->pdfTextExtractor->extract($path);
        $flat = $this->flatten($text);

        if (stripos($flat, 'Volkswagen Group Charging') === false && stripos($flat, 'elli.eco') === false) {
            throw new RuntimeException('Dokument nevypadá jako faktura Powerpass / Elli.');
        }

        $invoiceNumber = $this->match('/Číslo\s+Faktury\s+([0-9]+)/ui', $flat);
        $documentDate = $this->parseCzechDate($this->match('/Datum\s+dokladu:\s*(\d{2}\.\d{2}\.\d{4})/ui', $flat));
        $currency = $this->match('/Cena\s+bez\s+DPH\s+([A-Z]{3})/ui', $flat) ?: 'CZK';

        $sessions = $this->extractSessions($flat, $invoiceNumber);
        if ($sessions === []) {
            throw new RuntimeException('Ve faktuře Powerpass / Elli nebyla nalezena žádná nabíjecí relace.');
        }

        return [
            'document_type' => 'charging_invoice',
            'provider' => 'Powerpass / Elli',
            'document_date' => $documentDate,
            'currency' => $currency,
            'confidence' => 1.0,
            'energy_entries' => $sessions,
            'service_records' => [],
            'expenses' => [],
            'metadata' => [
                'invoice_number' => $invoiceNumber,
                'source' => 'local_parser',
                'parser' => $this->name(),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractSessions(string $text, ?string $invoiceNumber): array
    {
        $blocks = preg_split('/(?=Tarif:\s*)/ui', $text) ?: [];
        $sessions = [];

        foreach ($blocks as $block) {
            if (stripos($block, 'ID nabíjení:') === false || stripos($block, 'Začátek nabíjení:') === false) {
                continue;
            }

            $tariff = $this->match('/Tarif:\s*(.+?)\s+ID\s+nabíjení:/ui', $block);
            $chargingId = $this->match('/ID\s+nabíjení:\s*(.+?)\s+Nabíjecí\s+stanice:/ui', $block);
            if ($chargingId !== null) {
                $chargingId = preg_replace('/\s+/u', '', $chargingId) ?? $chargingId;
            }

            $station = $this->match('/Nabíjecí\s+stanice:\s*(.+?)\s+EVSE\s+ID:/ui', $block);
            $evseId = $this->match('/EVSE\s+ID:\s*([^\s]+)\s+Začátek\s+nabíjení:/ui', $block);
            $startedAt = $this->match('/Začátek\s+nabíjení:\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/ui', $block);
            $endedAt = $this->match('/Konec\s+nabíjení:\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/ui', $block);

            $quantity = $this->decimal($this->match('/([0-9]+(?:[.,][0-9]+)?)\s*kWh\s*x\s*([0-9]+(?:[.,][0-9]+)?)\s*[A-Z]{3}\s*\/\s*kWh/ui', $block));
            $unitPrice = null;
            if (preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*kWh\s*x\s*([0-9]+(?:[.,][0-9]+)?)\s*[A-Z]{3}\s*\/\s*kWh/ui', $block, $priceMatch)) {
                $quantity = $this->decimal($priceMatch[1]);
                $unitPrice = $this->decimal($priceMatch[2]);
            }

            $chargingPrice = $this->decimal($this->match('/Cena\s+nabíjení\s+a\s+spotřeba\s*\(brutto\):\s*([0-9.,]+)\s*[A-Z]{3}/ui', $block));
            $timeFee = $this->decimal($this->match('/Časové\s+poplatky\s*\(brutto\):\s*([0-9.,]+)\s*[A-Z]{3}/ui', $block));
            $sessionFee = $this->decimal($this->match('/Poplatek\s+za\s+relaci\s*\(brutto\):\s*([0-9.,]+)\s*[A-Z]{3}/ui', $block));

            if ($startedAt === null || $quantity === null || $quantity <= 0) {
                continue;
            }

            $totalPrice = ($chargingPrice ?? 0.0) + ($timeFee ?? 0.0) + ($sessionFee ?? 0.0);
            if ($totalPrice <= 0 && $unitPrice !== null) {
                $totalPrice = round($quantity * $unitPrice, 2);
            }

            $notes = [];
            if ($tariff !== null) {
                $notes[] = 'Tarif: ' . $this->cleanValue($tariff);
            }
            if ($chargingId !== null) {
                $notes[] = 'ID nabíjení: ' . $chargingId;
            }
            if ($evseId !== null) {
                $notes[] = 'EVSE ID: ' . $evseId;
            }
            if ($endedAt !== null) {
                $notes[] = 'Konec nabíjení: ' . $endedAt;
            }
            if ($timeFee !== null && $timeFee > 0) {
                $notes[] = 'Časové poplatky: ' . $this->formatMoney($timeFee) . ' CZK';
            }
            if ($sessionFee !== null && $sessionFee > 0) {
                $notes[] = 'Poplatek za relaci: ' . $this->formatMoney($sessionFee) . ' CZK';
            }
            if ($invoiceNumber !== null) {
                $notes[] = 'Faktura: ' . $invoiceNumber;
            }

            $sessions[] = [
                'occurred_at' => $startedAt,
                'entry_type' => 'charging',
                'energy_type' => 'electricity',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice > 0 ? $totalPrice : null,
                'odometer_km' => null,
                'station' => $station !== null ? $this->cleanValue($station) : null,
                'note' => $notes !== [] ? implode('; ', $notes) : null,
            ];
        }

        return $sessions;
    }

    private function flatten(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function cleanValue(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function parseCzechDate(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $match)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int)$match[3], (int)$match[2], (int)$match[1]);
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}

<?php

declare(strict_types=1);

namespace App\Integration;

use App\Import\XlsxArchive;
use RuntimeException;

/**
 * Bezpečná read-only čtečka tabulkových souborů pro univerzální import.
 * Podporuje CSV a XLSX bez závislosti na PhpSpreadsheet.
 */
final class TabularFileReader
{
    /** @return array{headers:array<int,string>,rows:array<int,array<string,string>>,format:string} */
    public function read(string $path, string $originalName, int $limit = 0): array
    {
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'xlsx' || $this->isZip($path)) {
            return $this->readXlsx($path, $limit);
        }
        if (in_array($extension, ['csv', 'txt', 'xls'], true)) {
            return $this->readCsv($path, $limit);
        }

        throw new RuntimeException('Univerzální mapování podporuje CSV a XLSX soubory.');
    }

    private function isZip(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!$h) return false;
        $magic = fread($h, 4);
        fclose($h);
        return $magic === "PK\x03\x04";
    }

    /** @return array{headers:array<int,string>,rows:array<int,array<string,string>>,format:string} */
    private function readCsv(string $path, int $limit): array
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('Soubor nelze otevřít.');
        try {
            $sample = (string)fgets($handle);
            rewind($handle);
            $delimiter = $this->detectDelimiter($sample);
            $headerRow = fgetcsv($handle, 0, $delimiter);
            if (!is_array($headerRow)) throw new RuntimeException('Soubor neobsahuje hlavičku tabulky.');
            $headers = $this->normalizeHeaders($headerRow);
            $rows = [];
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($this->emptyRow($values)) continue;
                $rows[] = $this->combine($headers, $values);
                if ($limit > 0 && count($rows) >= $limit) break;
            }
            return ['headers' => $headers, 'rows' => $rows, 'format' => 'csv'];
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $scores = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), '|' => substr_count($line, '|')];
        arsort($scores);
        $delimiter = (string)key($scores);
        return reset($scores) > 0 ? $delimiter : ',';
    }

    /** @return array{headers:array<int,string>,rows:array<int,array<string,string>>,format:string} */
    private function readXlsx(string $path, int $limit): array
    {
        $archive = new XlsxArchive($path);
        $shared = $this->sharedStrings($archive);
        $sheet = $archive->get('xl/worksheets/sheet1.xml');
        if ($sheet === null) throw new RuntimeException('XLSX neobsahuje první list.');
        if (!preg_match_all('/<(?:[A-Za-z0-9_]+:)?row\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?row>/si', $sheet, $matches)) {
            throw new RuntimeException('XLSX neobsahuje čitelné řádky.');
        }
        $matrix = [];
        foreach ($matches[1] as $rowXml) {
            $row = [];
            if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?c\b([^>]*)>(.*?)<\/(?:[A-Za-z0-9_]+:)?c>/si', (string)$rowXml, $cells, PREG_SET_ORDER)) {
                foreach ($cells as $cell) {
                    if (!preg_match('/\br\s*=\s*["\']([A-Z]+)\d+["\']/i', (string)$cell[1], $ref)) continue;
                    $col = strtoupper((string)$ref[1]);
                    $type = preg_match('/\bt\s*=\s*["\']([^"\']+)["\']/', (string)$cell[1], $tm) ? (string)$tm[1] : '';
                    $value = '';
                    if ($type === 'inlineStr' && preg_match_all('/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/si', (string)$cell[2], $texts)) {
                        foreach ($texts[1] as $text) $value .= html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } elseif (preg_match('/<(?:[A-Za-z0-9_]+:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/si', (string)$cell[2], $vm)) {
                        $raw = html_entity_decode(strip_tags((string)$vm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $value = $type === 's' ? ($shared[(int)$raw] ?? '') : $raw;
                    }
                    $row[$this->columnIndex($col)] = trim($value);
                }
            }
            if ($row) {
                ksort($row);
                $matrix[] = $row;
            }
        }
        if (!$matrix) throw new RuntimeException('XLSX je prázdný.');
        $max = 0;
        foreach ($matrix as $row) if ($row) $max = max($max, max(array_keys($row)));
        $headerValues = [];
        for ($i=0; $i<=$max; $i++) $headerValues[] = $matrix[0][$i] ?? '';
        $headers = $this->normalizeHeaders($headerValues);
        $rows = [];
        for ($r=1; $r<count($matrix); $r++) {
            $values = [];
            for ($i=0; $i<count($headers); $i++) $values[] = $matrix[$r][$i] ?? '';
            if ($this->emptyRow($values)) continue;
            $rows[] = $this->combine($headers, $values);
            if ($limit > 0 && count($rows) >= $limit) break;
        }
        return ['headers'=>$headers,'rows'=>$rows,'format'=>'xlsx'];
    }

    /** @return string[] */
    private function sharedStrings(XlsxArchive $archive): array
    {
        $xml = $archive->get('xl/sharedStrings.xml');
        if ($xml === null) return [];
        if (!preg_match_all('/<(?:[A-Za-z0-9_]+:)?si\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?si>/si', $xml, $matches)) return [];
        $out=[];
        foreach ($matches[1] as $item) {
            $value='';
            if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/si', (string)$item, $texts)) {
                foreach ($texts[1] as $text) $value .= html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $out[]=$value;
        }
        return $out;
    }

    private function columnIndex(string $letters): int
    {
        $n=0;
        for ($i=0; $i<strlen($letters); $i++) $n=$n*26+(ord($letters[$i])-64);
        return $n-1;
    }

    /** @param array<int,mixed> $headers @return string[] */
    private function normalizeHeaders(array $headers): array
    {
        $out=[]; $seen=[];
        foreach ($headers as $i=>$header) {
            $name=trim((string)$header);
            if ($name==='') $name='Sloupec '.($i+1);
            $base=$name; $n=2;
            while (isset($seen[$name])) $name=$base.' ('.$n++.')';
            $seen[$name]=true; $out[]=$name;
        }
        return $out;
    }

    /** @param array<int,mixed> $values */
    private function emptyRow(array $values): bool
    {
        foreach ($values as $v) if (trim((string)$v)!=='') return false;
        return true;
    }

    /** @param string[] $headers @param array<int,mixed> $values @return array<string,string> */
    private function combine(array $headers, array $values): array
    {
        $row=[];
        foreach ($headers as $i=>$header) $row[$header]=isset($values[$i]) ? trim((string)$values[$i]) : '';
        return $row;
    }
}

<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;
use ZipArchive;

/**
 * Malá čtečka ZIP kontejneru používaného formátem XLSX.
 *
 * Preferuje nativní ZipArchive. Pro sdílené hostingy, kde rozšíření ext-zip
 * není dostupné, obsahuje omezený read-only fallback pro běžné XLSX soubory
 * (metody STORE a DEFLATE). Díky tomu import Kia není závislý na ext-zip.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class XlsxArchive
{
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function get(string $name): ?string
    {
        if (class_exists(ZipArchive::class)) {
            return $this->getWithZipArchive($name);
        }

        return $this->getWithPhpFallback($name);
    }

    private function getWithZipArchive(string $name): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('Soubor nelze otevřít jako XLSX/ZIP.');
        }

        try {
            $content = $zip->getFromName($name);

            return $content === false ? null : $content;
        } finally {
            $zip->close();
        }
    }

    private function getWithPhpFallback(string $name): ?string
    {
        $handle = @fopen($this->path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Soubor XLSX nelze otevřít.');
        }

        try {
            $entry = $this->findEntry($handle, $name);
            if ($entry === null) {
                return null;
            }

            if (($entry['flags'] & 0x0001) !== 0) {
                throw new RuntimeException('Šifrované XLSX soubory nejsou podporovány.');
            }

            if (fseek($handle, $entry['local_offset']) !== 0) {
                throw new RuntimeException('Poškozený XLSX: nelze načíst lokální ZIP hlavičku.');
            }

            $header = fread($handle, 30);
            if (strlen($header) !== 30) {
                throw new RuntimeException('Poškozený XLSX: neúplná lokální ZIP hlavička.');
            }

            $local = unpack(
                'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length',
                $header
            );
            if (!$local || (int)$local['signature'] !== 0x04034b50) {
                throw new RuntimeException('Poškozený XLSX: neplatná lokální ZIP hlavička.');
            }

            $skip = (int)$local['name_length'] + (int)$local['extra_length'];
            if ($skip > 0 && fseek($handle, $skip, SEEK_CUR) !== 0) {
                throw new RuntimeException('Poškozený XLSX: nelze přeskočit ZIP metadata.');
            }

            $compressed = $this->readExact($handle, $entry['compressed_size']);
            if ($entry['method'] === 0) {
                return $compressed;
            }
            if ($entry['method'] === 8) {
                $content = @gzinflate($compressed);
                if ($content === false) {
                    throw new RuntimeException('XLSX položku se nepodařilo dekomprimovat.');
                }

                return $content;
            }

            throw new RuntimeException(
                'XLSX používá nepodporovanou kompresní metodu ' . $entry['method'] . '.'
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return array{flags:int,method:int,compressed_size:int,local_offset:int}|null
     */
    private function findEntry($handle, string $wantedName): ?array
    {
        $stat = fstat($handle);
        $size = (int)($stat['size'] ?? 0);
        if ($size < 22) {
            throw new RuntimeException('Soubor není platný XLSX/ZIP.');
        }

        $tailSize = min($size, 65557);
        if (fseek($handle, $size - $tailSize) !== 0) {
            throw new RuntimeException('XLSX nelze načíst.');
        }
        $tail = $this->readExact($handle, $tailSize);
        $eocdPosition = strrpos($tail, "PK\x05\x06");
        if ($eocdPosition === false || strlen($tail) < $eocdPosition + 22) {
            throw new RuntimeException('Soubor není platný XLSX/ZIP.');
        }

        $eocd = unpack(
            'vdisk/vdisk_start/ventries_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length',
            substr($tail, $eocdPosition + 4, 18)
        );
        if (!$eocd) {
            throw new RuntimeException('Poškozený XLSX: nelze načíst ZIP adresář.');
        }

        $centralOffset = (int)$eocd['central_offset'];
        $entries = (int)$eocd['entries'];
        if ($centralOffset === 0xffffffff || $entries === 0xffff) {
            throw new RuntimeException('ZIP64 XLSX není tímto fallbackem podporován.');
        }
        if (fseek($handle, $centralOffset) !== 0) {
            throw new RuntimeException('Poškozený XLSX: ZIP adresář je mimo soubor.');
        }

        for ($index = 0; $index < $entries; $index++) {
            $fixed = $this->readExact($handle, 46);
            $entry = unpack(
                'Vsignature/vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/' .
                'Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/' .
                'vcomment_length/vdisk_start/vinternal_attr/Vexternal_attr/Vlocal_offset',
                $fixed
            );
            if (!$entry || (int)$entry['signature'] !== 0x02014b50) {
                throw new RuntimeException('Poškozený XLSX: neplatná položka ZIP adresáře.');
            }

            $name = $this->readExact($handle, (int)$entry['name_length']);
            $skip = (int)$entry['extra_length'] + (int)$entry['comment_length'];
            if ($skip > 0 && fseek($handle, $skip, SEEK_CUR) !== 0) {
                throw new RuntimeException('Poškozený XLSX: nelze přeskočit ZIP metadata.');
            }

            if ($name === $wantedName) {
                return [
                    'flags' => (int)$entry['flags'],
                    'method' => (int)$entry['method'],
                    'compressed_size' => (int)$entry['compressed_size'],
                    'local_offset' => (int)$entry['local_offset'],
                ];
            }
        }

        return null;
    }

    /** @param resource $handle */
    private function readExact($handle, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        if (strlen($data) !== $length) {
            throw new RuntimeException('Poškozený XLSX: neočekávaný konec souboru.');
        }

        return $data;
    }
}

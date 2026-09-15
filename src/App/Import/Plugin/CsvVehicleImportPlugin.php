<?php

declare(strict_types=1);

namespace App\Import\Plugin;

use App\Csv\CsvPluginRegistry;
use App\Import\TripImportPluginInterface;
use RuntimeException;

/**
 * Adaptér stávajících CSV pluginů do obecného importního systému.
 *
 * Díky adaptéru zůstávají jednotlivé CSV formáty vozidel oddělené v
 * App\Csv\Plugin, zatímco ImportService může vedle CSV zpracovat i jiné
 * kontejnery, například XLSX export z Kia Connect.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class CsvVehicleImportPlugin implements TripImportPluginInterface
{
    /** @var CsvPluginRegistry */
    private $registry;

    public function __construct(CsvPluginRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function key(): string
    {
        return 'csv';
    }

    public function supports(string $path, ?string $originalName = null): bool
    {
        if ($this->isZipContainer($path)) {
            return false;
        }

        try {
            $header = $this->readHeader($path);
            $this->registry->detect($header);

            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function inspect(string $path, ?string $originalName = null): array
    {
        $header = $this->readHeader($path);
        $plugin = $this->registry->detect($header);
        $vin = $this->vinFromFilename($originalName);

        return array_merge(
            [
                'vin' => $vin,
                'format' => $plugin->id(),
                'plugin_label' => $plugin->label(),
            ],
            $plugin->inspect($vin)
        );
    }

    /** @return iterable<int,array<string,mixed>> */
    public function records(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV nelze otevřít.');
        }

        try {
            $header = fgetcsv($handle);
            if (!$header) {
                throw new RuntimeException('CSV nemá hlavičku.');
            }

            $header = $this->normalizeHeader($header);
            $plugin = $this->registry->detect($header);

            while (($values = fgetcsv($handle)) !== false) {
                if (!$values || count($values) < count($header)) {
                    continue;
                }

                $row = [];
                foreach ($header as $index => $column) {
                    $row[$column] = $values[$index] ?? null;
                }

                $trip = $plugin->parse($row);
                if ($trip !== null) {
                    yield $trip;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return string[] */
    private function readHeader(string $path): array
    {
        if ($this->isZipContainer($path)) {
            throw new RuntimeException('Soubor je XLSX/ZIP, nikoli CSV.');
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV nelze otevřít.');
        }

        try {
            $header = fgetcsv($handle);
            if (!$header) {
                throw new RuntimeException('CSV nemá hlavičku.');
            }

            return $this->normalizeHeader($header);
        } finally {
            fclose($handle);
        }
    }

    private function isZipContainer(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        try {
            return fread($handle, 4) === "PK\x03\x04";
        } finally {
            fclose($handle);
        }
    }

    private function vinFromFilename(?string $name): ?string
    {
        if (!$name || !preg_match('/([A-HJ-NPR-Z0-9]{17})/i', $name, $matches)) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    /** @param array<int,mixed> $header @return string[] */
    private function normalizeHeader(array $header): array
    {
        $header = array_map('strval', $header);
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        }

        return $header;
    }
}

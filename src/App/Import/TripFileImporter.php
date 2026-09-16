<?php

declare(strict_types=1);

namespace App\Import;

use App\Repository\TripRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Importér jízd nezávislý na konkrétním souborovém formátu.
 *
 * Formát souboru rozpoznávají importní pluginy. Samotný importér řeší pouze
 * společnou transakci a uložení normalizovaných jízd do databáze.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class TripFileImporter
{
    /** @var PDO */
    private $pdo;

    /** @var TripRepository */
    private $trips;

    /** @var TripImportPluginInterface[] */
    private $plugins;

    /** @param TripImportPluginInterface[] $plugins */
    public function __construct(PDO $pdo, TripRepository $trips, array $plugins)
    {
        $this->pdo = $pdo;
        $this->trips = $trips;
        $this->plugins = $plugins;
    }

    /** @return array<string,mixed> */
    public function inspect(string $path, ?string $originalName = null): array
    {
        $plugin = $this->detectPlugin($path, $originalName);
        $meta = $plugin->inspect($path, $originalName);
        $meta['import_plugin'] = $plugin->key();

        return $meta;
    }

    /** @return array<string,mixed> */
    public function import(int $vehicleId, string $path, ?string $originalName = null): array
    {
        $plugin = $this->detectPlugin($path, $originalName);
        $meta = $plugin->inspect($path, $originalName);
        $inserted = 0;
        $skipped = 0;
        $seen = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($plugin->records($path) as $trip) {
                $seen++;

                if ($this->trips->insertIgnore($vehicleId, $trip)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($seen === 0) {
            throw new RuntimeException('V souboru nebyly nalezeny žádné importovatelné jízdy.');
        }

        return [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'format' => (string)($meta['format'] ?? $plugin->key()),
            'plugin_label' => (string)($meta['plugin_label'] ?? $plugin->key()),
        ];
    }

    private function detectPlugin(string $path, ?string $originalName): TripImportPluginInterface
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->supports($path, $originalName)) {
                return $plugin;
            }
        }

        throw new UnsupportedImportFormatException('Pro tento soubor nebyl nalezen nativní importní plugin.');
    }
}

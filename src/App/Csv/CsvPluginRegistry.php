<?php

declare(strict_types=1);

namespace App\Csv;

use App\Csv\Plugin\CsvVehiclePluginInterface;
use RuntimeException;

/**
     * Selects CSV plugins for import and export operations.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
final class CsvPluginRegistry
{
    /** @var array<string,CsvVehiclePluginInterface> */
    private $plugins = [];
    /** @param CsvVehiclePluginInterface[] $plugins */
    public function __construct(array $plugins)
    {
        foreach ($plugins as $plugin) {
            $this->plugins[$plugin->id()] = $plugin;
        }
    }
    /** @return CsvVehiclePluginInterface[] */
    public function all(): array
    {
        return array_values($this->plugins);
    }
    /** @param string[] $header */
    public function detect(array $header): CsvVehiclePluginInterface
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->supports($header)) {
                return $plugin;
            }
        }
        throw new RuntimeException('Nepodporovaný formát CSV. Nainstalujte nebo doplňte plugin pro dané vozidlo/export.');
    }

    public function get(string $id): CsvVehiclePluginInterface
    {
        if (!isset($this->plugins[$id])) {
            throw new RuntimeException('CSV plugin "' . $id . '" není registrován.');
        }
        return $this->plugins[$id];
    }

    public function has(string $id): bool
    {
        returnisset($this->plugins[$id]);
    }
}

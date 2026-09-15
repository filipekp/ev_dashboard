<?php

declare(strict_types=1);

namespace App\Csv;

use App\Csv\Plugin\CsvVehiclePluginInterface;
use ReflectionClass;

/**
     * Discovers CSV vehicle plugins from the plugin directory.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
final class CsvPluginLoader
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }
    /** @return CsvVehiclePluginInterface[] */
    public function load(): array
    {
        $plugins = [];
        foreach (glob($this->directory . '/*Plugin.php')?: [] as $file) {
            $base = basename($file, '.php');
            if (in_array($base, ['AbstractCsvVehiclePlugin', 'CsvVehiclePluginInterface'], TRUE)) {
                continue;
            }
            $class = 'App\\Csv\\Plugin\\' . $base;
            if (!class_exists($class)) {
                require_once $file;
            }
            if (!class_exists($class) || !is_subclass_of($class, CsvVehiclePluginInterface::class)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }
            /** @var CsvVehiclePluginInterface $plugin */
            $plugin = $ref->newInstance();
            $plugins[] = $plugin;
        }
        usort($plugins, static function (CsvVehiclePluginInterface $a, CsvVehiclePluginInterface $b): int
        {
            return strcmp($a->id(), $b->id());
        }
        );
        return $plugins;
    }
}

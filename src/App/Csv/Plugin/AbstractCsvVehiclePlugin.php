<?php

declare(strict_types=1);

namespace App\Csv\Plugin;

abstract class AbstractCsvVehiclePlugin implements CsvVehiclePluginInterface
{
    /** @param string[] $header @param string[] $required */
    protected function hasColumns(array $header, array $required): bool
    {
        $map = array_flip($header);
        foreach ($required as $column) {
            if (!isset($map[$column])) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value */
    protected function numOrNull($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace(',', '.', trim((string)$value));
        return $value !== '' && is_numeric($value) ? (float)$value : null;
    }

    /** @param mixed $value */
    protected function valueOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /** @return array{0:?float,1:?float} */
    protected function parseCoord(?string $value): array
    {
        if (!$value || strpos($value, ',') === false) {
            return [null, null];
        }
        [$a, $b] = array_map('trim', explode(',', $value, 2));
        return [is_numeric($a) ? (float)$a : null, is_numeric($b) ? (float)$b : null];
    }

    /** @param mixed $value */
    protected function out($value): string
    {
        return $value === null ? '' : (string)$value;
    }

    /** @param mixed $lat @param mixed $lng */
    protected function coords($lat, $lng): string
    {
        return ($lat === null || $lng === null) ? '' : $lat . ', ' . $lng;
    }
}

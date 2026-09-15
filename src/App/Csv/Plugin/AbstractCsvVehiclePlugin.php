<?php
    
    declare(strict_types=1);
    
    namespace App\Csv\Plugin;
    
    /**
     * Třída AbstractCsvVehiclePlugin.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
    abstract class AbstractCsvVehiclePlugin implements CsvVehiclePluginInterface
    {
        /** @param string[] $header @param string[] $required */
        protected function hasColumns(array $header, array $required): bool {
            $map = array_flip($header);
            foreach ($required as $column) {
                if (!isset($map[$column])) {
                    return FALSE;
                }
            }
            
            return TRUE;
        }
        
        /** @param mixed $value */
        protected function numOrNull($value): ?float {
            if ($value === NULL) {
                return NULL;
            }
            $value = str_replace(',', '.', trim((string)$value));
            
            return $value !== '' && is_numeric($value) ? (float)$value : NULL;
        }
        
        /** @param mixed $value */
        protected function valueOrNull($value): ?string {
            if ($value === NULL) {
                return NULL;
            }
            $value = trim((string)$value);
            
            return $value === '' ? NULL : $value;
        }
        
        /** @return array{0:?float,1:?float} */
        protected function parseCoord(?string $value): array {
            if (!$value || strpos($value, ',') === FALSE) {
                return [
                    NULL,
                    NULL
                ];
            }
            [
                $a,
                $b
            ] = array_map('trim', explode(',', $value, 2));
            
            return [
                is_numeric($a) ? (float)$a : NULL,
                is_numeric($b) ? (float)$b : NULL
            ];
        }
        
        /** @param mixed $value */
        protected function out($value): string {
            return $value === NULL ? '' : (string)$value;
        }
        
        /** @param mixed $lat @param mixed $lng */
        protected function coords($lat, $lng): string {
            return ($lat === NULL || $lng === NULL) ? '' : $lat . ', ' . $lng;
        }
    }

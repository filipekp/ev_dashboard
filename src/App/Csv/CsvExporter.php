<?php
    
    declare(strict_types=1);
    
    namespace App\Csv;
    
    use App\Repository\TripRepository;
    use RuntimeException;
    
    /**
     * Třída CsvExporter.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
    final class CsvExporter
    {
        /** @var CsvPluginRegistry */
        private $registry;
        /** @var TripRepository */
        private $trips;
        
        public function __construct(CsvPluginRegistry $registry, TripRepository $trips) {
            $this->registry = $registry;
            $this->trips    = $trips;
        }
        
        /** @param array<string,mixed> $vehicle */
        public function stream(array $vehicle, string $period, string $year, ?string $accessFrom = null): void {
            [
                $period,
                $from,
                $to,
                $suffix
            ] = $this->period($period, $year);
            if ($accessFrom !== null && ($from === null || strtotime($from) < strtotime($accessFrom))) {
                $from = $accessFrom;
            }
            $vehicleId = (int)$vehicle['id'];
            $format    = $this->trips->dominantSourceFormat($vehicleId, $from, $to);
            if (!$this->registry->has($format)) {
                throw new RuntimeException('Pro zdrojový formát "' . $format . '" není dostupný exportní plugin.');
            }
            $plugin   = $this->registry->get($format);
            $safeVin  = preg_replace('/[^A-Z0-9_-]/i', '', (string)($vehicle['vin'] ?? ''));
            $filename = 'tripStatistics_' . ($safeVin ?: 'vehicle') . $suffix . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            $out = fopen('php://output', 'wb');
            if (!$out) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $plugin->exportHeaders());
            foreach ($this->trips->findForExport($vehicleId, $from, $to) as $trip) {
                fputcsv($out, array_map([$this, 'safeCell'], $plugin->exportRow($trip)));
            }
            fclose($out);
        }
        
        /** @param mixed $value @return mixed */
        private function safeCell($value)
        {
            if (!is_string($value)) {
                return $value;
            }

            // Ochrana proti CSV/Excel formula injection u hodnot pocházejících z uživatelských dat.
            if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
                return "'" . $value;
            }

            return $value;
        }

        /** @return array{0:string,1:?string,2:?string,3:string} */
        private function period(string $period, string $year): array {
            if (preg_match('/^\d{4}-\d{2}$/', $period)) {
                $from = $period . '-01 00:00:00';
                $d    = new \DateTime($from);
                $d->modify('+1 month');
                
                return [
                    $period,
                    $from,
                    $d->format('Y-m-d H:i:s'),
                    '_' . $period
                ];
            }
            if ($period === 'year' && preg_match('/^\d{4}$/', $year)) {
                return [
                    $period,
                    $year . '-01-01 00:00:00',
                    ((int)$year + 1) . '-01-01 00:00:00',
                    '_' . $year
                ];
            }
            
            return [
                'all',
                NULL,
                NULL,
                ''
            ];
        }
    }

<?php
    
    declare(strict_types=1);
    
    namespace App\Csv;
    
    use App\Repository\TripRepository;
    use RuntimeException;
    use Throwable;
    use PDO;
    
    /**
     * Třída CsvImporter.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
    final class CsvImporter
    {
        /** @var PDO */
        private $pdo;
        /** @var CsvPluginRegistry */
        private $registry;
        /** @var TripRepository */
        private $trips;
        
        public function __construct(PDO $pdo, CsvPluginRegistry $registry, TripRepository $trips) {
            $this->pdo      = $pdo;
            $this->registry = $registry;
            $this->trips    = $trips;
        }
        
        public function vinFromFilename(?string $name): ?string {
            if (!$name || !preg_match('/([A-HJ-NPR-Z0-9]{17})/i', $name, $m)) {
                return NULL;
            }
            
            return strtoupper($m[1]);
        }
        
        /** @return array<string,mixed> */
        public function inspect(string $path, ?string $name = NULL): array {
            $header = $this->readHeader($path);
            $plugin = $this->registry->detect($header);
            $vin    = $this->vinFromFilename($name);
            
            return array_merge(['vin'          => $vin,
                                'format'       => $plugin->id(),
                                'plugin_label' => $plugin->label()
            ], $plugin->inspect($vin));
        }
        
        /** @return array<string,mixed> */
        public function import(int $vehicleId, string $path, ?string $name = NULL): array {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                throw new RuntimeException('CSV nelze otevřít.');
            }
            $header = fgetcsv($fh);
            if (!$header) {
                fclose($fh);
                throw new RuntimeException('CSV nemá hlavičku.');
            }
            $header   = $this->normalizeHeader($header);
            $plugin   = $this->registry->detect($header);
            $inserted = 0;
            $skipped  = 0;
            $this->pdo->beginTransaction();
            try {
                while (($values = fgetcsv($fh)) !== FALSE) {
                    if (!$values || count($values) < count($header)) {
                        $skipped++;
                        continue;
                    }
                    $row = [];
                    foreach ($header as $i => $column) {
                        $row[$column] = $values[$i] ?? NULL;
                    }
                    $trip = $plugin->parse($row);
                    if ($trip === NULL) {
                        $skipped++;
                        continue;
                    }
                    if ($this->trips->insertIgnore($vehicleId, $trip)) {
                        $inserted++;
                    }
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            } finally {
                fclose($fh);
            }
            
            return ['inserted'     => $inserted,
                    'skipped'      => $skipped,
                    'format'       => $plugin->id(),
                    'plugin_label' => $plugin->label()
            ];
        }
        
        /** @return string[] */
        private function readHeader(string $path): array {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                throw new RuntimeException('CSV nelze otevřít.');
            }
            try {
                $h = fgetcsv($fh);
                if (!$h) {
                    throw new RuntimeException('CSV nemá hlavičku.');
                }
                
                return $this->normalizeHeader($h);
            } finally {
                fclose($fh);
            }
        }
        
        /** @param array<int,mixed> $header @return string[] */
        private function normalizeHeader(array $header): array {
            $header = array_map('strval', $header);
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            }
            
            return $header;
        }
    }

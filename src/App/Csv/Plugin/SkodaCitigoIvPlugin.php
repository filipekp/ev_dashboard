<?php
    
    declare(strict_types=1);
    
    namespace App\Csv\Plugin;
    
    use App\Vehicle\VinDecoder;
    use DateTime;
    
    /**
     * Třída SkodaCitigoIvPlugin.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
    final class SkodaCitigoIvPlugin extends AbstractCsvVehiclePlugin
    {
        public function id(): string {
            return 'citigo_iv';
        }
        
        public function label(): string {
            return 'Škoda Citigo iV / Volkswagen e-up!';
        }
        
        public function supports(array $header): bool {
            return $this->hasColumns($header, [
                'End of trip',
                'Start mileage in km',
                'End mileage in km',
                'Mileage in km',
                'Travel time in minutes',
                'Average electric consumption in kWh/100km'
            ]);
        }
        
        public function inspect(?string $vin): array {
            $manufacturer = VinDecoder::manufacturer($vin);
            $suggestedName = 'Škoda Citigo iV';

            if ($manufacturer === 'VOLKSWAGEN') {
                $suggestedName = 'Volkswagen e-up!';
            } elseif ($manufacturer === null) {
                // Starší Citigo exporty bez rozpoznatelného VIN zůstávají
                // kvůli zpětné kompatibilitě vedené jako Škoda.
                $manufacturer = 'SKODA';
            }

            return [
                'manufacturer'        => $manufacturer,
                'suggested_name'      => $suggestedName,
                'battery_kwh'         => 32.3,
                'battery_nominal_kwh' => 32.3
            ];
        }
        
        public function parse(array $row): ?array {
            $end = DateTime::createFromFormat('d.m.Y H:i', trim((string)($row['End of trip'] ?? '')));
            if (!$end) {
                return NULL;
            }
            $travelMinutes = max(0, (int)($row['Travel time in minutes'] ?? 0));
            $start         = clone $end;
            if ($travelMinutes > 0) {
                $start->modify('-' . $travelMinutes . ' minutes');
            }
            $distance = $this->numOrNull($row['Mileage in km'] ?? NULL);
            if ($distance === NULL) {
                return NULL;
            }
            $avgConsumption = $this->numOrNull($row['Average electric consumption in kWh/100km'] ?? NULL);
            $consumed       = ($avgConsumption !== NULL && $distance > 0) ? max(0.0, $distance * $avgConsumption / 100) : 0.0;
            $startOdo       = $this->numOrNull($row['Start mileage in km'] ?? NULL);
            $endOdo         = $this->numOrNull($row['End mileage in km'] ?? NULL);
            $hash           = hash('sha256', implode('|', [
                $this->id(),
                $end->format('c'),
                (string)$startOdo,
                (string)$endOdo,
                (string)$distance,
                (string)$travelMinutes
            ]));
            
            return [
                'trip_hash'                   => $hash,
                'started_at'                  => $start->format('Y-m-d H:i:s'),
                'ended_at'                    => $end->format('Y-m-d H:i:s'),
                'classification'              => NULL,
                'start_address'               => '',
                'end_address'                 => '',
                'start_lat'                   => NULL,
                'start_lng'                   => NULL,
                'end_lat'                     => NULL,
                'end_lng'                     => NULL,
                'distance_km'                 => $distance,
                'start_odometer_km'           => $startOdo,
                'end_odometer_km'             => $endOdo,
                'driving_minutes'             => $travelMinutes,
                'travel_minutes'              => $travelMinutes,
                'avg_speed_kmh'               => $this->numOrNull($row['Average speed in km/h'] ?? NULL),
                'consumed_kwh'                => $consumed,
                'avg_consumption_kwh_100'     => $avgConsumption,
                'start_soc'                   => NULL,
                'end_soc'                     => NULL,
                'public_charging_stops'       => 0,
                'public_charge_soc_gained'    => 0,
                'short_trip'                  => ($distance <= 5 ? 1 : 0),
                'source_format'               => $this->id(),
                'total_cost'                  => $this->numOrNull($row['Total cost'] ?? NULL),
                'total_cost_currency'         => $this->valueOrNull($row['Total cost currency'] ?? NULL),
                'electricity_cost'            => $this->numOrNull($row['Electricity cost'] ?? NULL),
                'electricity_cost_currency'   => $this->valueOrNull($row['Electricity cost currency'] ?? NULL),
                'electricity_price_per_kwh'   => $this->numOrNull($row['Electricity price per kWh'] ?? NULL),
                'avg_aux_consumption_kwh_100' => $this->numOrNull($row['Average auxiliary consumption in kWh/100km'] ?? NULL),
                'avg_recuperation_kwh_100'    => $this->numOrNull($row['Average recuperation in kWh/100km'] ?? NULL),
            ];
        }
        
        public function exportHeaders(): array {
            return [
                'End of trip',
                'Start mileage in km',
                'End mileage in km',
                'Mileage in km',
                'Travel time in minutes',
                'Average speed in km/h',
                'Average electric consumption in kWh/100km',
                'Total cost',
                'Total cost currency',
                'Electricity cost',
                'Electricity cost currency',
                'Electricity price per kWh',
                'Average auxiliary consumption in kWh/100km',
                'Average recuperation in kWh/100km'
            ];
        }
        
        public function exportRow(array $t): array {
            return [
                date('d.m.Y H:i', strtotime((string)$t['ended_at'])),
                $this->out($t['start_odometer_km'] ?? NULL),
                $this->out($t['end_odometer_km'] ?? NULL),
                $this->out($t['distance_km'] ?? NULL),
                $this->out($t['travel_minutes'] ?? NULL),
                $this->out($t['avg_speed_kmh'] ?? NULL),
                $this->out($t['avg_consumption_kwh_100'] ?? NULL),
                $this->out($t['total_cost'] ?? NULL),
                $this->out($t['total_cost_currency'] ?? NULL),
                $this->out($t['electricity_cost'] ?? NULL),
                $this->out($t['electricity_cost_currency'] ?? NULL),
                $this->out($t['electricity_price_per_kwh'] ?? NULL),
                $this->out($t['avg_aux_consumption_kwh_100'] ?? NULL),
                $this->out($t['avg_recuperation_kwh_100'] ?? NULL)
            ];
        }
    }

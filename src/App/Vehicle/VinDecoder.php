<?php

    declare(strict_types=1);

    namespace App\Vehicle;

    /**
     * Jednoduchý dekodér WMI části VIN.
     *
     * První tři znaky VIN (World Manufacturer Identifier) používáme pouze pro
     * bezpečné rozpoznání výrobce. Konkrétní model se určuje až v kontextu
     * importního pluginu, protože samotné WMI model vozidla neurčuje.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   16.09.2026
     */
    final class VinDecoder
    {
        /**
         * Mapa WMI prefixů na interní identifikátor výrobce.
         *
         * Jedna automobilka může používat více WMI podle země výroby,
         * výrobního závodu nebo konkrétní divize.
         *
         * @var array<string,string>
         */
        private const MANUFACTURERS = [
            // Škoda
            'TMB' => 'SKODA',

            // Volkswagen
            'WVW' => 'VOLKSWAGEN',
            '1VW' => 'VOLKSWAGEN',
            '3VW' => 'VOLKSWAGEN',

            // Audi
            'WAU' => 'AUDI',
            'WA1' => 'AUDI',
            'TRU' => 'AUDI',

            // Porsche
            'WP0' => 'PORSCHE',
            'WP1' => 'PORSCHE',

            // BMW
            'WBA' => 'BMW',
            'WBS' => 'BMW',
            'WBY' => 'BMW',
            '4US' => 'BMW',
            '5UX' => 'BMW',

            // MINI
            'WMW' => 'MINI',

            // Mercedes-Benz
            'WDB' => 'MERCEDES_BENZ',
            'WDD' => 'MERCEDES_BENZ',
            'W1K' => 'MERCEDES_BENZ',
            'W1N' => 'MERCEDES_BENZ',

            // Smart
            'WME' => 'SMART',

            // Opel
            'W0L' => 'OPEL',

            // Ford
            'WF0' => 'FORD',
            '1FA' => 'FORD',

            // Renault
            'VF1' => 'RENAULT',

            // Dacia
            'UU1' => 'DACIA',
            'VR1' => 'DACIA',

            // Peugeot
            'VF3' => 'PEUGEOT',

            // Citroën
            'VF7' => 'CITROEN',

            // Fiat
            'ZFA' => 'FIAT',

            // Alfa Romeo
            'ZAR' => 'ALFA_ROMEO',

            // Volvo
            'YV1' => 'VOLVO',

            // Jaguar
            'SAJ' => 'JAGUAR',

            // Land Rover
            'SAL' => 'LAND_ROVER',

            // Toyota
            'JTD' => 'TOYOTA',
            'JT2' => 'TOYOTA',
            'SB1' => 'TOYOTA',
            'NMT' => 'TOYOTA',

            // Lexus
            'JTH' => 'LEXUS',
            'JTJ' => 'LEXUS',
            '2T2' => 'LEXUS',

            // Honda
            'JHM' => 'HONDA',
            '1HG' => 'HONDA',
            '2HG' => 'HONDA',

            // Nissan
            'JN1' => 'NISSAN',
            'SJN' => 'NISSAN',
            '1N4' => 'NISSAN',

            // Mazda
            'JMZ' => 'MAZDA',
            'JM1' => 'MAZDA',

            // Subaru
            'JF1' => 'SUBARU',
            'JF2' => 'SUBARU',
            '4S3' => 'SUBARU',
            '4S4' => 'SUBARU',

            // Suzuki
            'JS2' => 'SUZUKI',
            'JS3' => 'SUZUKI',

            // Mitsubishi
            'JMB' => 'MITSUBISHI',

            // Hyundai
            'KMH' => 'HYUNDAI',
            'KM8' => 'HYUNDAI',

            // Kia
            'KNA' => 'KIA',
            'KNB' => 'KIA',
            'KNC' => 'KIA',
            'U5Y' => 'KIA',

            // Tesla
            '5YJ' => 'TESLA',
            '7SA' => 'TESLA',
            'LRW' => 'TESLA',
        ];

        /**
         * Normalizuje VIN pro další zpracování.
         */
        public static function normalize(?string $vin): ?string
        {
            if ($vin === null) {
                return null;
            }

            $vin = strtoupper(trim($vin));

            return $vin !== '' ? $vin : null;
        }

        /**
         * Vrátí WMI část VIN, tedy první tři znaky.
         */
        public static function wmi(?string $vin): ?string
        {
            $vin = self::normalize($vin);

            if ($vin === null || strlen($vin) < 3) {
                return null;
            }

            return substr($vin, 0, 3);
        }

        /**
         * Vrátí interní identifikátor výrobce podle WMI.
         *
         * Například:
         * TMB => SKODA
         * WVW => VOLKSWAGEN
         * U5Y => KIA
         * 5YJ => TESLA
         */
        public static function manufacturer(?string $vin): ?string
        {
            $wmi = self::wmi($vin);

            if ($wmi === null) {
                return null;
            }

            return self::MANUFACTURERS[$wmi] ?? null;
        }

        /**
         * Zjistí, zda máme WMI daného VIN v interní databázi.
         */
        public static function isSupported(?string $vin): bool
        {
            return self::manufacturer($vin) !== null;
        }

        /**
         * Vrátí všechny aktuálně podporované WMI prefixy.
         *
         * @return array<string,string>
         */
        public static function manufacturers(): array
        {
            return self::MANUFACTURERS;
        }
    }

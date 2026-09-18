<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Vag;

use App\Integration\Vehicle\VehicleConnectorException;
use App\Integration\Vehicle\VehicleConnectorInterface;

/**
 * Společná implementace read-only EU Data Act Data Hub konektoru pro značky
 * koncernu Volkswagen.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
abstract class AbstractVagDataHubConnector implements VehicleConnectorInterface
{
    /** @var VagDataHubClient */
    protected $client;

    /** @var int */
    private $syncIntervalSeconds;

    public function __construct(VagDataHubClient $client, int $syncIntervalSeconds = 900)
    {
        $this->client = $client;
        $this->syncIntervalSeconds = max(300, min(21600, $syncIntervalSeconds));
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return [
            'read_telemetry' => true,
            'odometer' => true,
            'charging' => true,
            'parking_position' => true,
            'fuel' => true,
            'remote_commands' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function credentialSchema(): array
    {
        return [
            'type' => 'api_key',
            'configured' => $this->client->configured(),
            'fields' => [
                [
                    'name' => 'marketplace_api_key',
                    'label' => 'Data Hub API key',
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'off',
                    'help' => 'API key z Volkswagen Group Info Services Data Hub subscription. Ukládá se pouze šifrovaně.',
                ],
            ],
            'description' => 'Oficiální Volkswagen Group Info Services EU Data Act API. Vyžaduje Data Hub onboarding, subscription a consent pro dané VIN.',
            'help' => $this->client->configured()
                ? 'Před připojením musí být VIN aktivní ve vašem Data Hub subscription.'
                : 'Doplňte VAG_DATA_HUB_VEHICLE_DATA_URL_TEMPLATE a ONE Business ID Client ID/Secret v .env.',
            'documentation_url' => 'https://drivesomethinggreater.com/developer-hub/api-specifications/EUDA-api-specification',
        ];
    }

    /** @param array<string,mixed> $vehicle */
    public function supportsVehicle(array $vehicle): bool
    {
        $manufacturer = strtoupper(trim((string)($vehicle['manufacturer'] ?? '')));
        return in_array($manufacturer, $this->supportedManufacturers(), true);
    }

    public function recommendedSyncIntervalSeconds(): int
    {
        return $this->syncIntervalSeconds;
    }

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $credentials @return array<string,mixed> */
    public function fetch(array $vehicle, array $credentials): array
    {
        $vin = strtoupper(trim((string)($vehicle['vin'] ?? '')));
        $apiKey = trim((string)($credentials['marketplace_api_key'] ?? ''));
        if ($vin === '' || $apiKey === '') {
            throw new VehicleConnectorException($this->label() . ' vyžaduje VIN a Data Hub API key.', 0, true);
        }

        $response = $this->client->fetchVehicle($vin, $apiKey);
        $normalized = VagDataHubNormalizer::normalize($response['payload'], $this->vendorCode());
        $returnedVin = strtoupper(trim((string)($normalized['vin'] ?? '')));
        if ($returnedVin !== '' && !hash_equals($vin, $returnedVin)) {
            throw new VehicleConnectorException('Data Hub vrátil jiné VIN než vozidlo v EV Stats.', 403, true);
        }

        return [
            'snapshot' => $normalized,
            'connection' => [
                'external_vehicle_id' => $vin,
                'external_vin' => $vin,
                'external_name' => (string)($normalized['name'] ?? $vehicle['name'] ?? $this->label()),
                'capabilities' => $normalized['capabilities'] ?? [],
                'next_sync_at' => date('Y-m-d H:i:s', time() + $this->syncIntervalSeconds),
                'metadata' => [
                    'api' => 'Volkswagen Group Info Services EU Data Act API',
                    'brand' => $this->vendorCode(),
                ],
            ],
        ];
    }

    abstract protected function vendorCode(): string;
}

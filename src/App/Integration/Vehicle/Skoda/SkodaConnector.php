<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Skoda;

use App\Integration\Vehicle\VehicleConnectorException;
use App\Integration\Vehicle\VehicleConnectorInterface;

/**
 * Přímý konektor pro oficiální MyŠkoda Public API.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class SkodaConnector implements VehicleConnectorInterface
{
    /** @var SkodaPublicApiClient */
    private $client;

    /** @var int */
    private $syncIntervalSeconds;

    /** @var int */
    private $rateLimitReserve;

    public function __construct(SkodaPublicApiClient $client, int $syncIntervalSeconds = 600, int $rateLimitReserve = 3)
    {
        $this->client = $client;
        $this->syncIntervalSeconds = max(300, min(3600, $syncIntervalSeconds));
        $this->rateLimitReserve = max(1, min(10, $rateLimitReserve));
    }

    public function id(): string
    {
        return 'skoda_public_api';
    }

    public function label(): string
    {
        return 'MyŠkoda Public API';
    }

    /** @return string[] */
    public function supportedManufacturers(): array
    {
        return ['SKODA', 'ŠKODA'];
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
            'vehicle_status' => true,
            'remote_commands' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function credentialSchema(): array
    {
        return [
            'type' => 'api_key',
            'fields' => [
                [
                    'name' => 'api_key',
                    'label' => 'API klíč MyŠkoda',
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'off',
                    'help' => 'Klíč vytvořte v aplikaci MyŠkoda a povolte v něm toto vozidlo.',
                ],
            ],
            'documentation_url' => 'https://public.api.connect.skoda-auto.cz/docs',
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
        $apiKey = trim((string)($credentials['api_key'] ?? ''));
        if ($vin === '' || $apiKey === '') {
            throw new VehicleConnectorException('Škoda konektor vyžaduje VIN a API klíč.', 0, true);
        }

        $response = $this->client->fetchVehicle($vin, $apiKey);
        $normalized = SkodaVehicleNormalizer::normalize($response['payload']);
        $returnedVin = strtoupper(trim((string)($normalized['vin'] ?? '')));
        if ($returnedVin !== '' && !hash_equals($vin, $returnedVin)) {
            throw new VehicleConnectorException('MyŠkoda vrátila jiné VIN než vozidlo v EV Stats. Připojení bylo z bezpečnostních důvodů odmítnuto.', 403, true);
        }

        $metadata = $this->client->headerMetadata($response['headers']);
        $remaining = $metadata['rate_limit_remaining'] ?? null;
        $nextSyncAt = date('Y-m-d H:i:s', time() + $this->syncIntervalSeconds);
        if (is_numeric($remaining) && (int)$remaining <= $this->rateLimitReserve && !empty($metadata['rate_limit_reset_at'])) {
            $reset = strtotime((string)$metadata['rate_limit_reset_at']);
            if ($reset !== false) {
                $nextSyncAt = date('Y-m-d H:i:s', $reset + 5);
            }
        }

        $errors = isset($response['payload']['errors']) && is_array($response['payload']['errors'])
            ? $response['payload']['errors']
            : [];

        return [
            'snapshot' => $normalized,
            'connection' => [
                'external_vehicle_id' => $vin,
                'external_vin' => $vin,
                'external_name' => (string)($normalized['name'] ?? $vehicle['name'] ?? 'Škoda'),
                'capabilities' => $normalized['capabilities'] ?? [],
                'credential_expires_at' => $metadata['credential_expires_at'] ?? null,
                'rate_limit_limit' => $metadata['rate_limit_limit'] ?? null,
                'rate_limit_remaining' => $metadata['rate_limit_remaining'] ?? null,
                'rate_limit_reset_at' => $metadata['rate_limit_reset_at'] ?? null,
                'retry_after_at' => $metadata['retry_after_at'] ?? null,
                'next_sync_at' => $nextSyncAt,
                'metadata' => [
                    'partial_errors' => $errors,
                    'api' => 'MyŠkoda Public API',
                ],
            ],
        ];
    }
}

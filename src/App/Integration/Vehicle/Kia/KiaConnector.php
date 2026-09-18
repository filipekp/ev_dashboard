<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Kia;

use App\Integration\Vehicle\RefreshableVehicleConnectorInterface;
use App\Integration\Vehicle\RevocableVehicleConnectorInterface;
use App\Integration\Vehicle\VehicleConnectorException;
use App\Integration\Vehicle\VehicleConnectorInterface;

/**
 * Přímý konektor Kia Europe Vehicle Data API přes Pleos Business User flow.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaConnector implements VehicleConnectorInterface, RefreshableVehicleConnectorInterface, RevocableVehicleConnectorInterface
{
    /** @var KiaPleosApiClient */
    private $client;

    /** @var int */
    private $syncIntervalSeconds;

    public function __construct(KiaPleosApiClient $client, int $syncIntervalSeconds = 900)
    {
        $this->client = $client;
        $this->syncIntervalSeconds = max(300, min(7200, $syncIntervalSeconds));
    }

    public function id(): string
    {
        return 'kia_pleos';
    }

    public function label(): string
    {
        return 'Kia Connect / Pleos';
    }

    /** @return string[] */
    public function supportedManufacturers(): array
    {
        return ['KIA'];
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
            'oauth' => true,
            'remote_commands' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function credentialSchema(): array
    {
        return [
            'type' => 'oauth',
            'fields' => [],
            'available' => $this->client->isConfigured(),
            'connect_url' => 'kia-oauth-start.php',
            'connect_label' => 'Připojit Kia účet',
            'help' => $this->client->isConfigured()
                ? 'Přihlášení proběhne přímo přes Kia/Pleos. EV Stats nikdy neuvidí vaše heslo ke Kia Connect.'
                : 'Kia konektor není nakonfigurován. Administrátor musí doplnit KIA_PLEOS_CLIENT_ID a KIA_PLEOS_CLIENT_SECRET.',
            'documentation_url' => 'https://document.pleos.ai/en/api-reference/vehicle-data-api/intro',
        ];
    }

    /** @param array<string,mixed> $vehicle */
    public function supportsVehicle(array $vehicle): bool
    {
        return strtoupper(trim((string)($vehicle['manufacturer'] ?? ''))) === 'KIA';
    }

    public function recommendedSyncIntervalSeconds(): int
    {
        return $this->syncIntervalSeconds;
    }

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $credentials @return array<string,mixed> */
    public function fetch(array $vehicle, array $credentials): array
    {
        $vin = strtoupper(trim((string)($vehicle['vin'] ?? '')));
        if ($vin === '') {
            throw new VehicleConnectorException('Kia konektor vyžaduje VIN.', 0, true);
        }

        $accessToken = trim((string)($credentials['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new VehicleConnectorException('Kia konektor nemá access token. Připojte Kia účet znovu.', 401, true);
        }

        $response = $this->client->fetchVehicleTelemetry($vin, $accessToken);
        $normalized = KiaVehicleNormalizer::normalize(
            $vin,
            isset($response['payloads']) && is_array($response['payloads']) ? $response['payloads'] : [],
            isset($response['partial_errors']) && is_array($response['partial_errors']) ? $response['partial_errors'] : []
        );

        $credentialExpiresAt = trim((string)($credentials['access_token_expires_at'] ?? ''));
        return [
            'snapshot' => $normalized,
            'connection' => [
                'external_vehicle_id' => $vin,
                'external_vin' => $vin,
                'external_name' => (string)($vehicle['name'] ?? 'Kia'),
                'capabilities' => $normalized['capabilities'] ?? [],
                'credential_expires_at' => $credentialExpiresAt !== '' ? $credentialExpiresAt : null,
                'next_sync_at' => date('Y-m-d H:i:s', time() + $this->syncIntervalSeconds),
                'metadata' => [
                    'api' => 'Kia Europe Vehicle Data API / Pleos',
                    'partial_errors' => $response['partial_errors'] ?? [],
                    'refresh_token_expires_at' => $credentials['refresh_token_expires_at'] ?? null,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public function refreshCredentialsIfNeeded(array $credentials): array
    {
        $fresh = $this->client->ensureFreshCredentials($credentials);
        $updated = $fresh['credentials'];
        return [
            'credentials' => $updated,
            'changed' => (bool)$fresh['refreshed'],
            'hint' => 'OAuth / Kia',
            'expires_at' => isset($updated['access_token_expires_at'])
                ? (string)$updated['access_token_expires_at']
                : null,
        ];
    }

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $credentials */
    public function revoke(array $vehicle, array $credentials): void
    {
        $vin = strtoupper(trim((string)($vehicle['vin'] ?? '')));
        $fresh = $this->client->ensureFreshCredentials($credentials);
        $accessToken = trim((string)($fresh['credentials']['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new VehicleConnectorException('Kia konektor nemá platný access token pro odvolání souhlasu.', 401, true);
        }
        $this->client->stopDataSharing($accessToken, $vin);
    }

    public function purgeDataOnDisconnect(): bool
    {
        return true;
    }
}

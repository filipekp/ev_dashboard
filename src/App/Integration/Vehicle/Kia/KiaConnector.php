<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Kia;

use App\Integration\Vehicle\RefreshableVehicleConnectorInterface;
use App\Integration\Vehicle\VehicleConnectorException;
use App\Integration\Vehicle\VehicleConnectorInterface;

/**
 * Přímý konektor Kia Europe Vehicle Data API přes Pleos.
 *
 * Nová připojení používají Private User My Vehicle API key, tedy Client ID a
 * Client Secret vygenerované uživatelem v Pleos Playground. Legacy Business
 * User OAuth credentials jsou dočasně podporované kvůli bezbolestnému upgradu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaConnector implements VehicleConnectorInterface, RefreshableVehicleConnectorInterface
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
        return 'Kia Vehicle Data API / Pleos';
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
            'oauth' => false,
            'remote_commands' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function credentialSchema(): array
    {
        return [
            'type' => 'credentials',
            'available' => true,
            'fields' => [
                [
                    'name' => 'client_id',
                    'label' => 'Client ID',
                    'type' => 'text',
                    'required' => true,
                    'autocomplete' => 'off',
                    'placeholder' => 'Client ID z Pleos Playground',
                    'help' => 'Najdete jej v Pleos Playground → My Vehicle Data API.',
                ],
                [
                    'name' => 'client_secret',
                    'label' => 'Client Secret',
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Client Secret z Pleos Playground',
                    'help' => 'Secret se ukládá pouze šifrovaně pomocí VEHICLE_CREDENTIALS_KEY.',
                ],
            ],
            'description' => 'Oficiální Kia Vehicle Data API pro vlastní vozidlo. Každý uživatel zadá svůj Client ID a Client Secret z Pleos Playground.',
            'help' => 'Přístupové údaje si vytvořte na Pleos Playground v sekci My Vehicle Data API. EV Stats z nich při každé synchronizaci získá access token; Client Secret nikdy nezobrazuje zpět.',
            'documentation_url' => 'https://document.pleos.ai/en/api-reference/vehicle-data-api/getting-started/for-private-users/authorization',
            'credentials_url' => 'https://pleos.ai/playground/vehicle-data-api',
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

        $clientId = trim((string)($credentials['client_id'] ?? ''));
        $clientSecret = trim((string)($credentials['client_secret'] ?? ''));
        $legacyAccessToken = trim((string)($credentials['access_token'] ?? ''));
        $authMode = 'personal_api_key';

        if ($clientId !== '' || $clientSecret !== '') {
            if ($clientId === '' || $clientSecret === '') {
                throw new VehicleConnectorException('Kia konektor vyžaduje Client ID i Client Secret.', 0, true);
            }
            $accessToken = $this->client->createPersonalAccessToken($clientId, $clientSecret);
            $remoteVehicle = $this->findPersonalVehicle($vin, $this->client->consentVehicles($accessToken));
            if ($remoteVehicle === null) {
                throw new VehicleConnectorException(
                    'Pleos My Vehicle API key neobsahuje VIN ' . $vin . '. Pokud bylo vozidlo přidáno až po vytvoření klíče, v Pleos Playground klíč znovu vygenerujte.',
                    403,
                    true
                );
            }
        } elseif ($legacyAccessToken !== '') {
            $accessToken = $legacyAccessToken;
            $remoteVehicle = null;
            $authMode = 'legacy_business_oauth';
        } else {
            throw new VehicleConnectorException('Kia konektor nemá Client ID a Client Secret. Připojení nastavte znovu.', 401, true);
        }

        $response = $this->client->fetchVehicleTelemetry($vin, $accessToken);
        $normalized = KiaVehicleNormalizer::normalize(
            $vin,
            isset($response['payloads']) && is_array($response['payloads']) ? $response['payloads'] : [],
            isset($response['partial_errors']) && is_array($response['partial_errors']) ? $response['partial_errors'] : [],
            (string)($vehicle['powertrain_type'] ?? '')
        );

        $externalName = (string)($vehicle['name'] ?? 'Kia');
        if (isset($remoteVehicle) && is_array($remoteVehicle)) {
            $externalName = trim((string)($remoteVehicle['nickname'] ?? ''))
                ?: trim((string)($remoteVehicle['sellname'] ?? ''))
                ?: $externalName;
        }

        $credentialExpiresAt = $authMode === 'legacy_business_oauth'
            ? trim((string)($credentials['access_token_expires_at'] ?? ''))
            : '';

        return [
            'snapshot' => $normalized,
            'connection' => [
                'external_vehicle_id' => $vin,
                'external_vin' => $vin,
                'external_name' => $externalName,
                'capabilities' => $normalized['capabilities'] ?? [],
                'credential_expires_at' => $credentialExpiresAt !== '' ? $credentialExpiresAt : null,
                'next_sync_at' => date('Y-m-d H:i:s', time() + $this->syncIntervalSeconds),
                'metadata' => [
                    'api' => 'Kia Europe Vehicle Data API / Pleos',
                    'auth_mode' => $authMode,
                    'partial_errors' => $response['partial_errors'] ?? [],
                    'refresh_token_expires_at' => $credentials['refresh_token_expires_at'] ?? null,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public function refreshCredentialsIfNeeded(array $credentials): array
    {
        $clientId = trim((string)($credentials['client_id'] ?? ''));
        $clientSecret = trim((string)($credentials['client_secret'] ?? ''));
        if ($clientId !== '' || $clientSecret !== '') {
            return [
                'credentials' => $credentials,
                'changed' => false,
                'hint' => $clientId !== '' ? 'Client ID ••••' . substr($clientId, -4) : 'Pleos My Vehicle API',
                'expires_at' => null,
            ];
        }

        $fresh = $this->client->ensureFreshCredentials($credentials);
        $updated = $fresh['credentials'];
        return [
            'credentials' => $updated,
            'changed' => (bool)$fresh['refreshed'],
            'hint' => 'Legacy OAuth / Kia',
            'expires_at' => isset($updated['access_token_expires_at'])
                ? (string)$updated['access_token_expires_at']
                : null,
        ];
    }

    /** @param array<int,array<string,mixed>> $vehicles @return array<string,mixed>|null */
    private function findPersonalVehicle(string $vin, array $vehicles): ?array
    {
        foreach ($vehicles as $remoteVehicle) {
            if (strtoupper(trim((string)($remoteVehicle['vin'] ?? ''))) === $vin) {
                return $remoteVehicle;
            }
        }

        return null;
    }
}

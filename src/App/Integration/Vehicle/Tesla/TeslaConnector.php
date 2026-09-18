<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Tesla;

use App\Integration\Vehicle\OAuthVehicleConnectorInterface;
use App\Integration\Vehicle\RefreshableVehicleConnectorInterface;
use App\Integration\Vehicle\VehicleConnectorException;

/**
 * Oficiální Tesla Fleet API konektor s OAuth autorizací uživatele.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class TeslaConnector implements OAuthVehicleConnectorInterface, RefreshableVehicleConnectorInterface
{
    /** @var TeslaFleetApiClient */
    private $client;

    /** @var int */
    private $syncIntervalSeconds;

    public function __construct(TeslaFleetApiClient $client, int $syncIntervalSeconds = 1800)
    {
        $this->client = $client;
        $this->syncIntervalSeconds = max(600, min(21600, $syncIntervalSeconds));
    }

    public function id(): string
    {
        return 'tesla_fleet_api';
    }

    public function label(): string
    {
        return 'Tesla Fleet API';
    }

    /** @return string[] */
    public function supportedManufacturers(): array
    {
        return ['TESLA'];
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return [
            'read_telemetry' => true,
            'odometer' => true,
            'charging' => true,
            'parking_position' => true,
            'vehicle_status' => true,
            'remote_commands' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function credentialSchema(): array
    {
        return [
            'type' => 'oauth',
            'available' => $this->oauthConfigured(),
            'configured' => $this->oauthConfigured(),
            'fields' => [],
            'connect_url' => 'vehicle-oauth-start.php',
            'connect_label' => 'Připojit Tesla účet',
            'description' => 'Oficiální Tesla Fleet API přes OAuth. Heslo k Tesla účtu se do EV Stats nikdy neposílá.',
            'help' => $this->oauthConfigured()
                ? 'Po kliknutí budete přesměrováni na Tesla autorizaci a zpět do EV Stats.'
                : 'Nejdříve nastavte TESLA_CLIENT_ID a TESLA_CLIENT_SECRET v .env.',
            'documentation_url' => 'https://developer.tesla.com/docs/fleet-api/authentication/overview',
        ];
    }

    /** @param array<string,mixed> $vehicle */
    public function supportsVehicle(array $vehicle): bool
    {
        return strtoupper(trim((string)($vehicle['manufacturer'] ?? ''))) === 'TESLA';
    }

    public function recommendedSyncIntervalSeconds(): int
    {
        return $this->syncIntervalSeconds;
    }

    public function oauthConfigured(): bool
    {
        return $this->client->configured();
    }

    public function authorizationUrl(string $state, string $nonce): string
    {
        return $this->client->authorizationUrl($state, $nonce);
    }

    /** @return array<string,mixed> */
    public function exchangeAuthorizationCode(string $code): array
    {
        return $this->client->exchangeAuthorizationCode($code);
    }

    /** @param array<string,mixed> $credentials @return array<string,mixed> */
    public function refreshCredentialsIfNeeded(array $credentials): array
    {
        $accessToken = trim((string)($credentials['access_token'] ?? ''));
        $expiresAt = trim((string)($credentials['expires_at'] ?? ''));
        $timestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
        $needsRefresh = $accessToken === '' || ($timestamp !== false && $timestamp <= time() + 120);

        if (!$needsRefresh) {
            return [
                'credentials' => $credentials,
                'changed' => false,
                'hint' => 'OAuth / Tesla',
                'expires_at' => $this->credentialExpiry($credentials),
            ];
        }

        $refreshToken = trim((string)($credentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new VehicleConnectorException('Tesla refresh token chybí. Připojte Tesla účet znovu.', 401, true);
        }

        $refreshed = $this->client->refreshAccessToken($refreshToken);
        if (trim((string)($refreshed['refresh_token'] ?? '')) === '') {
            $refreshed['refresh_token'] = $refreshToken;
        }
        $updated = array_merge($credentials, $refreshed);

        return [
            'credentials' => $updated,
            'changed' => true,
            'hint' => 'OAuth / Tesla',
            'expires_at' => $this->credentialExpiry($updated),
        ];
    }

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $credentials @return array<string,mixed> */
    public function fetch(array $vehicle, array $credentials): array
    {
        $vin = strtoupper(trim((string)($vehicle['vin'] ?? '')));
        if ($vin === '') {
            throw new VehicleConnectorException('Tesla konektor vyžaduje VIN vozidla.', 0, true);
        }

        $updatedCredentials = null;
        if ($this->tokenExpired($credentials)) {
            $refreshed = $this->client->refreshAccessToken((string)($credentials['refresh_token'] ?? ''));
            if (trim((string)($refreshed['refresh_token'] ?? '')) === '') {
                $refreshed['refresh_token'] = (string)($credentials['refresh_token'] ?? '');
            }
            $credentials = array_merge($credentials, $refreshed);
            $updatedCredentials = $credentials;
        }

        $accessToken = trim((string)($credentials['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new VehicleConnectorException('Tesla access token chybí. Připojte Tesla účet znovu.', 401, true);
        }

        try {
            $response = $this->client->fetchVehicleData($vin, $accessToken);
        } catch (VehicleConnectorException $e) {
            if ($e->httpStatus() !== 401 || trim((string)($credentials['refresh_token'] ?? '')) === '') {
                throw $e;
            }
            $refreshed = $this->client->refreshAccessToken((string)$credentials['refresh_token']);
            if (trim((string)($refreshed['refresh_token'] ?? '')) === '') {
                $refreshed['refresh_token'] = (string)$credentials['refresh_token'];
            }
            $credentials = array_merge($credentials, $refreshed);
            $updatedCredentials = $credentials;
            $response = $this->client->fetchVehicleData($vin, (string)$credentials['access_token']);
        }

        $normalized = TeslaVehicleNormalizer::normalize($response['payload']);
        $returnedVin = strtoupper(trim((string)($normalized['vin'] ?? '')));
        if ($returnedVin !== '' && !hash_equals($vin, $returnedVin)) {
            throw new VehicleConnectorException('Tesla API vrátilo jiné VIN než vozidlo v EV Stats.', 403, true);
        }

        $result = [
            'snapshot' => $normalized,
            'connection' => [
                'external_vehicle_id' => $vin,
                'external_vin' => $vin,
                'external_name' => (string)($normalized['name'] ?? $vehicle['name'] ?? 'Tesla'),
                'capabilities' => $normalized['capabilities'] ?? [],
                'credential_expires_at' => $this->credentialExpiry($credentials),
                'next_sync_at' => date('Y-m-d H:i:s', time() + $this->syncIntervalSeconds),
                'metadata' => [
                    'api' => 'Tesla Fleet API',
                    'auth' => 'OAuth 2.0',
                ],
            ],
        ];
        if ($updatedCredentials !== null) {
            $result['credentials'] = $updatedCredentials;
        }

        return $result;
    }

    /** @param array<string,mixed> $credentials */
    private function tokenExpired(array $credentials): bool
    {
        $accessToken = trim((string)($credentials['access_token'] ?? ''));
        if ($accessToken === '') {
            return true;
        }
        $expiresAt = trim((string)($credentials['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return false;
        }
        $timestamp = strtotime($expiresAt);
        return $timestamp !== false && $timestamp <= time() + 120;
    }

    /** @param array<string,mixed> $credentials */
    private function credentialExpiry(array $credentials): ?string
    {
        $value = trim((string)($credentials['expires_at'] ?? ''));
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}

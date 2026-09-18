<?php

declare(strict_types=1);

namespace App\Service;

use App\AuthService;
use App\Integration\Vehicle\RefreshableVehicleConnectorInterface;
use App\Integration\Vehicle\RevocableVehicleConnectorInterface;
use App\Integration\Vehicle\VehicleConnectorRegistry;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleRepository;
use App\Security\CredentialCipher;
use RuntimeException;

/**
 * Aplikační služba pro připojení, obnovu a odpojení OEM konektorů vozidel.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class VehicleConnectorService
{
    /** @var AuthService */
    private $auth;

    /** @var VehicleRepository */
    private $vehicles;

    /** @var VehicleDataRepository */
    private $repository;

    /** @var VehicleConnectorRegistry */
    private $connectors;

    /** @var CredentialCipher */
    private $cipher;

    /** @var VehicleSyncService */
    private $sync;

    public function __construct(
        AuthService $auth,
        VehicleRepository $vehicles,
        VehicleDataRepository $repository,
        VehicleConnectorRegistry $connectors,
        CredentialCipher $cipher,
        VehicleSyncService $sync
    ) {
        $this->auth = $auth;
        $this->vehicles = $vehicles;
        $this->repository = $repository;
        $this->connectors = $connectors;
        $this->cipher = $cipher;
        $this->sync = $sync;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $credentials
     * @return array{connection_id:int,snapshots:int,events:int}
     */
    public function connect(array $user, int $vehicleId, string $provider, array $credentials): array
    {
        $vehicle = $this->managedVehicle($user, $vehicleId);
        $connector = $this->connectors->get($provider);
        if (!$connector->supportsVehicle($vehicle)) {
            throw new RuntimeException('Tento konektor není určen pro výrobce vybraného vozidla.');
        }
        $schema = $connector->credentialSchema();
        if (($schema['type'] ?? '') === 'oauth') {
            throw new RuntimeException('Tento konektor používá OAuth. Připojení spusťte tlačítkem přihlášení k výrobci.');
        }

        $this->validateCredentials($schema, $credentials);
        $encrypted = $this->cipher->encrypt($credentials);
        $secret = $this->primarySecret($credentials);
        $hint = $secret !== '' ? '••••' . substr($secret, -4) : 'uloženo';
        $fingerprint = $secret !== '' ? hash('sha256', $secret) : hash('sha256', $encrypted);
        $externalId = strtoupper(trim((string)($vehicle['vin'] ?? '')));

        $connection = $this->repository->upsertConnectorConnection(
            (int)$user['id'],
            $vehicleId,
            $provider,
            $externalId,
            $encrypted,
            $fingerprint,
            $hint
        );

        $sync = $this->sync->syncConnection((int)$connection['id'], (int)$user['id'], 'initial');
        return [
            'connection_id' => (int)$connection['id'],
            'snapshots' => $sync['snapshots'],
            'events' => $sync['events'],
        ];
    }

    /**
     * Uloží credentials získané přes OAuth callback a spustí první synchronizaci.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $credentials
     * @return array{connection_id:int,snapshots:int,events:int}
     */
    public function connectOAuth(array $user, int $vehicleId, string $provider, array $credentials): array
    {
        $vehicle = $this->managedVehicle($user, $vehicleId);
        $connector = $this->connectors->get($provider);
        if (!$connector->supportsVehicle($vehicle)) {
            throw new RuntimeException('Tento konektor není určen pro výrobce vybraného vozidla.');
        }
        $schema = $connector->credentialSchema();
        if (($schema['type'] ?? '') !== 'oauth') {
            throw new RuntimeException('Vybraný konektor nepoužívá OAuth autorizaci.');
        }
        if ($credentials === []) {
            throw new RuntimeException('OAuth provider nevrátil přístupové údaje.');
        }

        $encrypted = $this->cipher->encrypt($credentials);
        $secret = $this->primarySecret($credentials);
        $hint = $secret !== '' ? 'OAuth ••••' . substr($secret, -4) : 'OAuth';
        $fingerprint = $secret !== '' ? hash('sha256', $secret) : hash('sha256', $encrypted);
        $externalId = strtoupper(trim((string)($vehicle['vin'] ?? '')));

        $connection = $this->repository->upsertConnectorConnection(
            (int)$user['id'],
            $vehicleId,
            $provider,
            $externalId,
            $encrypted,
            $fingerprint,
            $hint
        );

        $expiresAt = trim((string)($credentials['expires_at'] ?? $credentials['access_token_expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $this->repository->updateConnectionCredentials(
                (int)$connection['id'],
                $encrypted,
                $fingerprint,
                $hint,
                $expiresAt
            );
        }

        $sync = $this->sync->syncConnection((int)$connection['id'], (int)$user['id'], 'initial');
        return [
            'connection_id' => (int)$connection['id'],
            'snapshots' => $sync['snapshots'],
            'events' => $sync['events'],
        ];
    }

    /** @param array<string,mixed> $user @return array{snapshots:int,events:int} */
    public function sync(array $user, int $vehicleId): array
    {
        $this->managedVehicle($user, $vehicleId);
        $connection = $this->requiredConnection($vehicleId);
        return $this->sync->syncConnection((int)$connection['id'], (int)$user['id'], 'manual');
    }

    /** @param array<string,mixed> $user */
    public function disconnect(array $user, int $vehicleId): void
    {
        $vehicle = $this->managedVehicle($user, $vehicleId);
        $connection = $this->requiredConnection($vehicleId);
        $connectionId = (int)$connection['id'];
        $connector = $this->connectors->get((string)$connection['provider']);

        if ($connector instanceof RevocableVehicleConnectorInterface) {
            $encrypted = trim((string)($connection['credentials_encrypted'] ?? ''));
            if ($encrypted !== '') {
                $credentials = $this->cipher->decrypt($encrypted);
                if ($connector instanceof RefreshableVehicleConnectorInterface) {
                    $prepared = $connector->refreshCredentialsIfNeeded($credentials);
                    $credentials = $prepared['credentials'];
                    if (!empty($prepared['changed'])) {
                        $newEncrypted = $this->cipher->encrypt($credentials);
                        $secret = trim((string)($credentials['refresh_token'] ?? $credentials['access_token'] ?? ''));
                        $this->repository->updateConnectionCredentials(
                            $connectionId,
                            $newEncrypted,
                            hash('sha256', $secret !== '' ? $secret : $newEncrypted),
                            (string)($prepared['hint'] ?? 'OAuth'),
                            isset($prepared['expires_at']) ? (string)$prepared['expires_at'] : null
                        );
                    }
                }
                $connector->revoke($vehicle, $credentials);
            }
            if ($connector->purgeDataOnDisconnect()) {
                $this->repository->purgeConnectionData($connectionId);
            }
        }

        $this->repository->deleteConnection($connectionId);
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function managedVehicle(array $user, int $vehicleId): array
    {
        if ($vehicleId <= 0 || !$this->auth->canAccessVehicle($user, $vehicleId)) {
            throw new RuntimeException('Toto vozidlo nemůžete spravovat.');
        }
        $vehicle = $this->vehicles->find($vehicleId);
        if ($vehicle === null) {
            throw new RuntimeException('Vozidlo nebylo nalezeno.');
        }
        return $vehicle;
    }

    /** @return array<string,mixed> */
    private function requiredConnection(int $vehicleId): array
    {
        $connection = $this->repository->findConnectionForVehicle($vehicleId);
        if ($connection === null) {
            throw new RuntimeException('Vozidlo nemá aktivní OEM konektor.');
        }
        return $connection;
    }

    /** @param array<string,mixed> $schema @param array<string,mixed> $credentials */
    private function validateCredentials(array $schema, array $credentials): void
    {
        $fields = isset($schema['fields']) && is_array($schema['fields']) ? $schema['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }
            $name = (string)($field['name'] ?? '');
            if ($name === '' || trim((string)($credentials[$name] ?? '')) === '') {
                throw new RuntimeException('Vyplňte ' . (string)($field['label'] ?? $name) . '.');
            }
        }
    }

    /** @param array<string,mixed> $credentials */
    private function primarySecret(array $credentials): string
    {
        foreach (['api_key', 'access_token', 'refresh_token', 'password'] as $key) {
            if (isset($credentials[$key]) && is_string($credentials[$key]) && trim($credentials[$key]) !== '') {
                return trim($credentials[$key]);
            }
        }
        return '';
    }
}

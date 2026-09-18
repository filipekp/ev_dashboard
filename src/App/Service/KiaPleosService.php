<?php

declare(strict_types=1);

namespace App\Service;

use App\AuthService;
use App\Integration\Vehicle\Kia\KiaPleosApiClient;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleRepository;
use App\Security\CredentialCipher;
use App\Session;
use RuntimeException;

/**
 * Orchestrace Kia/Pleos Business User OAuth a consent flow.
 *
 * Client ID/Secret patří serveru EV Stats. Uživatel se přihlašuje přímo u
 * Kia/Pleos a EV Stats ukládá pouze šifrované access/refresh tokeny.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaPleosService
{
    /** @var AuthService */
    private $auth;

    /** @var VehicleRepository */
    private $vehicles;

    /** @var VehicleDataRepository */
    private $repository;

    /** @var CredentialCipher */
    private $cipher;

    /** @var VehicleSyncService */
    private $sync;

    /** @var Session */
    private $session;

    /** @var KiaPleosApiClient */
    private $client;

    /** @var string */
    private $appBaseUrl;

    /** @var string */
    private $loginRedirectUri;

    /** @var string */
    private $consentRedirectUri;

    /** @var string */
    private $sharingEndToken;

    /** @var string */
    private $language;

    public function __construct(
        AuthService $auth,
        VehicleRepository $vehicles,
        VehicleDataRepository $repository,
        CredentialCipher $cipher,
        VehicleSyncService $sync,
        Session $session,
        KiaPleosApiClient $client,
        string $appBaseUrl,
        string $loginRedirectUri,
        string $consentRedirectUri,
        string $sharingEndToken,
        string $language = 'cs'
    ) {
        $this->auth = $auth;
        $this->vehicles = $vehicles;
        $this->repository = $repository;
        $this->cipher = $cipher;
        $this->sync = $sync;
        $this->session = $session;
        $this->client = $client;
        $this->appBaseUrl = rtrim(trim($appBaseUrl), '/');
        $this->loginRedirectUri = trim($loginRedirectUri);
        $this->consentRedirectUri = trim($consentRedirectUri);
        $this->sharingEndToken = trim($sharingEndToken);
        $this->language = strtolower(trim($language));
    }

    /** @param array<string,mixed> $user */
    public function startLogin(array $user, int $vehicleId): string
    {
        $vehicle = $this->managedKia($user, $vehicleId);
        $redirectUri = $this->resolvedLoginRedirectUri();
        $state = bin2hex(random_bytes(32));

        $_SESSION['kia_pleos_oauth'] = [
            'state' => $state,
            'user_id' => (int)$user['id'],
            'vehicle_id' => (int)$vehicle['id'],
            'created_at' => time(),
        ];

        return $this->client->loginUrl($redirectUri, $state);
    }

    /**
     * Dokončí login, uloží tokeny do pending connection a vrátí Pleos WebView
     * URL pro výběr vozidla a udělení data-sharing souhlasu.
     *
     * @param array<string,mixed> $user
     */
    public function completeLogin(array $user, string $code, string $state): string
    {
        $context = isset($_SESSION['kia_pleos_oauth']) && is_array($_SESSION['kia_pleos_oauth'])
            ? $_SESSION['kia_pleos_oauth']
            : [];
        unset($_SESSION['kia_pleos_oauth']);

        if (!$this->validStateContext($context, $user, $state, 900)) {
            throw new RuntimeException('Kia přihlášení vypršelo nebo má neplatný bezpečnostní stav. Spusťte připojení znovu.');
        }

        $vehicleId = (int)$context['vehicle_id'];
        $vehicle = $this->managedKia($user, $vehicleId);
        $credentials = $this->client->exchangeAuthorizationCode($code, $this->resolvedLoginRedirectUri());
        $connection = $this->storePendingConnection($user, $vehicle, $credentials);

        $selectionState = bin2hex(random_bytes(32));
        $_SESSION['kia_pleos_consent'] = [
            'state' => $selectionState,
            'user_id' => (int)$user['id'],
            'vehicle_id' => $vehicleId,
            'connection_id' => (int)$connection['id'],
            'created_at' => time(),
        ];

        return $this->client->createVehicleSelection(
            (string)$credentials['access_token'],
            $selectionState,
            $this->language
        );
    }

    /**
     * Po návratu z Pleos consent WebView ověří skutečný consent přes
     * /v1/vehicles/consent a teprve poté spustí první synchronizaci.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $query
     */
    public function completeConsent(array $user, array $query): int
    {
        $context = isset($_SESSION['kia_pleos_consent']) && is_array($_SESSION['kia_pleos_consent'])
            ? $_SESSION['kia_pleos_consent']
            : [];
        unset($_SESSION['kia_pleos_consent']);

        if (!$this->validConsentContext($context, $user, $query)) {
            throw new RuntimeException('Kia souhlas se sdílením vypršel nebo nelze bezpečně ověřit. Připojte vozidlo znovu.');
        }

        $vehicleId = (int)$context['vehicle_id'];
        $connectionId = (int)$context['connection_id'];
        $vehicle = $this->managedKia($user, $vehicleId);
        $connection = $this->repository->findConnection($connectionId);
        if ((int)$connection['vehicle_id'] !== $vehicleId || (string)$connection['provider'] !== 'kia_pleos') {
            throw new RuntimeException('Kia OAuth vazba neodpovídá vybranému vozidlu.');
        }

        $credentials = $this->cipher->decrypt((string)$connection['credentials_encrypted']);
        $fresh = $this->client->ensureFreshCredentials($credentials);
        $credentials = $fresh['credentials'];
        if ($fresh['refreshed']) {
            $this->persistCredentials($connectionId, $credentials);
        }

        $consentedVehicles = $this->client->consentVehicles((string)$credentials['access_token']);
        $vin = strtoupper(trim((string)($vehicle['vin'] ?? '')));
        $consented = false;
        foreach ($consentedVehicles as $remoteVehicle) {
            if (strtoupper(trim((string)($remoteVehicle['vin'] ?? ''))) === $vin) {
                $consented = true;
                break;
            }
        }

        if (!$consented) {
            $this->repository->deleteConnection($connectionId);
            throw new RuntimeException('Pro VIN tohoto vozidla nebyl v Kia udělen souhlas se sdílením dat.');
        }

        $this->sync->syncConnection($connectionId, (int)$user['id'], 'initial');
        return $vehicleId;
    }

    /**
     * Callback Kia/Pleos při odvolání data-sharing souhlasu. Pleos vyžaduje,
     * aby klient všechna data získaná Vehicle Data API bezodkladně odstranil.
     *
     * @return int počet odstraněných propojení
     */
    public function handleSharingEnd(string $rawBody, string $providedToken): int
    {
        if ($this->sharingEndToken === '' || $providedToken === '' || !hash_equals($this->sharingEndToken, $providedToken)) {
            throw new RuntimeException('Neplatný Kia callback token.');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['vins']) || !is_array($payload['vins'])) {
            throw new RuntimeException('Kia callback neobsahuje platný seznam VIN.');
        }

        return $this->repository->revokeProviderConnectionsByVins('kia_pleos', $payload['vins']);
    }

    public function resolvedLoginRedirectUri(): string
    {
        if ($this->loginRedirectUri !== '') {
            return $this->loginRedirectUri;
        }
        if ($this->appBaseUrl === '') {
            throw new RuntimeException('Nastavte APP_BASE_URL nebo KIA_PLEOS_LOGIN_REDIRECT_URI.');
        }
        return $this->appBaseUrl . '/kia-oauth-callback.php';
    }

    public function resolvedConsentRedirectUri(): string
    {
        if ($this->consentRedirectUri !== '') {
            return $this->consentRedirectUri;
        }
        if ($this->appBaseUrl === '') {
            throw new RuntimeException('Nastavte APP_BASE_URL nebo KIA_PLEOS_CONSENT_REDIRECT_URI.');
        }
        return $this->appBaseUrl . '/kia-consent-callback.php';
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function managedKia(array $user, int $vehicleId): array
    {
        if ($vehicleId <= 0 || !$this->auth->canAccessVehicle($user, $vehicleId)) {
            throw new RuntimeException('Toto vozidlo nemůžete spravovat.');
        }
        $vehicle = $this->vehicles->find($vehicleId);
        if ($vehicle === null) {
            throw new RuntimeException('Vozidlo nebylo nalezeno.');
        }
        if (strtoupper(trim((string)($vehicle['manufacturer'] ?? ''))) !== 'KIA') {
            throw new RuntimeException('Kia/Pleos lze připojit pouze k vozidlu výrobce KIA.');
        }
        if (trim((string)($vehicle['vin'] ?? '')) === '') {
            throw new RuntimeException('Vozidlo musí mít vyplněné VIN.');
        }
        return $vehicle;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $user
     */
    private function validStateContext(array $context, array $user, string $state, int $maxAge): bool
    {
        $expected = trim((string)($context['state'] ?? ''));
        $createdAt = (int)($context['created_at'] ?? 0);
        return $expected !== ''
            && trim($state) !== ''
            && hash_equals($expected, trim($state))
            && (int)($context['user_id'] ?? 0) === (int)$user['id']
            && $createdAt > 0
            && $createdAt >= time() - $maxAge;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $user
     * @param array<string,mixed> $query
     */
    private function validConsentContext(array $context, array $user, array $query): bool
    {
        $createdAt = (int)($context['created_at'] ?? 0);
        if ((int)($context['user_id'] ?? 0) !== (int)$user['id'] || $createdAt <= 0 || $createdAt < time() - 1800) {
            return false;
        }

        // Pleos dokumentuje vehicles parametr; pokud vrátí i state, ověříme jej.
        $returnedState = trim((string)($query['state'] ?? ''));
        $expectedState = trim((string)($context['state'] ?? ''));
        if ($returnedState !== '' && ($expectedState === '' || !hash_equals($expectedState, $returnedState))) {
            return false;
        }

        return (int)($context['vehicle_id'] ?? 0) > 0 && (int)($context['connection_id'] ?? 0) > 0;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $vehicle
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    private function storePendingConnection(array $user, array $vehicle, array $credentials): array
    {
        $encrypted = $this->cipher->encrypt($credentials);
        $secret = trim((string)($credentials['refresh_token'] ?? $credentials['access_token'] ?? ''));
        $fingerprint = hash('sha256', $secret !== '' ? $secret : $encrypted);
        $vin = strtoupper(trim((string)$vehicle['vin']));
        $connection = $this->repository->upsertConnectorConnection(
            (int)$user['id'],
            (int)$vehicle['id'],
            'kia_pleos',
            $vin,
            $encrypted,
            $fingerprint,
            'OAuth / Kia'
        );
        $this->repository->updateConnectionMetadata((int)$connection['id'], [
            'external_vehicle_id' => $vin,
            'external_vin' => $vin,
            'external_name' => (string)($vehicle['name'] ?? 'Kia'),
            'credential_expires_at' => $credentials['access_token_expires_at'] ?? null,
            'metadata' => [
                'api' => 'Kia Europe Vehicle Data API / Pleos',
                'oauth' => true,
                'consent_pending' => true,
            ],
        ]);
        return $this->repository->findConnection((int)$connection['id']);
    }

    /** @param array<string,mixed> $credentials */
    private function persistCredentials(int $connectionId, array $credentials): void
    {
        $encrypted = $this->cipher->encrypt($credentials);
        $secret = trim((string)($credentials['refresh_token'] ?? $credentials['access_token'] ?? ''));
        $this->repository->updateConnectionCredentials(
            $connectionId,
            $encrypted,
            hash('sha256', $secret !== '' ? $secret : $encrypted),
            'OAuth / Kia',
            isset($credentials['access_token_expires_at']) ? (string)$credentials['access_token_expires_at'] : null
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Kia;

use App\Integration\Vehicle\VehicleConnectorException;

/**
 * HTTP klient pro evropské Kia Vehicle Data API provozované přes Pleos.
 *
 * Primárně podporuje Private User flow s uživatelským Client ID/Secret z
 * Pleos Playground. Legacy Business User OAuth metody zůstávají kvůli
 * kompatibilitě se staršími instalacemi EV Stats.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaPleosApiClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var int */
    private $timeoutSeconds;

    public function __construct(string $baseUrl, string $clientId, string $clientSecret, int $timeoutSeconds = 30)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->timeoutSeconds = max(5, min(120, $timeoutSeconds));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Vydá access token z My Vehicle API key Private Usera.
     * Client Secret se nikam neposílá kromě oficiálního Pleos endpointu.
     */
    public function createPersonalAccessToken(string $clientId, string $clientSecret): string
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            throw new VehicleConnectorException('Vyplňte Kia Pleos Client ID a Client Secret.', 0, true);
        }

        $response = $this->requestJson('POST', '/v1/auth/personal-token', null, [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ], false);
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $accessToken = trim((string)($data['accessToken'] ?? ''));
        if ($accessToken === '') {
            throw new VehicleConnectorException('Kia Pleos nevrátil access token pro My Vehicle API key.', 401, true);
        }

        return $accessToken;
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        $this->assertConfigured();
        $redirectUri = trim($redirectUri);
        $state = trim($state);
        if ($redirectUri === '' || $state === '') {
            throw new VehicleConnectorException('Kia Pleos OAuth redirect nebo state není nakonfigurován.', 0, true);
        }

        return $this->baseUrl . '/v1/auth/login?' . http_build_query([
            'clientId' => $this->clientId,
            'brand' => 'kia',
            'redirectUrl' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $this->assertConfigured();
        $code = trim($code);
        $redirectUri = trim($redirectUri);
        if ($code === '' || $redirectUri === '') {
            throw new VehicleConnectorException('Kia Pleos nevrátil platný autorizační kód.', 0, true);
        }

        $response = $this->requestJson('POST', '/v1/auth/token', null, [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'code' => $code,
            'redirectUrl' => $redirectUri,
        ], false);

        return $this->tokenCredentials($response, null);
    }

    /**
     * Pleos refresh token je platný 90 dní a je jednorázový. Po každém refreshi
     * proto musí EV Stats uložit nově vrácený refresh token.
     *
     * @return array<string,mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $this->assertConfigured();
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new VehicleConnectorException('Kia připojení nemá refresh token. Připojte Kia účet znovu.', 401, true);
        }

        $response = $this->requestJson('POST', '/v1/auth/token-refresh', null, [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'refreshToken' => $refreshToken,
        ], false);

        return $this->tokenCredentials($response, $refreshToken);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{credentials:array<string,mixed>,refreshed:bool}
     */
    public function ensureFreshCredentials(array $credentials): array
    {
        $accessToken = trim((string)($credentials['access_token'] ?? ''));
        $expiresAt = trim((string)($credentials['access_token_expires_at'] ?? ''));
        $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;

        if ($accessToken !== '' && $expiresTimestamp !== false && $expiresTimestamp > time() + 90) {
            return ['credentials' => $credentials, 'refreshed' => false];
        }

        $refreshed = $this->refreshAccessToken((string)($credentials['refresh_token'] ?? ''));
        return ['credentials' => $refreshed, 'refreshed' => true];
    }

    public function createVehicleSelection(string $accessToken, string $state, string $language = 'cs'): string
    {
        $response = $this->requestJson('POST', '/v1/vehicles/selections', $accessToken, [
            'state' => $state,
            'lang' => $this->sanitizeLanguage($language),
        ]);
        $redirectUrl = trim((string)($response['data']['redirectUrl'] ?? ''));
        if ($redirectUrl === '' || filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
            throw new VehicleConnectorException('Kia Pleos nevrátil URL pro výběr vozidla a souhlas se sdílením.', 0, true);
        }
        return $redirectUrl;
    }

    /** @return array<int,array<string,mixed>> */
    public function consentVehicles(string $accessToken): array
    {
        $response = $this->requestJson('GET', '/v1/vehicles/consent', $accessToken);
        $vehicles = $response['data']['vehicles'] ?? [];
        return is_array($vehicles) ? array_values(array_filter($vehicles, 'is_array')) : [];
    }

    /** @return array<string,mixed> */
    public function fetchVehicleTelemetry(string $vin, string $accessToken): array
    {
        $vin = strtoupper(trim($vin));
        if ($vin === '') {
            throw new VehicleConnectorException('Kia konektor vyžaduje VIN.', 0, true);
        }

        $payloads = [];
        $errors = [];
        $endpoints = [
            'battery' => '/v1/vehicles/' . rawurlencode($vin) . '/batteries',
            'location' => '/v1/vehicles/' . rawurlencode($vin) . '/locations',
            'driving' => '/v1/vehicles/' . rawurlencode($vin) . '/driving',
            'powertrain' => '/v1/vehicles/' . rawurlencode($vin) . '/powertrains',
            'status' => '/v1/vehicles/' . rawurlencode($vin) . '/status',
        ];

        foreach ($endpoints as $key => $path) {
            try {
                $payloads[$key] = $this->requestJson('GET', $path, $accessToken);
            } catch (VehicleConnectorException $e) {
                if (in_array($e->httpStatus(), [403, 404], true)) {
                    $errors[$key] = $e->getMessage();
                    continue;
                }
                throw $e;
            }
        }

        if ($payloads === []) {
            throw new VehicleConnectorException(
                'Kia Pleos pro toto vozidlo nevrátil žádná dostupná telemetrická data.',
                404,
                false,
                date('Y-m-d H:i:s', time() + 900),
                ['partial_errors' => $errors]
            );
        }

        return ['payloads' => $payloads, 'partial_errors' => $errors];
    }

    public function stopDataSharing(string $accessToken, string $vin): void
    {
        $vin = strtoupper(trim($vin));
        if ($vin === '') {
            return;
        }
        $this->requestJson('DELETE', '/v1/vehicles/consent?vin=' . rawurlencode($vin), $accessToken);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function requestJson(string $method, string $path, ?string $accessToken = null, ?array $body = null, bool $retryable = true): array
    {
        if ($this->baseUrl === '') {
            throw new VehicleConnectorException('Kia Pleos API URL není nakonfigurována.', 0, true);
        }
        if (!function_exists('curl_init')) {
            throw new VehicleConnectorException('PHP rozšíření cURL není dostupné.', 0, true);
        }

        $url = strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0
            ? $path
            : $this->baseUrl . '/' . ltrim($path, '/');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Brand: kia',
        ];
        if ($accessToken !== null && trim($accessToken) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($accessToken);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new VehicleConnectorException('Nepodařilo se inicializovat Kia Pleos HTTP klienta.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                curl_close($ch);
                throw new VehicleConnectorException('Nepodařilo se sestavit Kia Pleos API požadavek.');
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }
        curl_setopt_array($ch, $options);

        $rawBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transportError = (string)curl_error($ch);
        curl_close($ch);

        if ($rawBody === false || $transportError !== '') {
            throw new VehicleConnectorException(
                'Kia Pleos API není dostupné: ' . ($transportError !== '' ? $transportError : 'transportní chyba.'),
                0,
                false,
                date('Y-m-d H:i:s', time() + 300)
            );
        }

        $decoded = json_decode((string)$rawBody, true);
        if ($status < 200 || $status >= 300) {
            $this->throwApiError($status, is_array($decoded) ? $decoded : [], $retryable);
        }
        if (!is_array($decoded)) {
            throw new VehicleConnectorException('Kia Pleos API nevrátilo platný JSON.', $status);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function throwApiError(int $status, array $payload, bool $retryable): void
    {
        $error = isset($payload['error']) && is_array($payload['error']) ? $payload['error'] : [];
        $code = trim((string)($error['code'] ?? ''));
        $message = trim((string)($error['message'] ?? ''));
        $requestId = trim((string)($error['requestId'] ?? ''));
        $suffix = $requestId !== '' ? ' (requestId ' . $this->limit($requestId, 80) . ')' : '';

        if ($code === '4009' || $code === '4011' || $status === 401) {
            throw new VehicleConnectorException(
                'Kia autorizace vypršela nebo je neplatná. Připojte Kia účet znovu.' . $suffix,
                401,
                true
            );
        }
        if ($code === '5006' || $status === 410) {
            throw new VehicleConnectorException(
                'Kia již nemá platný souhlas se sdílením dat pro toto vozidlo. Připojte vozidlo znovu.' . $suffix,
                $status,
                true
            );
        }
        if ($code === '4045' || $status === 404) {
            throw new VehicleConnectorException(
                'Kia pro tento endpoint zatím nemá dostupná data.' . $suffix,
                404,
                false,
                date('Y-m-d H:i:s', time() + 900)
            );
        }
        if ($status >= 500) {
            throw new VehicleConnectorException(
                'Kia Pleos API má dočasnou chybu (HTTP ' . $status . ').' . $suffix,
                $status,
                false,
                $retryable ? date('Y-m-d H:i:s', time() + 600) : null
            );
        }

        $detail = $message !== '' ? ': ' . $this->limit($message, 300) : '';
        throw new VehicleConnectorException(
            'Kia Pleos API odmítlo požadavek (HTTP ' . $status . ($code !== '' ? ', kód ' . $code : '') . ')' . $detail . $suffix . '.',
            $status,
            $status >= 400 && $status < 500
        );
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function tokenCredentials(array $response, ?string $previousRefreshToken): array
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $accessToken = trim((string)($data['accessToken'] ?? ''));
        $refreshToken = trim((string)($data['refreshToken'] ?? ''));
        if ($accessToken === '' || $refreshToken === '') {
            throw new VehicleConnectorException('Kia Pleos nevrátil access/refresh token.', 0, true);
        }
        if ($previousRefreshToken !== null && hash_equals($previousRefreshToken, $refreshToken)) {
            throw new VehicleConnectorException('Kia Pleos při refreshi nevrátil nový jednorázový refresh token.', 0, true);
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_token_expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'refresh_token_expires_at' => date('Y-m-d H:i:s', time() + 90 * 86400),
            'brand' => 'kia',
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new VehicleConnectorException(
                'Kia Pleos konektor není nakonfigurován. Doplňte KIA_PLEOS_CLIENT_ID a KIA_PLEOS_CLIENT_SECRET.',
                0,
                true
            );
        }
    }

    private function sanitizeLanguage(string $language): string
    {
        $allowed = ['en', 'bg', 'bs', 'cnr', 'cs', 'da', 'de', 'el', 'en-gb', 'es', 'et', 'fi', 'fr', 'hr', 'hu', 'is', 'it', 'ka', 'lt', 'lv', 'mk', 'nl', 'no', 'pl', 'pt', 'ro', 'sk', 'sl', 'sq', 'sr', 'sv', 'tr', 'uk'];
        $language = strtolower(trim($language));
        return in_array($language, $allowed, true) ? $language : 'en';
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}

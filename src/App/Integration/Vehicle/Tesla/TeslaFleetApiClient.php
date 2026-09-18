<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Tesla;

use App\Integration\Vehicle\VehicleConnectorException;

/**
 * HTTP/OAuth klient oficiálního Tesla Fleet API.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class TeslaFleetApiClient
{
    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var string */
    private $authorizationUrl;

    /** @var string */
    private $tokenUrl;

    /** @var string */
    private $apiUrl;

    /** @var string */
    private $redirectUri;

    /** @var string */
    private $scopes;

    /** @var int */
    private $timeoutSeconds;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $authorizationUrl,
        string $tokenUrl,
        string $apiUrl,
        string $redirectUri,
        string $scopes,
        int $timeoutSeconds = 30
    ) {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->authorizationUrl = trim($authorizationUrl);
        $this->tokenUrl = trim($tokenUrl);
        $this->apiUrl = rtrim(trim($apiUrl), '/');
        $this->redirectUri = trim($redirectUri);
        $this->scopes = trim($scopes);
        $this->timeoutSeconds = max(5, min(120, $timeoutSeconds));
    }

    public function configured(): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->authorizationUrl !== ''
            && $this->tokenUrl !== ''
            && $this->apiUrl !== ''
            && $this->redirectUri !== '';
    }

    public function authorizationUrl(string $state, string $nonce): string
    {
        $this->assertConfigured();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scopes,
            'state' => $state,
            'nonce' => $nonce,
            'prompt_missing_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->authorizationUrl . (strpos($this->authorizationUrl, '?') === false ? '?' : '&') . $query;
    }

    /** @return array<string,mixed> */
    public function exchangeAuthorizationCode(string $code): array
    {
        $this->assertConfigured();
        $code = trim($code);
        if ($code === '') {
            throw new VehicleConnectorException('Tesla nevrátila autorizační kód.', 400, true);
        }

        return $this->normalizeTokenResponse($this->formRequest($this->tokenUrl, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'audience' => $this->apiUrl,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scopes,
        ], 'Tesla OAuth'));
    }

    /** @return array<string,mixed> */
    public function refreshAccessToken(string $refreshToken): array
    {
        $this->assertConfigured();
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new VehicleConnectorException(
                'Tesla refresh token chybí. Připojte Tesla účet znovu.',
                401,
                true
            );
        }

        return $this->normalizeTokenResponse($this->formRequest($this->tokenUrl, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'refresh_token' => $refreshToken,
        ], 'Tesla OAuth'));
    }

    /** @return array{payload:array<string,mixed>,headers:array<string,string>} */
    public function fetchVehicleData(string $vin, string $accessToken): array
    {
        $this->assertConfigured();
        $vin = strtoupper(trim($vin));
        $accessToken = trim($accessToken);
        if ($vin === '' || $accessToken === '') {
            throw new VehicleConnectorException('Tesla konektor vyžaduje VIN a access token.', 0, true);
        }

        return $this->jsonGet(
            $this->apiUrl . '/api/1/vehicles/' . rawurlencode($vin) . '/vehicle_data',
            $accessToken
        );
    }

    /** @return array<string,mixed> */
    private function normalizeTokenResponse(array $payload): array
    {
        $accessToken = trim((string)($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new VehicleConnectorException('Tesla OAuth nevrátil access token.', 401, true);
        }

        $expiresIn = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? max(60, (int)$payload['expires_in'])
            : 3600;

        return [
            'access_token' => $accessToken,
            'refresh_token' => trim((string)($payload['refresh_token'] ?? '')),
            'token_type' => trim((string)($payload['token_type'] ?? 'Bearer')),
            'scope' => trim((string)($payload['scope'] ?? $this->scopes)),
            'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn),
        ];
    }

    /** @param array<string,string> $fields @return array<string,mixed> */
    private function formRequest(string $url, array $fields, string $context): array
    {
        if (!function_exists('curl_init')) {
            throw new VehicleConnectorException('PHP rozšíření cURL není dostupné.', 0, true);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new VehicleConnectorException('Nepodařilo se inicializovat ' . $context . '.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new VehicleConnectorException(
                $context . ' není dostupné: ' . ($error !== '' ? $error : 'transportní chyba.'),
                0,
                false,
                date('Y-m-d H:i:s', time() + 300)
            );
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded)
                ? trim((string)($decoded['error_description'] ?? $decoded['error'] ?? ''))
                : '';
            $needsAttention = $status === 400 || $status === 401 || $status === 403;
            throw new VehicleConnectorException(
                $context . ' odmítlo požadavek (HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $this->limit($detail, 300) : '') . '.',
                $status,
                $needsAttention,
                $needsAttention ? null : date('Y-m-d H:i:s', time() + 600)
            );
        }

        if (!is_array($decoded)) {
            throw new VehicleConnectorException($context . ' nevrátilo platný JSON.', $status);
        }

        return $decoded;
    }

    /** @return array{payload:array<string,mixed>,headers:array<string,string>} */
    private function jsonGet(string $url, string $accessToken): array
    {
        if (!function_exists('curl_init')) {
            throw new VehicleConnectorException('PHP rozšíření cURL není dostupné.', 0, true);
        }

        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new VehicleConnectorException('Nepodařilo se inicializovat Tesla Fleet API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    return $length;
                }
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new VehicleConnectorException(
                'Tesla Fleet API není dostupné: ' . ($error !== '' ? $error : 'transportní chyba.'),
                0,
                false,
                date('Y-m-d H:i:s', time() + 300)
            );
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $this->throwApiError($status, is_array($decoded) ? $decoded : [], $headers);
        }
        if (!is_array($decoded)) {
            throw new VehicleConnectorException('Tesla Fleet API nevrátilo platný JSON.', $status);
        }

        return ['payload' => $decoded, 'headers' => $headers];
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    private function throwApiError(int $status, array $payload, array $headers): void
    {
        $detail = trim((string)($payload['error_description'] ?? $payload['error'] ?? ''));
        $retryAfterAt = $this->retryAfterAt($headers);

        if ($status === 401 || $status === 403) {
            throw new VehicleConnectorException(
                'Tesla přístup již není platný nebo nemá požadované oprávnění. Připojte Tesla účet znovu.',
                $status,
                true
            );
        }
        if ($status === 408 || $status === 421) {
            throw new VehicleConnectorException(
                'Tesla vozidlo nyní není dostupné pro živý dotaz. Synchronizace se zopakuje později.',
                $status,
                false,
                date('Y-m-d H:i:s', time() + 900)
            );
        }
        if ($status === 429) {
            throw new VehicleConnectorException(
                'Tesla Fleet API dočasně omezuje počet požadavků.',
                $status,
                false,
                $retryAfterAt ?: date('Y-m-d H:i:s', time() + 900)
            );
        }
        if ($status >= 500) {
            throw new VehicleConnectorException(
                'Tesla Fleet API má dočasnou chybu (HTTP ' . $status . ').',
                $status,
                false,
                $retryAfterAt ?: date('Y-m-d H:i:s', time() + 900)
            );
        }

        throw new VehicleConnectorException(
            'Tesla Fleet API odmítlo požadavek (HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $this->limit($detail, 300) : '') . '.',
            $status,
            $status >= 400 && $status < 500,
            $retryAfterAt
        );
    }

    /** @param array<string,string> $headers */
    private function retryAfterAt(array $headers): ?string
    {
        $value = trim((string)($headers['retry-after'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return date('Y-m-d H:i:s', time() + (int)$value);
        }
        $time = strtotime($value);
        return $time !== false ? date('Y-m-d H:i:s', $time) : null;
    }

    private function assertConfigured(): void
    {
        if (!$this->configured()) {
            throw new VehicleConnectorException(
                'Tesla Fleet API není nakonfigurováno. Doplňte TESLA_CLIENT_ID, TESLA_CLIENT_SECRET a APP_BASE_URL.',
                0,
                true
            );
        }
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}

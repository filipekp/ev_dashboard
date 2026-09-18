<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Kia;

use App\Integration\Vehicle\VehicleConnectorException;

/**
 * OAuth/HTTP klient pro oficiální Kia Vehicle Data API.
 *
 * Konkrétní evropské endpointy jsou zpřístupněny po schválení přístupu v Kia
 * Developer Portalu, proto jsou URL konfigurovatelné přes .env.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class KiaVehicleDataApiClient
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
    private $vehicleDataUrlTemplate;

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
        string $vehicleDataUrlTemplate,
        string $redirectUri,
        string $scopes,
        int $timeoutSeconds = 30
    ) {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->authorizationUrl = trim($authorizationUrl);
        $this->tokenUrl = trim($tokenUrl);
        $this->vehicleDataUrlTemplate = trim($vehicleDataUrlTemplate);
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
            && $this->vehicleDataUrlTemplate !== ''
            && (strpos($this->vehicleDataUrlTemplate, '{vin}') !== false
                || strpos($this->vehicleDataUrlTemplate, '{VIN}') !== false)
            && $this->redirectUri !== '';
    }

    public function authorizationUrl(string $state, string $nonce): string
    {
        $this->assertConfigured();
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'nonce' => $nonce,
        ];
        if ($this->scopes !== '') {
            $params['scope'] = $this->scopes;
        }

        return $this->authorizationUrl
            . (strpos($this->authorizationUrl, '?') === false ? '?' : '&')
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    public function exchangeAuthorizationCode(string $code): array
    {
        $this->assertConfigured();
        $fields = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => trim($code),
            'redirect_uri' => $this->redirectUri,
        ];
        if ($this->scopes !== '') {
            $fields['scope'] = $this->scopes;
        }
        return $this->normalizeTokenResponse($this->formRequest($this->tokenUrl, $fields, 'Kia OAuth'));
    }

    /** @return array<string,mixed> */
    public function refreshAccessToken(string $refreshToken): array
    {
        $this->assertConfigured();
        if (trim($refreshToken) === '') {
            throw new VehicleConnectorException('Kia refresh token chybí. Připojte Kia účet znovu.', 401, true);
        }
        $fields = [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => trim($refreshToken),
        ];
        if ($this->scopes !== '') {
            $fields['scope'] = $this->scopes;
        }
        return $this->normalizeTokenResponse($this->formRequest($this->tokenUrl, $fields, 'Kia OAuth'));
    }

    /** @return array{payload:array<string,mixed>,headers:array<string,string>} */
    public function fetchVehicleData(string $vin, string $accessToken): array
    {
        $this->assertConfigured();
        $vin = strtoupper(trim($vin));
        if ($vin === ''
            || (strpos($this->vehicleDataUrlTemplate, '{vin}') === false
                && strpos($this->vehicleDataUrlTemplate, '{VIN}') === false)
        ) {
            throw new VehicleConnectorException('Kia Vehicle Data API URL template musí obsahovat {vin}.', 0, true);
        }
        $url = str_replace(['{vin}', '{VIN}'], rawurlencode($vin), $this->vehicleDataUrlTemplate);
        return $this->jsonGet($url, $accessToken);
    }

    /** @return array<string,mixed> */
    private function normalizeTokenResponse(array $payload): array
    {
        $accessToken = trim((string)($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new VehicleConnectorException('Kia OAuth nevrátil access token.', 401, true);
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
            $message = is_array($decoded)
                ? trim((string)($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? ''))
                : '';
            throw new VehicleConnectorException(
                $context . ' odmítlo požadavek (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $this->limit($message, 300) : '') . '.',
                $status,
                in_array($status, [400, 401, 403], true),
                $status >= 500 || $status === 429 ? date('Y-m-d H:i:s', time() + 600) : null
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
            throw new VehicleConnectorException('Nepodařilo se inicializovat Kia Vehicle Data API.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . trim($accessToken),
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
                'Kia Vehicle Data API není dostupné: ' . ($error !== '' ? $error : 'transportní chyba.'),
                0,
                false,
                date('Y-m-d H:i:s', time() + 300)
            );
        }
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? trim((string)($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? ''))
                : '';
            if ($status === 401 || $status === 403) {
                throw new VehicleConnectorException('Kia autorizace již není platná. Připojte Kia účet znovu.', $status, true);
            }
            throw new VehicleConnectorException(
                'Kia Vehicle Data API odmítlo požadavek (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $this->limit($message, 300) : '') . '.',
                $status,
                false,
                date('Y-m-d H:i:s', time() + ($status === 429 ? 900 : 600))
            );
        }
        if (!is_array($decoded)) {
            throw new VehicleConnectorException('Kia Vehicle Data API nevrátilo platný JSON.', $status);
        }
        return ['payload' => $decoded, 'headers' => $headers];
    }

    private function assertConfigured(): void
    {
        if (!$this->configured()) {
            throw new VehicleConnectorException(
                'Kia Vehicle Data API není nakonfigurováno. Přístup vyžaduje schválený Kia Developer účet a endpointy z Developer Portalu.',
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

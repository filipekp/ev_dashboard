<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Vag;

use App\Integration\Vehicle\VehicleConnectorException;

/**
 * Klient oficiálního Volkswagen Group Info Services EU Data Act API.
 *
 * Data Hub vyžaduje marketplace API key a OAuth bearer token získaný přes
 * ONE Business ID Client Credentials flow. Client ID/Secret patří provozovateli
 * EV Stats a zůstávají pouze v .env; marketplace API key je credential
 * konkrétního Data Hub subscription a ukládá se šifrovaně ke spojení vozidla.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class VagDataHubClient
{
    /** @var string */
    private $vehicleDataUrlTemplate;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var string */
    private $tokenUrl;

    /** @var string */
    private $tokenScope;

    /** @var int */
    private $timeoutSeconds;

    /** @var string|null */
    private $accessToken;

    /** @var int */
    private $accessTokenExpiresAt = 0;

    public function __construct(
        string $vehicleDataUrlTemplate,
        string $clientId,
        string $clientSecret,
        string $tokenUrl,
        string $tokenScope = 'audience_marketplace-portal',
        int $timeoutSeconds = 30
    ) {
        $this->vehicleDataUrlTemplate = trim($vehicleDataUrlTemplate);
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->tokenUrl = trim($tokenUrl);
        $this->tokenScope = trim($tokenScope);
        $this->timeoutSeconds = max(5, min(120, $timeoutSeconds));
    }

    public function configured(): bool
    {
        return $this->vehicleDataUrlTemplate !== ''
            && (strpos($this->vehicleDataUrlTemplate, '{vin}') !== false
                || strpos($this->vehicleDataUrlTemplate, '{VIN}') !== false)
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->tokenUrl !== '';
    }

    /** @return array{payload:array<string,mixed>,headers:array<string,string>} */
    public function fetchVehicle(string $vin, string $apiKey): array
    {
        if (!$this->configured()) {
            throw new VehicleConnectorException(
                'Volkswagen Group Data Hub není nakonfigurován. Doplňte URL produktu a ONE Business ID Client ID/Secret.',
                0,
                true
            );
        }
        if (!function_exists('curl_init')) {
            throw new VehicleConnectorException('PHP rozšíření cURL není dostupné.', 0, true);
        }

        $vin = strtoupper(trim($vin));
        $apiKey = trim($apiKey);
        if ($vin === '' || $apiKey === '') {
            throw new VehicleConnectorException('VAG Data Hub konektor vyžaduje VIN a Marketplace API key.', 0, true);
        }

        $url = str_replace(['{vin}', '{VIN}'], rawurlencode($vin), $this->vehicleDataUrlTemplate);
        $accessToken = $this->accessToken();

        try {
            return $this->jsonGet($url, $apiKey, $accessToken);
        } catch (VehicleConnectorException $e) {
            // Access token je krátkodobý. Při 401 jej jednou zahodíme a získáme nový.
            if ($e->httpStatus() !== 401) {
                throw $e;
            }

            $this->accessToken = null;
            $this->accessTokenExpiresAt = 0;
            return $this->jsonGet($url, $apiKey, $this->accessToken());
        }
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessTokenExpiresAt > time() + 30) {
            return $this->accessToken;
        }

        if (!function_exists('curl_init')) {
            throw new VehicleConnectorException('PHP rozšíření cURL není dostupné.', 0, true);
        }

        $fields = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        if ($this->tokenScope !== '') {
            $fields['scope'] = $this->tokenScope;
        }

        $ch = curl_init($this->tokenUrl);
        if ($ch === false) {
            throw new VehicleConnectorException('Nepodařilo se inicializovat ONE Business ID OAuth klienta.');
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
                'ONE Business ID OAuth není dostupné: ' . ($error !== '' ? $error : 'transportní chyba.'),
                0,
                false,
                date('Y-m-d H:i:s', time() + 600)
            );
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $detail = is_array($decoded)
                ? trim((string)($decoded['error_description'] ?? $decoded['error'] ?? ''))
                : '';
            throw new VehicleConnectorException(
                'ONE Business ID OAuth odmítlo Client Credentials (HTTP ' . $status . ')'
                . ($detail !== '' ? ': ' . $this->limit($detail, 300) : '') . '.',
                $status,
                in_array($status, [400, 401, 403], true),
                $status >= 500 || $status === 429 ? date('Y-m-d H:i:s', time() + 600) : null
            );
        }

        $token = trim((string)($decoded['access_token'] ?? ''));
        if ($token === '') {
            throw new VehicleConnectorException('ONE Business ID OAuth nevrátil access token.', $status, true);
        }

        $expiresIn = isset($decoded['expires_in']) && is_numeric($decoded['expires_in'])
            ? max(60, (int)$decoded['expires_in'])
            : 300;

        $this->accessToken = $token;
        $this->accessTokenExpiresAt = time() + $expiresIn;

        return $token;
    }

    /** @return array{payload:array<string,mixed>,headers:array<string,string>} */
    private function jsonGet(string $url, string $apiKey, string $accessToken): array
    {
        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new VehicleConnectorException('Nepodařilo se inicializovat Volkswagen Group Data Hub klienta.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-marketplace-api-key: ' . $apiKey,
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
                'Volkswagen Group Data Hub není dostupný: ' . ($error !== '' ? $error : 'transportní chyba.'),
                0,
                false,
                date('Y-m-d H:i:s', time() + 600)
            );
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded)
                ? trim((string)($decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? ''))
                : '';
            if ($status === 401) {
                throw new VehicleConnectorException(
                    'Volkswagen Group Data Hub bearer token není platný.',
                    $status,
                    false
                );
            }
            if ($status === 403) {
                throw new VehicleConnectorException(
                    'Volkswagen Group Data Hub API key není platný nebo vozidlo nemá aktivní consent/subscription.',
                    $status,
                    true
                );
            }
            if ($status === 404) {
                throw new VehicleConnectorException(
                    'VIN nebylo nalezeno v aktivním Volkswagen Group Data Hub subscription.',
                    $status,
                    true
                );
            }
            throw new VehicleConnectorException(
                'Volkswagen Group Data Hub odmítl požadavek (HTTP ' . $status . ')'
                . ($detail !== '' ? ': ' . $this->limit($detail, 300) : '') . '.',
                $status,
                false,
                date('Y-m-d H:i:s', time() + ($status === 429 ? 900 : 600))
            );
        }
        if (!is_array($decoded)) {
            throw new VehicleConnectorException('Volkswagen Group Data Hub nevrátil platný JSON.', $status);
        }

        return ['payload' => $decoded, 'headers' => $headers];
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}

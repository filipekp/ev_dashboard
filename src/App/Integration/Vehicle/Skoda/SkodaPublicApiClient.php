<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Skoda;

use App\Integration\Vehicle\VehicleConnectorException;

/**
 * HTTP klient oficiálního MyŠkoda Public API.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class SkodaPublicApiClient
{
    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeoutSeconds;

    public function __construct(string $baseUrl, int $timeoutSeconds = 30)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->timeoutSeconds = max(5, min(120, $timeoutSeconds));
    }

    /**
     * @return array{payload:array<string,mixed>,headers:array<string,string>}
     */
    public function fetchVehicle(string $vin, string $apiKey): array
    {
        $vin = strtoupper(trim($vin));
        $apiKey = trim($apiKey);
        if ($vin === '' || $apiKey === '') {
            throw new VehicleConnectorException('MyŠkoda konektor vyžaduje VIN a API klíč.', 0, true);
        }
        if ($this->baseUrl === '') {
            throw new VehicleConnectorException('MyŠkoda API URL není nakonfigurována.', 0, true);
        }
        if (!function_exists('curl_init')) {
            throw new VehicleConnectorException('PHP rozšíření cURL není dostupné.', 0, true);
        }

        $url = $this->baseUrl . '/api/v1/vehicles/' . rawurlencode($vin);
        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new VehicleConnectorException('Nepodařilo se inicializovat HTTP klienta MyŠkoda.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, application/problem+json',
                'X-API-Key: ' . $apiKey,
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    return $length;
                }
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transportError = (string)curl_error($ch);
        curl_close($ch);

        if ($body === false || $transportError !== '') {
            throw new VehicleConnectorException(
                'MyŠkoda API není dostupné: ' . ($transportError !== '' ? $transportError : 'transportní chyba.'),
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
            throw new VehicleConnectorException('MyŠkoda API nevrátilo platný JSON.', $status);
        }

        return ['payload' => $decoded, 'headers' => $headers];
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    private function throwApiError(int $status, array $payload, array $headers): void
    {
        $type = strtolower((string)($payload['type'] ?? ''));
        $detail = trim((string)($payload['detail'] ?? $payload['title'] ?? ''));
        $retryAfterAt = $this->retryAfterAt($headers);
        $metadata = $this->headerMetadata($headers);

        if ($status === 401 || strpos($type, 'api-key-expired') !== false) {
            throw new VehicleConnectorException(
                'MyŠkoda API klíč je neplatný nebo vypršel. Vytvořte/obnovte jej v aplikaci MyŠkoda.',
                $status,
                true,
                null,
                $metadata
            );
        }

        if ($status === 403 || strpos($type, 'api-key-not-authorized') !== false) {
            throw new VehicleConnectorException(
                'MyŠkoda API klíč nemá oprávnění k tomuto VIN. Při tvorbě klíče v MyŠkoda vyberte správné vozidlo.',
                $status,
                true,
                null,
                $metadata
            );
        }

        if ($status === 429) {
            $message = strpos($type, 'vehicle-not-accepting-requests') !== false
                ? 'Vozidlo nyní nepřijímá požadavky MyŠkoda. EV Stats synchronizaci zopakuje později.'
                : 'Byl vyčerpán limit MyŠkoda API. EV Stats počká do obnovení kvóty.';
            throw new VehicleConnectorException($message, $status, false, $retryAfterAt, $metadata);
        }

        if ($status >= 500) {
            throw new VehicleConnectorException(
                'MyŠkoda API má dočasnou chybu (HTTP ' . $status . '). Synchronizace bude zopakována.',
                $status,
                false,
                $retryAfterAt ?: date('Y-m-d H:i:s', time() + 600),
                $metadata
            );
        }

        $message = 'MyŠkoda API odmítlo požadavek (HTTP ' . $status . ')';
        if ($detail !== '') {
            $message .= ': ' . $this->limit($detail, 500);
        }
        throw new VehicleConnectorException($message . '.', $status, $status >= 400 && $status < 500, $retryAfterAt, $metadata);
    }

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function headerMetadata(array $headers): array
    {
        $resetSeconds = isset($headers['ratelimit-reset']) && is_numeric($headers['ratelimit-reset'])
            ? max(0, (int)$headers['ratelimit-reset'])
            : null;

        return [
            'credential_expires_at' => $this->dateHeader($headers['x-api-key-expires-at'] ?? null),
            'rate_limit_limit' => isset($headers['ratelimit-limit']) && is_numeric($headers['ratelimit-limit'])
                ? (int)$headers['ratelimit-limit']
                : null,
            'rate_limit_remaining' => isset($headers['ratelimit-remaining']) && is_numeric($headers['ratelimit-remaining'])
                ? (int)$headers['ratelimit-remaining']
                : null,
            'rate_limit_reset_at' => $resetSeconds !== null
                ? date('Y-m-d H:i:s', time() + $resetSeconds)
                : null,
            'retry_after_at' => $this->retryAfterAt($headers),
        ];
    }

    /** @param array<string,string> $headers */
    private function retryAfterAt(array $headers): ?string
    {
        $value = trim((string)($headers['retry-after'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return date('Y-m-d H:i:s', time() + max(0, (int)$value));
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function dateHeader(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}

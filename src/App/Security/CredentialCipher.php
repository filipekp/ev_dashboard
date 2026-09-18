<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Šifruje citlivé credentials OEM konektorů před uložením do databáze.
 *
 * Preferuje libsodium secretbox. Pokud není sodium dostupné, používá
 * AES-256-GCM z OpenSSL. Master key zůstává pouze v .env.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class CredentialCipher
{
    /** @var string */
    private $key;

    public function __construct(string $configuredKey)
    {
        $this->key = $this->decodeKey($configuredKey);
    }

    public static function isConfigured(string $configuredKey): bool
    {
        try {
            new self($configuredKey);
            return true;
        } catch (RuntimeException $e) {
            var_dump($e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $credentials */
    public function encrypt(array $credentials): string
    {
        $json = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Credentials nelze serializovat.');
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($json, $nonce, $this->key);
            return 'sodium:v1:' . base64_encode($nonce . $ciphertext);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $json,
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );
            if ($ciphertext === false || strlen($tag) !== 16) {
                throw new RuntimeException('Credentials se nepodařilo zašifrovat přes OpenSSL.');
            }
            return 'openssl:v1:' . base64_encode($iv . $tag . $ciphertext);
        }

        throw new RuntimeException('Server nemá dostupné libsodium ani OpenSSL pro bezpečné uložení credentials.');
    }

    /** @return array<string,mixed> */
    public function decrypt(string $value): array
    {
        if (strpos($value, 'sodium:v1:') === 0) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('Credentials byly zašifrovány přes libsodium, ale rozšíření nyní není dostupné.');
            }
            $raw = base64_decode(substr($value, strlen('sodium:v1:')), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('Uložená credentials mají neplatný formát.');
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $json = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
            if ($json === false) {
                throw new RuntimeException('Credentials nelze dešifrovat. Zkontrolujte VEHICLE_CREDENTIALS_KEY.');
            }
            return $this->decodeJson($json);
        }

        if (strpos($value, 'openssl:v1:') === 0) {
            if (!function_exists('openssl_decrypt')) {
                throw new RuntimeException('Credentials byly zašifrovány přes OpenSSL, ale rozšíření nyní není dostupné.');
            }
            $raw = base64_decode(substr($value, strlen('openssl:v1:')), true);
            if ($raw === false || strlen($raw) <= 28) {
                throw new RuntimeException('Uložená credentials mají neplatný formát.');
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ciphertext = substr($raw, 28);
            $json = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($json === false) {
                throw new RuntimeException('Credentials nelze dešifrovat. Zkontrolujte VEHICLE_CREDENTIALS_KEY.');
            }
            return $this->decodeJson($json);
        }

        throw new RuntimeException('Uložená credentials mají neznámou verzi šifrování.');
    }

    private function decodeKey(string $configuredKey): string
    {
        $configuredKey = trim($configuredKey);
        if ($configuredKey === '') {
            throw new RuntimeException('Pro OEM konektory nastavte VEHICLE_CREDENTIALS_KEY v .env.');
        }

        $decoded = base64_decode($configuredKey, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $configuredKey) === 1) {
            $decodedHex = hex2bin($configuredKey);
            if ($decodedHex !== false && strlen($decodedHex) === 32) {
                return $decodedHex;
            }
        }

        throw new RuntimeException('VEHICLE_CREDENTIALS_KEY musí být Base64 nebo HEX reprezentace přesně 32 náhodných bajtů.');
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Dešifrovaná credentials nejsou platný JSON objekt.');
        }
        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace App\Document\Ai;

use RuntimeException;

/** Google Gemini API adaptér pro vytěžování obrázků a PDF dokladů. */
final class GeminiDocumentExtractor implements AiDocumentExtractorInterface
{
    /** @var string */ private $apiKey;
    /** @var string */ private $model;

    public function __construct(string $apiKey, string $model = 'gemini-3.8-flash')
    {
        $this->apiKey = trim($apiKey);
        $this->model = trim($model) ?: 'gemini-3.8-flash';
    }

    public function name(): string
    {
        return 'gemini:' . $this->model;
    }

    /** @return array<string,mixed> */
    public function extract(string $path, string $mimeType, string $originalName): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY není nastaven.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP rozšíření cURL není dostupné.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Dokument nelze načíst.');
        }

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => DocumentExtractionSchema::prompt()],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($raw)]],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => DocumentExtractionSchema::jsonSchema(),
            ],
        ];

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Gemini API chyba (' . $status . '): ' . ($error ?: (string)$body));
        }
        $response = json_decode((string)$body, true);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $data = json_decode((string)$text, true);
        if (!is_array($data)) {
            throw new RuntimeException('Gemini nevrátil platný strukturovaný JSON.');
        }
        return $data;
    }
}

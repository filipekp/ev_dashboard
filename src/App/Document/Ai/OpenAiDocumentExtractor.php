<?php

declare(strict_types=1);

namespace App\Document\Ai;

use RuntimeException;

/** OpenAI Responses API adaptér pro multimodální vytěžování dokladů. */
final class OpenAiDocumentExtractor implements AiDocumentExtractorInterface
{
    /** @var string */ private $apiKey;
    /** @var string */ private $model;
    /** @var string */ private $endpoint;

    public function __construct(string $apiKey, string $model = 'gpt-5.6-luna', string $endpoint = 'https://api.openai.com/v1/responses')
    {
        $this->apiKey = trim($apiKey);
        $this->model = trim($model) ?: 'gpt-5.6-luna';
        $this->endpoint = $endpoint;
    }

    public function name(): string
    {
        return 'openai:' . $this->model;
    }

    /** @return array<string,mixed> */
    public function extract(string $path, string $mimeType, string $originalName): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY není nastaven.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP rozšíření cURL není dostupné.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Dokument nelze načíst.');
        }
        $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($raw);
        $isImage = strpos($mimeType, 'image/') === 0;
        $filePart = $isImage
            ? ['type' => 'input_image', 'image_url' => $dataUri]
            : ['type' => 'input_file', 'filename' => $originalName, 'file_data' => $dataUri];

        $payload = [
            'model' => $this->model,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => DocumentExtractionSchema::prompt()],
                    $filePart,
                ],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'vehicle_document',
                    'strict' => true,
                    'schema' => DocumentExtractionSchema::jsonSchema(),
                ],
            ],
        ];

        $response = $this->request($payload);
        $text = '';
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    $text .= (string)$content['text'];
                }
            }
        }
        if ($text === '') {
            throw new RuntimeException('OpenAI nevrátil strukturovaný výstup.');
        }

        return $this->decode($text);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function request(array $payload): array
    {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('OpenAI API chyba (' . $status . '): ' . ($error ?: $this->apiError((string)$body)));
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI API vrátil neplatnou odpověď.');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAI vrátil neplatný JSON.');
        }
        return $data;
    }

    private function apiError(string $body): string
    {
        $data = json_decode($body, true);
        return is_array($data) && isset($data['error']['message']) ? (string)$data['error']['message'] : 'neznámá chyba';
    }
}

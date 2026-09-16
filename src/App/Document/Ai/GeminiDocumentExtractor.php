<?php

    declare(strict_types=1);

    namespace App\Document\Ai;

    use RuntimeException;

    /**
     * Google Gemini API adaptér pro vytěžování obrázků a PDF dokladů.
     *
     * Pokud je primární model dočasně nedostupný, automaticky zkusí další
     * kompatibilní modely v pořadí definovaném v $fallbackModels.
     */
    final class GeminiDocumentExtractor implements AiDocumentExtractorInterface
    {
        private const DEFAULT_MODEL = 'gemini-3.8-flash';

        /** @var string[] */
        private const DEFAULT_FALLBACK_MODELS = [
            'gemini-3.7-flash',
            'gemini-3.6-flash',
            'gemini-3.5-flash',
        ];

        /**
         * HTTP stavy, u kterých dává smysl zkusit jiný model.
         *
         * 0   = transportní/cURL chyba bez HTTP odpovědi
         * 404 = konkrétní model nemusí být pro projekt/region dostupný
         * 408 = timeout
         * 429 = rate limit / dočasná kapacita
         * 5xx = chyba na straně služby
         *
         * @var int[]
         */
        private const FALLBACK_HTTP_STATUSES = [
            0,
            404,
            408,
            429,
            500,
            502,
            503,
            504
        ];

        /** @var string */
        private $apiKey;

        /** @var string */
        private $model;

        /** @var string[] */
        private $fallbackModels;

        /** @var string|null */
        private $lastUsedModel;

        /**
         * @param string[] $fallbackModels
         */
        public function __construct(string $apiKey, string $model = self::DEFAULT_MODEL, array $fallbackModels = self::DEFAULT_FALLBACK_MODELS) {
            $this->apiKey         = trim($apiKey);
            $this->model          = trim($model) ?: self::DEFAULT_MODEL;
            $this->fallbackModels = $this->normalizeFallbackModels($fallbackModels);
            $this->lastUsedModel  = NULL;
        }

        public function name(): string {
            return 'gemini:' . ($this->lastUsedModel ?: $this->model);
        }

        /** @return array<string,mixed> */
        public function extract(string $path, string $mimeType, string $originalName): array {
            if ($this->apiKey === '') {
                throw new RuntimeException('GEMINI_API_KEY není nastaven.');
            }

            if (!function_exists('curl_init')) {
                throw new RuntimeException('PHP rozšíření cURL není dostupné.');
            }

            $raw = file_get_contents($path);
            if ($raw === FALSE) {
                throw new RuntimeException('Dokument nelze načíst.');
            }

            $payload = [
                'contents'         => [
                    [
                        'parts' => [
                            ['text' => DocumentExtractionSchema::prompt()],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data'      => base64_encode($raw),
                                ],
                            ],
                        ],
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType'   => 'application/json',
                    'responseJsonSchema' => DocumentExtractionSchema::jsonSchema(),
                ],
            ];

            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payloadJson === FALSE) {
                throw new RuntimeException('Nepodařilo se sestavit požadavek pro Gemini API.');
            }

            $errors = [];
            $models = array_merge([$this->model], $this->fallbackModels);

            foreach ($models as $model) {
                [
                    $status,
                    $body,
                    $curlError
                ] = $this->request($model, $payloadJson);

                if ($body !== FALSE && $status >= 200 && $status < 300) {
                    try {
                        $data                = $this->parseResponse((string)$body);
                        $this->lastUsedModel = $model;

                        return $data;
                    } catch (RuntimeException $e) {
                        // Model odpověděl, ale odpověď nebyla použitelná. U dokumentového
                        // vytěžování je bezpečnější zkusit další model než celý import shodit.
                        $errors[] = $model . ': ' . $e->getMessage();
                        continue;
                    }
                }

                $message = $curlError !== '' ? $curlError : $this->extractApiErrorMessage((string)$body);

                $errors[] = sprintf('%s: HTTP %d - %s', $model, $status, $message);

                if (!$this->shouldTryFallback($status)) {
                    throw new RuntimeException('Gemini API chyba (' . $status . ') na modelu ' . $model . ': ' . $message);
                }
            }

            throw new RuntimeException('Gemini API je momentálně nedostupné pro všechny nakonfigurované modely. ' . 'Pokusy: ' . implode(' | ', $errors));
        }

        /**
         * @return array{0:int,1:string|false,2:string}
         */
        private function request(string $model, string $payloadJson): array {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);

            $ch = curl_init($endpoint);
            if ($ch === FALSE) {
                return [
                    0,
                    FALSE,
                    'Nepodařilo se inicializovat cURL.'
                ];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => TRUE,
                CURLOPT_RETURNTRANSFER => TRUE,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS     => $payloadJson,
            ]);

            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = (string)curl_error($ch);
            curl_close($ch);

            return [
                $status,
                $body,
                $error
            ];
        }

        /** @return array<string,mixed> */
        private function parseResponse(string $body): array {
            $response = json_decode($body, TRUE);
            if (!is_array($response)) {
                throw new RuntimeException('Gemini nevrátil platnou API odpověď v JSON.');
            }

            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            if (!is_array($parts)) {
                throw new RuntimeException('Gemini odpověď neobsahuje žádná data.');
            }

            $text = '';
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }

            $text = trim($text);
            if ($text === '') {
                throw new RuntimeException('Gemini nevrátil žádný strukturovaný výstup.');
            }

            // Ochrana pro případ, že model i přes responseMimeType obalí JSON do markdown fence.
            if (strpos($text, '```json') === 0) {
                $text = substr($text, 7);
            } elseif (strpos($text, '```') === 0) {
                $text = substr($text, 3);
            }

            $text = trim($text);
            if (substr($text, -3) === '```') {
                $text = trim(substr($text, 0, -3));
            }

            $data = json_decode($text, TRUE);
            if (!is_array($data)) {
                throw new RuntimeException('Gemini nevrátil platný strukturovaný JSON.');
            }

            return $data;
        }

        private function shouldTryFallback(int $status): bool {
            return in_array($status, self::FALLBACK_HTTP_STATUSES, TRUE);
        }

        private function extractApiErrorMessage(string $body): string {
            if ($body === '') {
                return 'Prázdná odpověď API.';
            }

            $decoded = json_decode($body, TRUE);
            if (is_array($decoded)) {
                $message = $decoded['error']['message'] ?? NULL;
                if (is_string($message) && trim($message) !== '') {
                    return $this->truncate(trim($message));
                }
            }

            return $this->truncate(trim($body));
        }

        private function truncate(string $value, int $maxLength = 700): string {
            if (strlen($value) <= $maxLength) {
                return $value;
            }

            return substr($value, 0, $maxLength) . '…';
        }

        /**
         * @param string[] $fallbackModels
         *
         * @return string[]
         */
        private function normalizeFallbackModels(array $fallbackModels): array {
            $normalized = [];

            foreach ($fallbackModels as $model) {
                if (!is_string($model)) {
                    continue;
                }

                $model = trim($model);
                if ($model === '' || $model === $this->model) {
                    continue;
                }

                $normalized[$model] = $model;
            }

            return array_values($normalized);
        }
    }

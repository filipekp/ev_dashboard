<?php

declare(strict_types=1);

namespace App\Document\Parser;

use RuntimeException;

/** Deterministický parser normalizovaných JSON dokladů pro integrace a testy. */
final class JsonDocumentParser implements DocumentParserInterface
{
    public function name(): string { return 'json'; }

    public function supports(string $path, string $mimeType, string $originalName): bool
    {
        return strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) === 'json'
            || $mimeType === 'application/json';
    }

    /** @return array<string,mixed> */
    public function parse(string $path, string $mimeType, string $originalName): array
    {
        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON dokument nemá platný formát.');
        }
        return $data;
    }
}

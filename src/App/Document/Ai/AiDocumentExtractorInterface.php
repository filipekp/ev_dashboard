<?php

declare(strict_types=1);

namespace App\Document\Ai;

/**
 * Rozhraní AI extraktoru dokladů.
 *
 * Implementace musí vracet normalizovaný dokumentový model nezávislý na
 * konkrétním poskytovateli AI. Díky tomu lze OpenAI/Gemini/lokální model
 * zaměnit bez změn business logiky aplikace.
 */
interface AiDocumentExtractorInterface
{
    public function name(): string;

    /** @return array<string,mixed> */
    public function extract(string $path, string $mimeType, string $originalName): array;
}

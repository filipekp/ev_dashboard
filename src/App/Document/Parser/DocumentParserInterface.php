<?php

declare(strict_types=1);

namespace App\Document\Parser;

interface DocumentParserInterface
{
    public function name(): string;
    public function supports(string $path, string $mimeType, string $originalName): bool;
    /** @return array<string,mixed> */
    public function parse(string $path, string $mimeType, string $originalName): array;
}

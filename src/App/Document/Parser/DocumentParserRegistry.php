<?php

declare(strict_types=1);

namespace App\Document\Parser;

final class DocumentParserRegistry
{
    /** @var array<int,DocumentParserInterface> */ private $parsers;
    /** @param array<int,DocumentParserInterface> $parsers */
    public function __construct(array $parsers) { $this->parsers = $parsers; }

    public function resolve(string $path, string $mimeType, string $originalName): ?DocumentParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($path, $mimeType, $originalName)) {
                return $parser;
            }
        }
        return null;
    }
}

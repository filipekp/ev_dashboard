<?php

declare(strict_types=1);

namespace App\Import;

interface TripImportPluginInterface
{
    public function key(): string;

    public function supports(string $path, ?string $originalName = null): bool;

    /** @return array<string,mixed> */
    public function inspect(string $path, ?string $originalName = null): array;

    /** @return iterable<int,array<string,mixed>> */
    public function records(string $path): iterable;
}

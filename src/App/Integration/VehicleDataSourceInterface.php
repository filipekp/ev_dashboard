<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * Společný kontrakt pro budoucí online integrace vozidel.
 *
 * Konektory (Tesla, Kia/Hyundai, VAG...) mají vracet normalizované dávky,
 * aby zbytek aplikace nemusel znát vendor-specific API strukturu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
interface VehicleDataSourceInterface
{
    public function id(): string;

    public function label(): string;

    /** @return array<string,mixed> */
    public function capabilities(): array;

    /**
     * @param array<string,mixed> $configuration
     * @return array<int,array<string,mixed>>
     */
    public function fetch(array $configuration, ?string $cursor = null): array;
}

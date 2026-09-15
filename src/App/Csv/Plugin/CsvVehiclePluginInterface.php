<?php

declare(strict_types=1);

namespace App\Csv\Plugin;
/**
     * Defines the contract implemented by CSV vehicle plugins.
     *
     * @author    Pavel Filípek <pavel@filipek-czech.cz>
     * @copyright © 2026, Proclient s.r.o.
     * @created   15.09.2026
     */
interface CsvVehiclePluginInterface
{
    public function id(): string;
    public function label(): string;
    /** @param string[] $header */
    public function supports(array $header): bool;
    /** @return array<string,mixed> */
    public function inspect(?string $vin): array;
    /** @param array<string,string|null> $row @return array<string,mixed>|null */
    public function parse(array $row): ?array;
    /** @return string[] */
    public function exportHeaders(): array;
    /** @param array<string,mixed> $trip @return array<int,string|int|float|null> */
    public function exportRow(array $trip): array;
}

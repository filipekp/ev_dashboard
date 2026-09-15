<?php

declare(strict_types=1);

namespace App\Csv\Plugin;

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

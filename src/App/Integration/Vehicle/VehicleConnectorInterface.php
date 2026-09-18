<?php

declare(strict_types=1);

namespace App\Integration\Vehicle;

/**
 * Společný kontrakt pro přímé OEM konektory vozidel.
 *
 * Konektor zná autentizaci a vendor-specific API, ale vrací jednotný interní
 * telemetry payload, nad kterým pracuje zbytek EV Stats.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
interface VehicleConnectorInterface
{
    public function id(): string;

    public function label(): string;

    /** @return string[] */
    public function supportedManufacturers(): array;

    /** @return array<string,mixed> */
    public function capabilities(): array;

    /**
     * Metadata pro vykreslení přihlašovacího widgetu v editaci vozidla.
     *
     * @return array<string,mixed>
     */
    public function credentialSchema(): array;

    /** @param array<string,mixed> $vehicle */
    public function supportsVehicle(array $vehicle): bool;

    public function recommendedSyncIntervalSeconds(): int;

    /**
     * @param array<string,mixed> $vehicle
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    public function fetch(array $vehicle, array $credentials): array;
}

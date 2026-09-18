<?php

declare(strict_types=1);

namespace App\Integration\Vehicle;

/**
 * Volitelný kontrakt pro OEM konektory, které umí na straně poskytovatele
 * ukončit sdílení dat při odpojení vozidla z EV Stats.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
interface RevocableVehicleConnectorInterface
{
    /**
     * @param array<string,mixed> $vehicle
     * @param array<string,mixed> $credentials
     */
    public function revoke(array $vehicle, array $credentials): void;

    /**
     * Někteří poskytovatelé vyžadují při ukončení souhlasu odstranit i data,
     * která byla jejich API získána.
     */
    public function purgeDataOnDisconnect(): bool;
}

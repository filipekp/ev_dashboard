<?php

declare(strict_types=1);

namespace App\Integration\Vehicle;

/**
 * Volitelný kontrakt pro OAuth konektory, jejichž credentials je nutné obnovit
 * před API voláním. U providerů s rotujícím refresh tokenem umožní sync vrstvě
 * uložit nový token ještě před dalším síťovým požadavkem.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
interface RefreshableVehicleConnectorInterface
{
    /**
     * @param array<string,mixed> $credentials
     * @return array{credentials:array<string,mixed>,changed:bool,hint:string,expires_at:?string}
     */
    public function refreshCredentialsIfNeeded(array $credentials): array;
}

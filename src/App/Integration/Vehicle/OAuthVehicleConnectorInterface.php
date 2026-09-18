<?php

declare(strict_types=1);

namespace App\Integration\Vehicle;

/**
 * Rozšíření OEM connectoru pro výrobce používající browserový OAuth flow.
 *
 * OAuth přihlašovací údaje uživatele nikdy neprocházejí EV Stats. Aplikace
 * dostane pouze krátkodobý access token a případný refresh token.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
interface OAuthVehicleConnectorInterface extends VehicleConnectorInterface
{
    public function oauthConfigured(): bool;

    public function authorizationUrl(string $state, string $nonce): string;

    /** @return array<string,mixed> */
    public function exchangeAuthorizationCode(string $code): array;
}

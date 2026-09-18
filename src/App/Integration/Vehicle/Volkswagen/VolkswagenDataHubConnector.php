<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Volkswagen;

use App\Integration\Vehicle\Vag\AbstractVagDataHubConnector;

/**
 * Volkswagen read-only konektor přes oficiální Volkswagen Group EU Data Act Data Hub.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class VolkswagenDataHubConnector extends AbstractVagDataHubConnector
{
    public function id(): string
    {
        return 'volkswagen_data_hub';
    }

    public function label(): string
    {
        return 'Volkswagen EU Data Act API';
    }

    /** @return string[] */
    public function supportedManufacturers(): array
    {
        return ['VOLKSWAGEN', 'VW'];
    }

    protected function vendorCode(): string
    {
        return 'VOLKSWAGEN';
    }
}

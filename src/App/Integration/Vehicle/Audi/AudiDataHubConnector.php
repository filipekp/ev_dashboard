<?php

declare(strict_types=1);

namespace App\Integration\Vehicle\Audi;

use App\Integration\Vehicle\Vag\AbstractVagDataHubConnector;

/**
 * Audi read-only konektor přes oficiální Volkswagen Group EU Data Act Data Hub.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class AudiDataHubConnector extends AbstractVagDataHubConnector
{
    public function id(): string
    {
        return 'audi_data_hub';
    }

    public function label(): string
    {
        return 'Audi EU Data Act API';
    }

    /** @return string[] */
    public function supportedManufacturers(): array
    {
        return ['AUDI'];
    }

    protected function vendorCode(): string
    {
        return 'AUDI';
    }
}

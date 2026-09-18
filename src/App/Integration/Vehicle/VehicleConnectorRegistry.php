<?php

declare(strict_types=1);

namespace App\Integration\Vehicle;

use RuntimeException;

/**
 * Registr dostupných OEM konektorů.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class VehicleConnectorRegistry
{
    /** @var array<string,VehicleConnectorInterface> */
    private $connectors = [];

    /** @param VehicleConnectorInterface[] $connectors */
    public function __construct(array $connectors = [])
    {
        foreach ($connectors as $connector) {
            $this->register($connector);
        }
    }

    public function register(VehicleConnectorInterface $connector): void
    {
        $this->connectors[$connector->id()] = $connector;
    }

    public function get(string $id): VehicleConnectorInterface
    {
        if (!isset($this->connectors[$id])) {
            throw new RuntimeException('Konektor „' . $id . '“ není v EV Stats registrován.');
        }

        return $this->connectors[$id];
    }

    /** @param array<string,mixed> $vehicle @return VehicleConnectorInterface[] */
    public function forVehicle(array $vehicle): array
    {
        $result = [];
        foreach ($this->connectors as $connector) {
            if ($connector->supportsVehicle($vehicle)) {
                $result[] = $connector;
            }
        }
        return $result;
    }

    /** @return VehicleConnectorInterface[] */
    public function all(): array
    {
        return array_values($this->connectors);
    }

    /** @param array<string,mixed> $vehicle @return array<int,array<string,mixed>> */
    public function descriptorsForVehicle(array $vehicle): array
    {
        $result = [];
        foreach ($this->forVehicle($vehicle) as $connector) {
            $result[] = [
                'id' => $connector->id(),
                'label' => $connector->label(),
                'capabilities' => $connector->capabilities(),
                'credentials' => $connector->credentialSchema(),
                'sync_interval_seconds' => $connector->recommendedSyncIntervalSeconds(),
            ];
        }
        return $result;
    }
}

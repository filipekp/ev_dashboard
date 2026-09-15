<?php

declare(strict_types=1);

namespace App\Service;

use App\AuthService;
use App\Import\TripFileImporter;
use App\Repository\IntegrationImportRunRepository;
use App\Repository\TripRepository;
use App\Repository\VehicleRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Aplikační služba pro import jízd a přiřazení importu k vozidlu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class ImportService
{
    /** @var PDO */
    private $pdo;

    /** @var AuthService */
    private $auth;

    /** @var TripFileImporter */
    private $importer;

    /** @var VehicleRepository */
    private $vehicles;

    /** @var TripRepository */
    private $trips;

    /** @var IntegrationImportRunRepository */
    private $importRuns;

    public function __construct(
        PDO $pdo,
        AuthService $auth,
        TripFileImporter $importer,
        VehicleRepository $vehicles,
        TripRepository $trips,
        IntegrationImportRunRepository $importRuns
    ) {
        $this->pdo = $pdo;
        $this->auth = $auth;
        $this->importer = $importer;
        $this->vehicles = $vehicles;
        $this->trips = $trips;
        $this->importRuns = $importRuns;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function importUploaded(
        array $user,
        string $path,
        string $originalName,
        int $selectedVehicleId
    ): array {
        $meta = $this->importer->inspect($path, $originalName);
        $vin = isset($meta['vin']) && is_string($meta['vin']) && $meta['vin'] !== ''
            ? $meta['vin']
            : null;
        $vehicleId = 0;
        $newVehicle = false;

        try {
            if ($vin !== null) {
                $existing = $this->vehicles->findByVin($vin);
                if ($existing) {
                    $vehicleId = (int)$existing['id'];
                    $this->assertVehicleAssignedToUser($user, $vehicleId);
                    $this->assertVehicleCompatible($existing, $meta);
                } else {
                    $this->pdo->beginTransaction();
                    try {
                        $vehicleId = $this->vehicles->createFromImport($vin, $meta);
                        $this->vehicles->assignUser((int)$user['id'], $vehicleId);
                        $this->pdo->commit();
                        $newVehicle = true;
                    } catch (Throwable $e) {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                        throw $e;
                    }
                }
            } else {
                $vehicleId = $selectedVehicleId;
                if ($vehicleId <= 0) {
                    throw new RuntimeException(
                        'Soubor neobsahuje rozpoznatelné VIN. Vyberte existující vozidlo, do kterého chcete data importovat.'
                    );
                }

                $this->assertVehicleAssignedToUser($user, $vehicleId);
                $vehicle = $this->vehicles->find($vehicleId);
                if ($vehicle === null) {
                    throw new RuntimeException('Vybrané vozidlo nebylo nalezeno.');
                }
                $this->assertVehicleCompatible($vehicle, $meta);
            }

            $runId = $this->importRuns->start(
                (int)$user['id'],
                $vehicleId,
                $this->sourceType($meta),
                $this->sourceLabel($meta, $originalName)
            );

            try {
                $result = $this->importer->import($vehicleId, $path, $originalName ?: null);
                $this->importRuns->completeSuccess(
                    $runId,
                    (int)($result['inserted'] ?? 0),
                    (int)($result['skipped'] ?? 0)
                );
            } catch (Throwable $e) {
                try {
                    $this->importRuns->completeFailure($runId, $e->getMessage());
                } catch (Throwable $auditError) {
                    // Auditní chyba nesmí překrýt původní chybu importu.
                }
                throw $e;
            }

            return array_merge($result, [
                'vehicle_id' => $vehicleId,
                'vin' => $vin,
                'new_vehicle' => $newVehicle,
                'import_run_id' => $runId,
            ]);
        } catch (Throwable $e) {
            if ($newVehicle && $vehicleId > 0 && $this->trips->countForVehicle($vehicleId) === 0) {
                try {
                    $this->vehicles->delete($vehicleId);
                } catch (Throwable $ignore) {
                    // Původní chyba importu má přednost před chybou úklidu.
                }
            }

            throw $e;
        }
    }
    /** @param array<string,mixed> $user */
    private function assertVehicleAssignedToUser(array $user, int $vehicleId): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || !$this->auth->isVehicleAssignedToUser($userId, $vehicleId)) {
            throw new RuntimeException(
                'Do vybraného vozidla nemůžete importovat data, protože není přiřazeno vašemu uživatelskému účtu. ' .
                'Správce musí vozidlo nejprve přiřadit konkrétnímu uživateli.'
            );
        }
    }

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $meta */
    private function assertVehicleCompatible(array $vehicle, array $meta): void
    {
        $expected = $this->normalizeManufacturer((string)($meta['manufacturer'] ?? ''));
        if ($expected === '') {
            return;
        }

        $actual = $this->normalizeManufacturer((string)($vehicle['manufacturer'] ?? ''));
        if ($actual === '') {
            throw new RuntimeException(
                'U vozidla „' . (string)($vehicle['name'] ?? '') . '“ není nastaven výrobce. ' .
                'Před importem jej doplňte ve správě vozidel.'
            );
        }

        if ($actual !== $expected) {
            throw new RuntimeException(
                'Import byl zastaven: soubor je určen pro výrobce ' . $expected .
                ', ale vybrané vozidlo je označeno jako ' . $actual . '. Žádná data nebyla uložena.'
            );
        }
    }

    /** @param array<string,mixed> $meta */
    private function sourceType(array $meta): string
    {
        $plugin = trim((string)($meta['import_plugin'] ?? ''));
        $format = trim((string)($meta['format'] ?? ''));

        if ($plugin !== '' && $format !== '' && $plugin !== $format) {
            return $plugin . ':' . $format;
        }
        if ($format !== '') {
            return $format;
        }

        return $plugin !== '' ? $plugin : 'manual_upload';
    }

    /** @param array<string,mixed> $meta */
    private function sourceLabel(array $meta, string $originalName): string
    {
        $label = trim((string)($meta['plugin_label'] ?? 'Import souboru'));
        $fileName = trim(basename($originalName));

        if ($fileName === '') {
            return $label;
        }

        return $label . ' · ' . $fileName;
    }

    private function normalizeManufacturer(string $manufacturer): string
    {
        $manufacturer = strtoupper(trim($manufacturer));
        $aliases = [
            'ŠKODA' => 'SKODA',
            'SKODA AUTO' => 'SKODA',
            'KIA MOTORS' => 'KIA',
        ];

        return $aliases[$manufacturer] ?? $manufacturer;
    }

}

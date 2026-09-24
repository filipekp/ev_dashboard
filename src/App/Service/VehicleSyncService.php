<?php

declare(strict_types=1);

namespace App\Service;

use App\Integration\Vehicle\RefreshableVehicleConnectorInterface;
use App\Integration\Vehicle\VehicleConnectorException;
use App\Integration\Vehicle\VehicleConnectorRegistry;
use App\Repository\TripRepository;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleOperationRepository;
use App\Security\CredentialCipher;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Provider-agnostická synchronizace OEM Connected Car telemetrie.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class VehicleSyncService
{
    /** @var PDO */
    private $pdo;

    /** @var VehicleDataRepository */
    private $repository;

    /** @var VehicleConnectorRegistry */
    private $connectors;

    /** @var CredentialCipher */
    private $cipher;

    /** @var VehicleTelemetryEventProjector */
    private $projector;

    /** @var VehicleTelemetryDerivedDataService */
    private $derivedData;

    public function __construct(
        PDO $pdo,
        VehicleDataRepository $repository,
        VehicleConnectorRegistry $connectors,
        CredentialCipher $cipher
    ) {
        $this->pdo = $pdo;
        $this->repository = $repository;
        $this->connectors = $connectors;
        $this->cipher = $cipher;
        $this->projector = new VehicleTelemetryEventProjector();
        $this->derivedData = new VehicleTelemetryDerivedDataService(
            $pdo,
            $repository,
            new TripRepository($pdo),
            new VehicleOperationRepository($pdo)
        );
    }

    /** @return array{snapshots:int,events:int} */
    public function syncConnection(int $connectionId, ?int $actorUserId = null, string $triggerType = 'manual'): array
    {
        $connection = $this->repository->findConnection($connectionId);
        $vehicleId = (int)($connection['vehicle_id'] ?? 0);
        if ($vehicleId <= 0) {
            throw new RuntimeException('OEM konektor není přiřazen k vozidlu v EV Stats.');
        }

        $provider = (string)$connection['provider'];
        $connector = $this->connectors->get($provider);
        $encryptedCredentials = trim((string)($connection['credentials_encrypted'] ?? ''));
        if ($encryptedCredentials === '') {
            throw new RuntimeException('OEM konektor nemá uložené přihlašovací údaje.');
        }

        $vehicle = [
            'id' => $vehicleId,
            'name' => (string)($connection['vehicle_name'] ?? ''),
            'vin' => (string)($connection['vehicle_vin'] ?? ''),
            'manufacturer' => (string)($connection['vehicle_manufacturer'] ?? ''),
            'powertrain_type' => (string)($connection['vehicle_powertrain'] ?? ''),
        ];
        if (!$connector->supportsVehicle($vehicle)) {
            throw new RuntimeException('Konektor ' . $connector->label() . ' nepodporuje výrobce tohoto vozidla.');
        }

        $runId = $this->repository->startSyncRun($connectionId, $actorUserId, $triggerType);
        $snapshotCount = 0;
        $eventCount = 0;

        try {
            // Lifecycle projekce běží ještě před síťovým voláním. Díky tomu
            // každý CRON dokáže po 2 hodinách uzavřít živou jízdu i tehdy,
            // když OEM vrací stále stejný snapshot nebo aktuální API request
            // následně selže.
            $before = $this->derivedData->project($vehicleId, $connectionId, $provider, false);
            $eventCount += (int)($before['events'] ?? 0);

            $credentials = $this->cipher->decrypt($encryptedCredentials);
            if ($connector instanceof RefreshableVehicleConnectorInterface) {
                $prepared = $connector->refreshCredentialsIfNeeded($credentials);
                $credentials = $prepared['credentials'];
                if (!empty($prepared['changed'])) {
                    // U rotujících refresh tokenů (Pleos) ukládáme nový token
                    // okamžitě před dalším síťovým voláním, jinak by případná
                    // následná chyba zneplatnila starý token bez možnosti obnovy.
                    $encrypted = $this->cipher->encrypt($credentials);
                    $secret = trim((string)($credentials['refresh_token'] ?? $credentials['access_token'] ?? ''));
                    $this->repository->updateConnectionCredentials(
                        $connectionId,
                        $encrypted,
                        hash('sha256', $secret !== '' ? $secret : $encrypted),
                        (string)($prepared['hint'] ?? 'OAuth'),
                        isset($prepared['expires_at']) ? (string)$prepared['expires_at'] : null
                    );
                }
            }

            $result = $connector->fetch($vehicle, $credentials);
            $normalized = isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : null;
            $metadata = isset($result['connection']) && is_array($result['connection']) ? $result['connection'] : [];
            $updatedCredentials = isset($result['credentials']) && is_array($result['credentials'])
                ? $result['credentials']
                : null;
            if ($normalized === null) {
                throw new RuntimeException('Konektor nevrátil normalizovaný telemetry snapshot.');
            }

            // Některé OEM API mohou vrátit nový/rotovaný token až během samotného
            // datového requestu (např. retry po HTTP 401). Uložíme ho ještě v
            // rámci stejného sync běhu, aby další synchronizace nepoužila starý token.
            if ($updatedCredentials !== null) {
                $credentials = $updatedCredentials;
                $encrypted = $this->cipher->encrypt($credentials);
                $secret = trim((string)($credentials['refresh_token'] ?? $credentials['access_token'] ?? ''));
                $expiresAt = trim((string)($credentials['expires_at'] ?? $credentials['access_token_expires_at'] ?? ''));
                $this->repository->updateConnectionCredentials(
                    $connectionId,
                    $encrypted,
                    hash('sha256', $secret !== '' ? $secret : $encrypted),
                    'OAuth',
                    $expiresAt !== '' ? $expiresAt : null
                );
            }

            $previous = $this->repository->latestTelemetry($connectionId);
            $this->repository->updateConnectionMetadata($connectionId, $metadata);
            $inserted = $this->repository->insertTelemetry($vehicleId, $connectionId, $provider, $normalized);
            if ($inserted) {
                $snapshotCount++;
                foreach ($this->projector->project($previous, $normalized) as $event) {
                    if ($this->repository->insertEvent(
                        $vehicleId,
                        $connectionId,
                        $provider,
                        (string)$event['type'],
                        (string)$event['severity'],
                        (string)$event['title'],
                        (string)$event['occurred_at'],
                        (array)$event['data']
                    )) {
                        $eventCount++;
                    }
                }
                $this->updateVehicleOperationalState($vehicleId, $normalized);
            }

            // Po načtení OEM dat lifecycle projekci spustíme znovu. Nový
            // přírůstek odometru tak založí/aktualizuje živou jízdu okamžitě,
            // ne až po dvouhodinovém timeoutu.
            $after = $this->derivedData->project($vehicleId, $connectionId, $provider, $inserted);
            $eventCount += (int)($after['events'] ?? 0);

            $nextSyncAt = isset($metadata['next_sync_at']) && is_string($metadata['next_sync_at'])
                ? $metadata['next_sync_at']
                : date('Y-m-d H:i:s', time() + $connector->recommendedSyncIntervalSeconds());
            $this->repository->markConnectionSynced($connectionId, $nextSyncAt);
            $this->repository->finishSyncRun($runId, $snapshotCount, $eventCount);

            return ['snapshots' => $snapshotCount, 'events' => $eventCount];
        } catch (VehicleConnectorException $e) {
            $this->repository->failSyncRun($runId, $e->getMessage());
            $this->repository->markConnectionFailure(
                $connectionId,
                $e->getMessage(),
                $e->needsAttention(),
                $e->retryAfterAt(),
                $e->metadata()
            );
            throw $e;
        } catch (Throwable $e) {
            $this->repository->failSyncRun($runId, $e->getMessage());
            $this->repository->markConnectionFailure(
                $connectionId,
                $e->getMessage(),
                false,
                date('Y-m-d H:i:s', time() + 600)
            );
            throw $e;
        }
    }

    /**
     * Zpracuje frontu rekonstrukce telemetry jízd. Metoda je veřejná, aby ji
     * mohl spustit nejen CRON, ale také webová administrace po aplikaci migrací.
     *
     * @return array{repaired:int,failed:int,events:int}
     */
    public function repairQueuedTelemetryTrips(int $limit = 100): array
    {
        $result = ['repaired' => 0, 'failed' => 0, 'events' => 0];
        foreach ($this->repository->queuedTripRebuilds($limit) as $queued) {
            try {
                $rebuilt = $this->derivedData->rebuildTelemetryTrips(
                    (int)$queued['vehicle_id'],
                    (int)$queued['connection_id'],
                    (string)$queued['provider']
                );
                $result['events'] += (int)($rebuilt['events'] ?? 0);
                $this->repository->completeTripRebuild((int)$queued['connection_id']);
                $result['repaired']++;
            } catch (Throwable $e) {
                $result['failed']++;
                error_log('[EV Stats telemetry trip rebuild] ' . $e->getMessage());
            }
        }

        return $result;
    }

    /** @return array{connections:int,snapshots:int,events:int,failed:int,repairs:int,repair_failed:int} */
    public function syncAll(): array
    {
        $result = [
            'connections' => 0,
            'snapshots' => 0,
            'events' => 0,
            'failed' => 0,
            'repairs' => 0,
            'repair_failed' => 0,
        ];

        // Rebuild po migracích v26/v27 opraví dříve slepené nebo časově
        // nevěrohodné telemetry jízdy ještě před běžným lifecycle zpracováním.
        $repair = $this->repairQueuedTelemetryTrips(100);
        $result['events'] += (int)$repair['events'];
        $result['repairs'] = (int)$repair['repaired'];
        $result['repair_failed'] = (int)$repair['failed'];

        // Každé CRON volání nejdřív zpracuje lifecycle nad uloženými daty pro
        // všechny aktivní konektory, i když mají next_sync_at v budoucnu nebo
        // čekají na retry_after. Ukončení jízdy tak není závislé na dalším
        // úspěšném síťovém requestu k automobilce.
        foreach ($this->repository->allActiveConnections() as $connection) {
            try {
                $derived = $this->derivedData->project(
                    (int)$connection['vehicle_id'],
                    (int)$connection['id'],
                    (string)$connection['provider'],
                    false
                );
                $result['events'] += (int)($derived['events'] ?? 0);
            } catch (Throwable $e) {
                error_log('[EV Stats AutoSync lifecycle] ' . $e->getMessage());
            }
        }

        foreach ($this->repository->activeConnections() as $connection) {
            $result['connections']++;
            try {
                $sync = $this->syncConnection((int)$connection['id'], null, 'cron');
                $result['snapshots'] += $sync['snapshots'];
                $result['events'] += $sync['events'];
            } catch (Throwable $e) {
                $result['failed']++;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $normalized */
    private function updateVehicleOperationalState(int $vehicleId, array $normalized): void
    {
        $telemetry = isset($normalized['telemetry']) && is_array($normalized['telemetry']) ? $normalized['telemetry'] : [];
        $odometer = $telemetry['odometer_km'] ?? null;
        if (is_numeric($odometer) && (float)$odometer >= 0) {
            $q = $this->pdo->prepare(
                'UPDATE vehicles SET odometer_km=CASE WHEN odometer_km IS NULL OR odometer_km<? THEN ? ELSE odometer_km END WHERE id=?'
            );
            $q->execute([(float)$odometer, (float)$odometer, $vehicleId]);
        }
    }
}

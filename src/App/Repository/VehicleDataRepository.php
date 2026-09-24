<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use RuntimeException;

/**
 * Persistence vrstva pro OEM konektory, telemetrii, event stream a sync běhy.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class VehicleDataRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Vytvoří nebo aktualizuje přímé propojení konkrétního lokálního vozidla.
     * Credentials musí být již zašifrované aplikačním master key.
     *
     * @return array<string,mixed>
     */
    public function upsertConnectorConnection(
        int $userId,
        int $vehicleId,
        string $provider,
        string $externalVehicleId,
        string $encryptedCredentials,
        string $credentialFingerprint,
        string $credentialHint
    ): array {
        $q = $this->pdo->prepare(
            'INSERT INTO vehicle_connector_connections(
                user_id,vehicle_id,provider,external_vehicle_id,external_vin,external_name,
                credentials_encrypted,credential_fingerprint,credential_hint,status,next_sync_at,last_error
             )
             SELECT ?,v.id,?, ?,v.vin,v.name,?,?,?,\'pending\',NOW(),NULL
             FROM vehicles v WHERE v.id=?
             ON DUPLICATE KEY UPDATE
                user_id=VALUES(user_id),
                external_vehicle_id=VALUES(external_vehicle_id),
                external_vin=VALUES(external_vin),
                external_name=VALUES(external_name),
                credentials_encrypted=VALUES(credentials_encrypted),
                credential_fingerprint=VALUES(credential_fingerprint),
                credential_hint=VALUES(credential_hint),
                status=\'pending\',
                next_sync_at=NOW(),
                last_error=NULL'
        );
        $q->execute([
            $userId,
            $this->limit($provider, 40),
            $this->limit($externalVehicleId, 190),
            $encryptedCredentials,
            $credentialFingerprint,
            $this->limit($credentialHint, 32),
            $vehicleId,
        ]);

        $connection = $this->findConnectionForVehicle($vehicleId, $provider);
        if ($connection === null) {
            throw new RuntimeException('OEM propojení se nepodařilo uložit.');
        }
        return $connection;
    }

    /** @return array<string,mixed> */
    public function findConnection(int $connectionId): array
    {
        $q = $this->pdo->prepare(
            'SELECT c.*,v.name vehicle_name,v.vin vehicle_vin,v.manufacturer vehicle_manufacturer,
                    v.powertrain_type vehicle_powertrain
             FROM vehicle_connector_connections c
             JOIN vehicles v ON v.id=c.vehicle_id
             WHERE c.id=?'
        );
        $q->execute([$connectionId]);
        $row = $q->fetch();
        if (!$row) {
            throw new RuntimeException('Connected Car vazba nebyla nalezena.');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function findConnectionForVehicle(int $vehicleId, ?string $provider = null): ?array
    {
        $sql = 'SELECT c.*,v.name vehicle_name,v.vin vehicle_vin,v.manufacturer vehicle_manufacturer,
                       v.powertrain_type vehicle_powertrain
                FROM vehicle_connector_connections c
                JOIN vehicles v ON v.id=c.vehicle_id
                WHERE c.vehicle_id=?';
        $params = [$vehicleId];
        if ($provider !== null) {
            $sql .= ' AND c.provider=?';
            $params[] = $provider;
        }
        $sql .= ' ORDER BY c.id LIMIT 1';
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        $row = $q->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function connectionsForUser(int $userId): array
    {
        $q = $this->pdo->prepare(
            'SELECT c.*,v.name vehicle_name,v.vin vehicle_vin,v.manufacturer vehicle_manufacturer,
                    (SELECT COUNT(*) FROM vehicle_telemetry_snapshots t WHERE t.connection_id=c.id) telemetry_count,
                    (SELECT MAX(t.observed_at) FROM vehicle_telemetry_snapshots t WHERE t.connection_id=c.id) latest_telemetry_at
             FROM vehicle_connector_connections c
             JOIN vehicles v ON v.id=c.vehicle_id
             WHERE c.user_id=?
             ORDER BY v.name,c.id'
        );
        $q->execute([$userId]);
        return $q->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function allActiveConnections(): array
    {
        return $this->pdo->query(
            "SELECT c.*,v.name vehicle_name,v.vin vehicle_vin,v.manufacturer vehicle_manufacturer,
                    v.powertrain_type vehicle_powertrain
             FROM vehicle_connector_connections c
             JOIN vehicles v ON v.id=c.vehicle_id
             WHERE c.status='active'
             ORDER BY c.id"
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function activeConnections(): array
    {
        return $this->pdo->query(
            "SELECT c.*,v.name vehicle_name,v.vin vehicle_vin,v.manufacturer vehicle_manufacturer,
                    v.powertrain_type vehicle_powertrain
             FROM vehicle_connector_connections c
             JOIN vehicles v ON v.id=c.vehicle_id
             WHERE c.status='active'
               AND (c.next_sync_at IS NULL OR c.next_sync_at<=NOW())
               AND (c.retry_after_at IS NULL OR c.retry_after_at<=NOW())
             ORDER BY c.id"
        )->fetchAll();
    }

    /** @param array<string,mixed> $metadata */
    public function updateConnectionMetadata(int $connectionId, array $metadata): void
    {
        $capabilities = json_encode($metadata['capabilities'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $details = json_encode($metadata['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $q = $this->pdo->prepare(
            'UPDATE vehicle_connector_connections SET
                external_vehicle_id=COALESCE(?,external_vehicle_id),
                external_vin=COALESCE(?,external_vin),
                external_name=COALESCE(?,external_name),
                capabilities_json=?,
                credential_expires_at=?,
                rate_limit_limit=?,
                rate_limit_remaining=?,
                rate_limit_reset_at=?,
                retry_after_at=?,
                next_sync_at=?,
                metadata_json=?
             WHERE id=?'
        );
        $q->execute([
            $this->nullableString($metadata['external_vehicle_id'] ?? null, 190),
            $this->nullableString($metadata['external_vin'] ?? null, 64),
            $this->nullableString($metadata['external_name'] ?? null, 190),
            $capabilities !== false ? $capabilities : '{}',
            $this->nullableDateTime($metadata['credential_expires_at'] ?? null),
            $this->nullableInt($metadata['rate_limit_limit'] ?? null),
            $this->nullableInt($metadata['rate_limit_remaining'] ?? null),
            $this->nullableDateTime($metadata['rate_limit_reset_at'] ?? null),
            $this->nullableDateTime($metadata['retry_after_at'] ?? null),
            $this->nullableDateTime($metadata['next_sync_at'] ?? null),
            $details !== false ? $details : '{}',
            $connectionId,
        ]);
    }

    public function markConnectionSynced(int $connectionId, ?string $nextSyncAt = null): void
    {
        $q = $this->pdo->prepare(
            "UPDATE vehicle_connector_connections
             SET status='active',last_synced_at=NOW(),last_error=NULL,retry_after_at=NULL,
                 next_sync_at=COALESCE(?,next_sync_at)
             WHERE id=?"
        );
        $q->execute([$this->nullableDateTime($nextSyncAt), $connectionId]);
    }

    /** @param array<string,mixed> $metadata */
    public function markConnectionFailure(
        int $connectionId,
        string $error,
        bool $needsAttention,
        ?string $retryAfterAt = null,
        array $metadata = []
    ): void {
        $status = $needsAttention ? 'error' : 'active';
        $nextSyncAt = $retryAfterAt ?: date('Y-m-d H:i:s', time() + 600);
        $q = $this->pdo->prepare(
            'UPDATE vehicle_connector_connections SET status=?,last_error=?,retry_after_at=?,next_sync_at=?,
                    rate_limit_limit=COALESCE(?,rate_limit_limit),
                    rate_limit_remaining=COALESCE(?,rate_limit_remaining),
                    rate_limit_reset_at=COALESCE(?,rate_limit_reset_at)
             WHERE id=?'
        );
        $q->execute([
            $status,
            $this->limit($error, 65535),
            $this->nullableDateTime($retryAfterAt),
            $this->nullableDateTime($nextSyncAt),
            $this->nullableInt($metadata['rate_limit_limit'] ?? null),
            $this->nullableInt($metadata['rate_limit_remaining'] ?? null),
            $this->nullableDateTime($metadata['rate_limit_reset_at'] ?? null),
            $connectionId,
        ]);
    }

    public function deleteConnection(int $connectionId): void
    {
        $q = $this->pdo->prepare('DELETE FROM vehicle_connector_connections WHERE id=?');
        $q->execute([$connectionId]);
    }

    /** @return array<string,mixed>|null */
    public function latestTelemetry(int $connectionId): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT * FROM vehicle_telemetry_snapshots WHERE connection_id=? ORDER BY received_at DESC,id DESC LIMIT 1'
        );
        $q->execute([$connectionId]);
        $row = $q->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentTelemetry(int $connectionId, int $limit = 60): array
    {
        $limit = max(3, min(240, $limit));
        $q = $this->pdo->prepare(
            'SELECT * FROM vehicle_telemetry_snapshots WHERE connection_id=? ORDER BY received_at DESC,id DESC LIMIT ' . $limit
        );
        $q->execute([$connectionId]);

        return $q->fetchAll();
    }

    /**
     * Vrátí nejnovější telemetry snapshot aktivního OEM konektoru pro vozidlo.
     *
     * @return array<string,mixed>|null
     */
    public function latestTelemetryForVehicle(int $vehicleId): ?array
    {
        $q = $this->pdo->prepare(
            "SELECT t.*,
                    c.provider,
                    c.status connection_status,
                    c.last_synced_at,
                    c.next_sync_at,
                    c.credential_expires_at,
                    c.rate_limit_limit,
                    c.rate_limit_remaining
             FROM vehicle_telemetry_snapshots t
             JOIN vehicle_connector_connections c ON c.id=t.connection_id
             WHERE t.vehicle_id=? AND c.status='active'
             ORDER BY t.received_at DESC,t.id DESC
             LIMIT 1"
        );
        $q->execute([$vehicleId]);
        $row = $q->fetch();

        return $row ?: null;
    }

    /**
     * Vrátí geolokační telemetrii vozidla v časovém intervalu jízdy.
     *
     * @return array<int,array<string,mixed>>
     */
    public function telemetryForVehiclePeriod(
        int $vehicleId,
        string $from,
        string $to,
        int $limit = 800
    ): array {
        $limit = max(2, min(2000, $limit));
        $q = $this->pdo->prepare(
            'SELECT id,connection_id,source,observed_at,latitude,longitude,parking_address,odometer_km,soc_pct,is_charging,is_plugged_in,charging_power_kw '
            . 'FROM vehicle_telemetry_snapshots '
            . 'WHERE vehicle_id=? AND observed_at>=? AND observed_at<=? AND latitude IS NOT NULL AND longitude IS NOT NULL '
            . 'ORDER BY observed_at ASC,id ASC LIMIT ' . $limit
        );
        $q->execute([$vehicleId, $from, $to]);

        return $q->fetchAll();
    }

    /** @param array<string,mixed> $normalized */
    public function insertTelemetry(int $vehicleId, int $connectionId, string $source, array $normalized): bool
    {
        $telemetry = isset($normalized['telemetry']) && is_array($normalized['telemetry']) ? $normalized['telemetry'] : [];
        $raw = json_encode($normalized['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fingerprint = $this->telemetryFingerprint($normalized);

        $q = $this->pdo->prepare(
            'INSERT IGNORE INTO vehicle_telemetry_snapshots(
                vehicle_id,connection_id,source,observed_at,fingerprint,soc_pct,range_km,odometer_km,
                latitude,longitude,vehicle_speed_kmh,parking_address,is_charging,is_plugged_in,charging_power_kw,
                charging_status,charging_plugin_status,charging_target_standard_pct,charging_target_quick_pct,
                charging_remaining_minutes,battery_temperature_c,fuel_level_pct,ignition_status,sleep_mode,is_parked,
                air_conditioning_state,air_conditioning_target_c,climate_control_mode,climate_blower_speed,
                air_conditioning_without_external_power,air_conditioning_at_unlock,window_heating_front,window_heating_rear,
                windshield_defrost_state,steering_wheel_heat_state,outside_temperature_c,auxiliary_heating_state,
                auxiliary_heating_start_mode,auxiliary_heating_duration_seconds,auxiliary_heating_target_c,
                active_ventilation_state,active_ventilation_duration_seconds,raw_json
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $q->execute([
            $vehicleId,
            $connectionId,
            $this->limit($source, 40),
            (string)($normalized['observed_at'] ?? date('Y-m-d H:i:s')),
            $fingerprint,
            $telemetry['soc_pct'] ?? null,
            $telemetry['range_km'] ?? null,
            $telemetry['odometer_km'] ?? null,
            $telemetry['latitude'] ?? null,
            $telemetry['longitude'] ?? null,
            $telemetry['vehicle_speed_kmh'] ?? null,
            $this->nullableString($telemetry['parking_address'] ?? null, 255),
            array_key_exists('is_charging', $telemetry) && $telemetry['is_charging'] !== null ? (int)(bool)$telemetry['is_charging'] : null,
            array_key_exists('is_plugged_in', $telemetry) && $telemetry['is_plugged_in'] !== null ? (int)(bool)$telemetry['is_plugged_in'] : null,
            $telemetry['charging_power_kw'] ?? null,
            $this->nullableString($telemetry['charging_status'] ?? null, 32),
            $this->nullableString($telemetry['charging_plugin_status'] ?? null, 24),
            $telemetry['charging_target_standard_pct'] ?? null,
            $telemetry['charging_target_quick_pct'] ?? null,
            isset($telemetry['charging_remaining_minutes']) && is_numeric($telemetry['charging_remaining_minutes'])
                ? max(0, (int)$telemetry['charging_remaining_minutes'])
                : null,
            $telemetry['battery_temperature_c'] ?? null,
            $telemetry['fuel_level_pct'] ?? null,
            $this->nullableString($telemetry['ignition_status'] ?? null, 24),
            $this->nullableString($telemetry['sleep_mode'] ?? null, 24),
            array_key_exists('is_parked', $telemetry) && $telemetry['is_parked'] !== null ? (int)(bool)$telemetry['is_parked'] : null,
            $this->nullableString($telemetry['air_conditioning_state'] ?? null, 64),
            $telemetry['air_conditioning_target_c'] ?? null,
            $this->nullableString($telemetry['climate_control_mode'] ?? null, 24),
            isset($telemetry['climate_blower_speed']) && is_numeric($telemetry['climate_blower_speed'])
                ? (int)$telemetry['climate_blower_speed']
                : null,
            array_key_exists('air_conditioning_without_external_power', $telemetry) && $telemetry['air_conditioning_without_external_power'] !== null
                ? (int)(bool)$telemetry['air_conditioning_without_external_power']
                : null,
            array_key_exists('air_conditioning_at_unlock', $telemetry) && $telemetry['air_conditioning_at_unlock'] !== null
                ? (int)(bool)$telemetry['air_conditioning_at_unlock']
                : null,
            $this->nullableString($telemetry['window_heating_front'] ?? null, 24),
            $this->nullableString($telemetry['window_heating_rear'] ?? null, 24),
            $this->nullableString($telemetry['windshield_defrost_state'] ?? null, 24),
            $this->nullableString($telemetry['steering_wheel_heat_state'] ?? null, 24),
            $telemetry['outside_temperature_c'] ?? null,
            $this->nullableString($telemetry['auxiliary_heating_state'] ?? null, 64),
            $this->nullableString($telemetry['auxiliary_heating_start_mode'] ?? null, 64),
            isset($telemetry['auxiliary_heating_duration_seconds']) && is_numeric($telemetry['auxiliary_heating_duration_seconds'])
                ? max(0, (int)$telemetry['auxiliary_heating_duration_seconds'])
                : null,
            $telemetry['auxiliary_heating_target_c'] ?? null,
            $this->nullableString($telemetry['active_ventilation_state'] ?? null, 64),
            isset($telemetry['active_ventilation_duration_seconds']) && is_numeric($telemetry['active_ventilation_duration_seconds'])
                ? max(0, (int)$telemetry['active_ventilation_duration_seconds'])
                : null,
            $raw !== false ? $raw : null,
        ]);

        return $q->rowCount() > 0;
    }

    /** @param array<string,mixed> $data */
    public function insertEvent(
        int $vehicleId,
        int $connectionId,
        string $source,
        string $eventType,
        string $severity,
        string $title,
        string $occurredAt,
        array $data
    ): bool {
        $eventKey = hash('sha256', implode('|', [
            (string)$connectionId,
            $eventType,
            $occurredAt,
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $q = $this->pdo->prepare(
            'INSERT IGNORE INTO vehicle_events(vehicle_id,connection_id,source,event_key,event_type,severity,title,occurred_at,data_json)
             VALUES(?,?,?,?,?,?,?,?,?)'
        );
        $q->execute([
            $vehicleId,
            $connectionId,
            $this->limit($source, 40),
            $eventKey,
            $this->limit($eventType, 64),
            in_array($severity, ['info', 'notice', 'warning', 'critical'], true) ? $severity : 'info',
            $this->limit($title, 190),
            $occurredAt,
            $json !== false ? $json : '{}',
        ]);
        return $q->rowCount() > 0;
    }

    public function startSyncRun(int $connectionId, ?int $userId, string $triggerType): int
    {
        $allowed = ['manual', 'cron', 'webhook', 'initial'];
        if (!in_array($triggerType, $allowed, true)) {
            $triggerType = 'manual';
        }
        $q = $this->pdo->prepare(
            "INSERT INTO vehicle_sync_runs(connection_id,user_id,trigger_type,status,started_at) VALUES(?,?,?,'running',NOW())"
        );
        $q->execute([$connectionId, $userId, $triggerType]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finishSyncRun(int $runId, int $snapshots, int $events): void
    {
        $q = $this->pdo->prepare(
            "UPDATE vehicle_sync_runs SET status='success',snapshots_created=?,events_created=?,finished_at=NOW(),error_message=NULL WHERE id=?"
        );
        $q->execute([max(0, $snapshots), max(0, $events), $runId]);
    }

    public function failSyncRun(int $runId, string $error): void
    {
        $q = $this->pdo->prepare(
            "UPDATE vehicle_sync_runs SET status='failed',error_message=?,finished_at=NOW() WHERE id=?"
        );
        $q->execute([$this->limit($error, 65535), $runId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentRunsForUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $q = $this->pdo->prepare(
            'SELECT r.*,c.external_name,c.provider,v.name vehicle_name
             FROM vehicle_sync_runs r
             JOIN vehicle_connector_connections c ON c.id=r.connection_id
             LEFT JOIN vehicles v ON v.id=c.vehicle_id
             WHERE c.user_id=? ORDER BY r.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $q->execute([$userId]);
        return $q->fetchAll();
    }

    public function syncRunCountForUser(int $userId): int
    {
        $q = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM vehicle_sync_runs r
             JOIN vehicle_connector_connections c ON c.id=r.connection_id
             WHERE c.user_id=?'
        );
        $q->execute([$userId]);
        return (int)$q->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> */
    public function recentRunsAll(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $q = $this->pdo->query(
            'SELECT r.*,c.external_name,c.provider,c.user_id connection_user_id,v.name vehicle_name,
                    run_user.name run_user_name,connection_user.name connection_user_name
             FROM vehicle_sync_runs r
             JOIN vehicle_connector_connections c ON c.id=r.connection_id
             LEFT JOIN vehicles v ON v.id=c.vehicle_id
             LEFT JOIN users run_user ON run_user.id=r.user_id
             LEFT JOIN users connection_user ON connection_user.id=c.user_id
             ORDER BY r.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        return $q->fetchAll();
    }

    public function syncRunCountAll(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM vehicle_sync_runs')->fetchColumn();
    }


    /**
     * Aktualizuje šifrované credentials po OAuth refreshi. U Pleos je refresh
     * token jednorázový, takže novou hodnotu je nutné uložit atomicky hned po
     * úspěšném refreshi.
     */
    public function updateConnectionCredentials(
        int $connectionId,
        string $encryptedCredentials,
        string $credentialFingerprint,
        string $credentialHint,
        ?string $credentialExpiresAt = null
    ): void {
        $q = $this->pdo->prepare(
            'UPDATE vehicle_connector_connections SET credentials_encrypted=?,credential_fingerprint=?,credential_hint=?,credential_expires_at=? WHERE id=?'
        );
        $q->execute([
            $encryptedCredentials,
            $credentialFingerprint,
            $this->limit($credentialHint, 32),
            $this->nullableDateTime($credentialExpiresAt),
            $connectionId,
        ]);
    }

    /**
     * Smaže telemetrii a eventy získané konkrétním konektorem. Používá se u
     * providerů, kteří po ukončení souhlasu vyžadují odstranění poskytnutých dat.
     */
    public function purgeConnectionData(int $connectionId): void
    {
        $q = $this->pdo->prepare('DELETE FROM vehicle_events WHERE connection_id=?');
        $q->execute([$connectionId]);
        $q = $this->pdo->prepare('DELETE FROM vehicle_telemetry_snapshots WHERE connection_id=?');
        $q->execute([$connectionId]);
    }

    /**
     * Zpracuje externí odvolání souhlasu podle VIN a odstraní pouze data daného
     * providera. Ostatní historie vozidla v EV Stats zůstává nedotčená.
     *
     * @param string[] $vins
     * @return int počet odstraněných propojení
     */
    public function revokeProviderConnectionsByVins(string $provider, array $vins): int
    {
        $normalized = [];
        foreach ($vins as $vin) {
            $vin = strtoupper(trim((string)$vin));
            if ($vin !== '') {
                $normalized[$vin] = true;
            }
        }
        if ($normalized === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $params = array_merge([$provider], array_keys($normalized), array_keys($normalized));
        $q = $this->pdo->prepare(
            'SELECT c.id FROM vehicle_connector_connections c LEFT JOIN vehicles v ON v.id=c.vehicle_id '
            . 'WHERE c.provider=? AND (UPPER(c.external_vin) IN (' . $placeholders . ') OR UPPER(v.vin) IN (' . $placeholders . '))'
        );
        $q->execute($params);
        $ids = array_map('intval', array_column($q->fetchAll(), 'id'));
        foreach ($ids as $connectionId) {
            $this->purgeConnectionData($connectionId);
            $this->deleteConnection($connectionId);
        }
        return count($ids);
    }

    /** @param array<string,mixed> $normalized */
    private function telemetryFingerprint(array $normalized): string
    {
        $stable = [
            'external_id' => $normalized['external_id'] ?? null,
            'observed_at' => $normalized['observed_at'] ?? null,
            'telemetry' => isset($normalized['telemetry']) && is_array($normalized['telemetry']) ? $normalized['telemetry'] : [],
        ];
        return hash('sha256', (string)json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param mixed $value */
    private function nullableString($value, int $length): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $this->limit($value, $length);
    }

    /** @param mixed $value */
    private function nullableInt($value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /** @param mixed $value */
    private function nullableDateTime($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function limit(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}

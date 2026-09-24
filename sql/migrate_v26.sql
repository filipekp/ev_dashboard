-- EV Stats v5.6: spolehlivé LIVE jízdy a oprava telemetry trip historie.
-- last_seen_at zaznamenává i opakovaně potvrzený identický snapshot, který se
-- kvůli fingerprint deduplikaci nevkládá jako nový řádek.

ALTER TABLE vehicle_telemetry_snapshots
    ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER received_at;

UPDATE vehicle_telemetry_snapshots
SET last_seen_at=received_at
WHERE last_seen_at IS NULL;

CREATE TABLE IF NOT EXISTS vehicle_trip_rebuild_queue (
    connection_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(120) NOT NULL DEFAULT 'v26_live_trip_fix',
    queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_rebuild_connection FOREIGN KEY (connection_id)
        REFERENCES vehicle_connector_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_rebuild_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

INSERT IGNORE INTO vehicle_trip_rebuild_queue(connection_id,vehicle_id,reason)
SELECT DISTINCT c.id,c.vehicle_id,'v26_live_trip_fix'
FROM vehicle_connector_connections c
JOIN vehicle_telemetry_snapshots t ON t.connection_id=c.id
WHERE c.vehicle_id IS NOT NULL
  AND t.odometer_km IS NOT NULL;

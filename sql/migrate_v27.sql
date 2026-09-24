-- EV Stats v5.7: oprava časově nevěrohodných telemetry jízd a opakovaný rebuild.
--
-- Rebuild v26 mohl u historických odometrových skoků bez dostatečného časového
-- kontextu vytvořit nesmyslné rychlosti. v27 proto znovu zařadí všechny OEM
-- konektory do fronty; nový projektor takové intervaly raději vynechá, než aby
-- z nich odhadoval nereálnou dobu jízdy.

UPDATE vehicle_telemetry_snapshots
SET last_seen_at=received_at
WHERE last_seen_at IS NULL;

INSERT INTO vehicle_trip_rebuild_queue(connection_id,vehicle_id,reason,queued_at)
SELECT DISTINCT c.id,c.vehicle_id,'v27_timing_sanity',CURRENT_TIMESTAMP
FROM vehicle_connector_connections c
JOIN vehicle_telemetry_snapshots t ON t.connection_id=c.id
WHERE c.vehicle_id IS NOT NULL
  AND t.odometer_km IS NOT NULL
ON DUPLICATE KEY UPDATE
    vehicle_id=VALUES(vehicle_id),
    reason=VALUES(reason),
    queued_at=CURRENT_TIMESTAMP;

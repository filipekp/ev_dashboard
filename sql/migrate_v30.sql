-- Oprava spotřeby OEM jízd po průběžném nabíjení.
-- Přepočítáme pouze automatické telemetry jízdy z uložených raw snapshotů;
-- CSV a ruční jízdy zůstávají zachované (viz TripRepository).
INSERT INTO vehicle_trip_rebuild_queue(connection_id,vehicle_id,reason,queued_at)
SELECT DISTINCT c.id,c.vehicle_id,'v30_segment_consumption',CURRENT_TIMESTAMP
FROM vehicle_connector_connections c
JOIN vehicle_telemetry_snapshots t ON t.connection_id=c.id
WHERE c.vehicle_id IS NOT NULL
  AND t.odometer_km IS NOT NULL
ON DUPLICATE KEY UPDATE
    vehicle_id=VALUES(vehicle_id),
    reason=VALUES(reason),
    queued_at=CURRENT_TIMESTAMP;

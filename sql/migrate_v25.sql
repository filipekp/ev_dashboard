-- EV Stats v5.5: zive OEM jizdy prubezne aktualizovane z telemetrie.

ALTER TABLE trips
    ADD COLUMN trip_state ENUM('active','completed') NOT NULL DEFAULT 'completed' AFTER canonical_key,
    ADD COLUMN telemetry_connection_id BIGINT UNSIGNED NULL AFTER trip_state,
    ADD COLUMN telemetry_start_snapshot_id BIGINT UNSIGNED NULL AFTER telemetry_connection_id,
    ADD COLUMN telemetry_last_snapshot_id BIGINT UNSIGNED NULL AFTER telemetry_start_snapshot_id,
    ADD COLUMN telemetry_last_movement_at DATETIME NULL AFTER telemetry_last_snapshot_id,
    ADD KEY idx_trips_vehicle_state (vehicle_id, trip_state, started_at),
    ADD KEY idx_trips_telemetry_state (telemetry_connection_id, trip_state, telemetry_last_movement_at);

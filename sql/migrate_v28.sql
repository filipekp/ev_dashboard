-- EV Stats v5.8: Data Quality Engine, Trip Intelligence, Battery Health a Prediction foundation.
-- Podezrele raw snapshoty se zachovavaji pro audit, ale dostanou quality metadata.

ALTER TABLE vehicle_telemetry_snapshots
    ADD COLUMN IF NOT EXISTS quality_score TINYINT UNSIGNED NULL AFTER active_ventilation_duration_seconds,
    ADD COLUMN IF NOT EXISTS quality_status ENUM('unknown','good','warning','bad') NOT NULL DEFAULT 'unknown' AFTER quality_score,
    ADD COLUMN IF NOT EXISTS quality_flags_json TEXT NULL AFTER quality_status,
    ADD KEY idx_vehicle_telemetry_quality (vehicle_id, quality_status, observed_at);

ALTER TABLE trips
    ADD COLUMN IF NOT EXISTS quality_score TINYINT UNSIGNED NULL AFTER telemetry_last_movement_at,
    ADD COLUMN IF NOT EXISTS quality_status ENUM('unknown','good','warning','bad') NOT NULL DEFAULT 'unknown' AFTER quality_score,
    ADD COLUMN IF NOT EXISTS quality_issues_json TEXT NULL AFTER quality_status,
    ADD COLUMN IF NOT EXISTS quality_checked_at DATETIME NULL AFTER quality_issues_json,
    ADD COLUMN IF NOT EXISTS telemetry_point_count INT UNSIGNED NULL AFTER quality_checked_at,
    ADD COLUMN IF NOT EXISTS telemetry_bad_point_count INT UNSIGNED NULL AFTER telemetry_point_count,
    ADD COLUMN IF NOT EXISTS telemetry_gap_minutes DECIMAL(8,1) NULL AFTER telemetry_bad_point_count,
    ADD COLUMN IF NOT EXISTS max_speed_kmh DECIMAL(8,2) NULL AFTER telemetry_gap_minutes,
    ADD COLUMN IF NOT EXISTS avg_outside_temperature_c DECIMAL(6,2) NULL AFTER max_speed_kmh,
    ADD COLUMN IF NOT EXISTS avg_battery_temperature_c DECIMAL(6,2) NULL AFTER avg_outside_temperature_c,
    ADD COLUMN IF NOT EXISTS expected_consumption_kwh_100 DECIMAL(7,2) NULL AFTER avg_battery_temperature_c,
    ADD COLUMN IF NOT EXISTS consumption_delta_pct DECIMAL(8,2) NULL AFTER expected_consumption_kwh_100,
    ADD COLUMN IF NOT EXISTS efficiency_score TINYINT UNSIGNED NULL AFTER consumption_delta_pct,
    ADD COLUMN IF NOT EXISTS prediction_confidence_pct TINYINT UNSIGNED NULL AFTER efficiency_score,
    ADD COLUMN IF NOT EXISTS trip_intelligence_json TEXT NULL AFTER prediction_confidence_pct,
    ADD KEY idx_trips_vehicle_quality (vehicle_id, quality_status, started_at);

-- Po nasazeni noveho projektoru znovu sestavime pouze telemetry jizdy.
INSERT INTO vehicle_trip_rebuild_queue(connection_id,vehicle_id,reason,queued_at)
SELECT DISTINCT c.id,c.vehicle_id,'v28_data_quality_engine',CURRENT_TIMESTAMP
FROM vehicle_connector_connections c
JOIN vehicle_telemetry_snapshots t ON t.connection_id=c.id
WHERE c.vehicle_id IS NOT NULL
  AND t.odometer_km IS NOT NULL
ON DUPLICATE KEY UPDATE
    vehicle_id=VALUES(vehicle_id),
    reason=VALUES(reason),
    queued_at=CURRENT_TIMESTAMP;

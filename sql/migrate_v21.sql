-- EV Stats v5.1: direct OEM connector framework.
--
-- This migration intentionally performs a clean reset of the old Connected Car
-- / Enode storage introduced in v20. No old integration table is renamed or
-- reused. This is deliberate so the migration also succeeds when the legacy
-- vehicle_data_connections InnoDB tablespace is missing/corrupted.
--
-- WARNING: historical Connected Car telemetry, events and sync-run history from
-- the old v20/Enode integration are deleted by this migration.

SET FOREIGN_KEY_CHECKS = 0;

-- Remove a partially created v21 table, if a previous manual attempt created it.
DROP TABLE IF EXISTS vehicle_sync_runs;
DROP TABLE IF EXISTS vehicle_events;
DROP TABLE IF EXISTS vehicle_telemetry_snapshots;
DROP TABLE IF EXISTS vehicle_connector_connections;

-- Remove the complete legacy v20 / Enode integration layer.
DROP TABLE IF EXISTS vehicle_data_connections;
DROP TABLE IF EXISTS vehicle_data_accounts;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Per-vehicle OEM connector configuration.
-- ---------------------------------------------------------------------------
CREATE TABLE vehicle_connector_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    external_vehicle_id VARCHAR(190) NOT NULL,
    external_vin VARCHAR(64) NULL,
    external_name VARCHAR(190) NULL,
    capabilities_json LONGTEXT NULL,
    credentials_encrypted LONGTEXT NULL,
    credential_fingerprint CHAR(64) NULL,
    credential_hint VARCHAR(32) NULL,
    credential_expires_at DATETIME NULL,
    status ENUM('pending','active','disabled','error') NOT NULL DEFAULT 'pending',
    last_synced_at DATETIME NULL,
    next_sync_at DATETIME NULL,
    retry_after_at DATETIME NULL,
    rate_limit_limit INT UNSIGNED NULL,
    rate_limit_remaining INT UNSIGNED NULL,
    rate_limit_reset_at DATETIME NULL,
    metadata_json LONGTEXT NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_data_connection_external (provider, external_vehicle_id),
    UNIQUE KEY uq_vehicle_connector_vehicle_provider (vehicle_id, provider),
    KEY idx_vehicle_data_connection_vehicle (vehicle_id, status),
    KEY idx_vehicle_connector_user (user_id, status),
    KEY idx_vehicle_connector_sync (status, next_sync_at, retry_after_at),
    CONSTRAINT fk_vehicle_connector_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_data_connection_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Normalized telemetry snapshots shared by all OEM connectors.
-- ---------------------------------------------------------------------------
CREATE TABLE vehicle_telemetry_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NULL,
    source VARCHAR(40) NOT NULL,
    observed_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fingerprint CHAR(64) NOT NULL,
    soc_pct DECIMAL(5,2) NULL,
    range_km DECIMAL(9,2) NULL,
    odometer_km DECIMAL(12,2) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    is_charging TINYINT(1) NULL,
    is_plugged_in TINYINT(1) NULL,
    charging_power_kw DECIMAL(9,3) NULL,
    battery_temperature_c DECIMAL(6,2) NULL,
    fuel_level_pct DECIMAL(5,2) NULL,
    raw_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_telemetry_fingerprint (connection_id, fingerprint),
    KEY idx_vehicle_telemetry_vehicle_time (vehicle_id, observed_at),
    KEY idx_vehicle_telemetry_connection_time (connection_id, observed_at),
    CONSTRAINT fk_vehicle_telemetry_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_telemetry_connection
        FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Normalized vehicle events generated from telemetry changes.
-- ---------------------------------------------------------------------------
CREATE TABLE vehicle_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NULL,
    source VARCHAR(40) NOT NULL,
    event_key CHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    severity ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
    title VARCHAR(190) NOT NULL,
    occurred_at DATETIME NOT NULL,
    data_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_event_key (event_key),
    KEY idx_vehicle_event_vehicle_time (vehicle_id, occurred_at),
    KEY idx_vehicle_event_type_time (event_type, occurred_at),
    CONSTRAINT fk_vehicle_event_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_event_connection
        FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------------
-- Connector synchronization audit/history.
-- ---------------------------------------------------------------------------
CREATE TABLE vehicle_sync_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    trigger_type ENUM('manual','cron','webhook','initial') NOT NULL DEFAULT 'manual',
    status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    snapshots_created INT UNSIGNED NOT NULL DEFAULT 0,
    events_created INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vehicle_sync_connection_time (connection_id, started_at),
    KEY idx_vehicle_sync_status_time (status, started_at),
    CONSTRAINT fk_vehicle_sync_connection
        FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_sync_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

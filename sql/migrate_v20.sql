-- EV Stats v5.0: Connected Car / AutoSync foundation.
-- Adds provider accounts, vehicle mappings, normalized telemetry, event stream and sync history.

CREATE TABLE IF NOT EXISTS vehicle_data_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    external_user_id VARCHAR(190) NOT NULL,
    status ENUM('pending','active','needs_relink','disabled','error') NOT NULL DEFAULT 'pending',
    linked_vendor VARCHAR(120) NULL,
    last_error TEXT NULL,
    linked_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_data_account_external (provider, external_user_id),
    UNIQUE KEY uq_vehicle_data_account_user_provider (user_id, provider),
    KEY idx_vehicle_data_account_user (user_id, provider, status),
    CONSTRAINT fk_vehicle_data_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_data_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    external_vehicle_id VARCHAR(190) NOT NULL,
    external_vin VARCHAR(64) NULL,
    external_name VARCHAR(190) NULL,
    capabilities_json LONGTEXT NULL,
    status ENUM('discovered','active','disabled','error') NOT NULL DEFAULT 'discovered',
    last_synced_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_data_connection_external (provider, external_vehicle_id),
    KEY idx_vehicle_data_connection_vehicle (vehicle_id, status),
    KEY idx_vehicle_data_connection_account (account_id, status),
    CONSTRAINT fk_vehicle_data_connection_account FOREIGN KEY (account_id) REFERENCES vehicle_data_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_data_connection_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_telemetry_snapshots (
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
    CONSTRAINT fk_vehicle_telemetry_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_telemetry_connection FOREIGN KEY (connection_id) REFERENCES vehicle_data_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_events (
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
    CONSTRAINT fk_vehicle_event_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_event_connection FOREIGN KEY (connection_id) REFERENCES vehicle_data_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_sync_runs (
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
    CONSTRAINT fk_vehicle_sync_connection FOREIGN KEY (connection_id) REFERENCES vehicle_data_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

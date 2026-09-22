CREATE DATABASE IF NOT EXISTS ev_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
USE ev_stats;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
    parent_user_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at DATETIME NULL,
    default_vehicle_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    vin VARCHAR(32) NOT NULL UNIQUE,
    manufacturer VARCHAR(80) NULL,
    battery_kwh DECIMAL(7,2) NOT NULL DEFAULT 77.00,
    battery_nominal_kwh DECIMAL(7,2) NULL,
    soh_manual_pct DECIMAL(5,2) NULL,
    soh_manual_at DATETIME NULL,
    home_label VARCHAR(190) NULL,
    acquisition_date DATE NULL,
    acquisition_price DECIMAL(12,2) NULL,
    current_value DECIMAL(12,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

ALTER TABLE users
    ADD KEY idx_users_default_vehicle (default_vehicle_id),
    ADD CONSTRAINT fk_users_default_vehicle FOREIGN KEY (default_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL;

ALTER TABLE users
    ADD KEY idx_users_parent (parent_user_id),
    ADD CONSTRAINT fk_users_parent FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS user_vehicles (
    user_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, vehicle_id),
    KEY idx_uv_vehicle (vehicle_id),
    CONSTRAINT fk_uv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uv_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS user_vehicle_access (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    assigned_by_user_id BIGINT UNSIGNED NULL,
    valid_from DATETIME NOT NULL,
    valid_to DATETIME NULL,
    home_label VARCHAR(190) NULL,
    acquisition_date DATE NULL,
    acquisition_price DECIMAL(12,2) NULL,
    current_value DECIMAL(12,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uva_user_current (user_id, valid_to, vehicle_id),
    KEY idx_uva_vehicle_period (vehicle_id, valid_from, valid_to),
    CONSTRAINT fk_uva_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uva_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_uva_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS trips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    trip_hash CHAR(64) NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NOT NULL,
    classification VARCHAR(40) NULL,
    start_address VARCHAR(255) NOT NULL,
    end_address VARCHAR(255) NOT NULL,
    start_lat DECIMAL(10,7) NULL,
    start_lng DECIMAL(10,7) NULL,
    end_lat DECIMAL(10,7) NULL,
    end_lng DECIMAL(10,7) NULL,
    distance_km DECIMAL(9,2) NOT NULL,
    start_odometer_km DECIMAL(10,2) NULL,
    end_odometer_km DECIMAL(10,2) NULL,
    driving_minutes INT UNSIGNED NOT NULL,
    travel_minutes INT UNSIGNED NOT NULL,
    avg_speed_kmh DECIMAL(7,2) NULL,
    consumed_kwh DECIMAL(9,3) NULL,
    avg_consumption_kwh_100 DECIMAL(7,2) NULL,
    fuel_consumed_l DECIMAL(9,3) NULL,
    avg_fuel_consumption_l_100 DECIMAL(7,2) NULL,
    start_soc DECIMAL(5,2) NULL,
    end_soc DECIMAL(5,2) NULL,
    public_charging_stops INT UNSIGNED NOT NULL DEFAULT 0,
    public_charge_soc_gained DECIMAL(6,2) NOT NULL DEFAULT 0,
    short_trip TINYINT(1) NOT NULL DEFAULT 0,
    source_format VARCHAR(32) NOT NULL DEFAULT 'full',
    total_cost DECIMAL(12,2) NULL,
    total_cost_currency VARCHAR(8) NULL,
    electricity_cost DECIMAL(12,2) NULL,
    electricity_cost_currency VARCHAR(8) NULL,
    electricity_price_per_kwh DECIMAL(10,4) NULL,
    avg_aux_consumption_kwh_100 DECIMAL(8,2) NULL,
    avg_recuperation_kwh_100 DECIMAL(8,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_trip_hash (vehicle_id, trip_hash),
    KEY idx_vehicle_started (vehicle_id, started_at),
    KEY idx_vehicle_route (vehicle_id, start_address(80), end_address(80)),
    CONSTRAINT fk_trips_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


CREATE TABLE IF NOT EXISTS registration_verification_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_registration_token_user (user_id),
    KEY idx_registration_token_expiry (expires_at, used_at),
    CONSTRAINT fk_registration_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;


CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_prt_user (user_id),
    KEY idx_prt_expires (expires_at),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL UNIQUE,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- v7: univerzální vozidla a provozní evidence
ALTER TABLE vehicles
    ADD COLUMN IF NOT EXISTS powertrain_type ENUM('BEV','PHEV','HEV','PETROL','DIESEL','LPG','CNG') NOT NULL DEFAULT 'BEV' AFTER vin,
    ADD COLUMN IF NOT EXISTS fuel_tank_l DECIMAL(7,2) NULL AFTER battery_nominal_kwh,
    ADD COLUMN IF NOT EXISTS registration_plate VARCHAR(32) NULL AFTER fuel_tank_l,
    ADD COLUMN IF NOT EXISTS first_registration_date DATE NULL AFTER registration_plate,
    ADD COLUMN IF NOT EXISTS odometer_km DECIMAL(12,2) NULL AFTER first_registration_date;

ALTER TABLE trips
    ADD COLUMN IF NOT EXISTS trip_note VARCHAR(500) NULL AFTER classification;

CREATE TABLE IF NOT EXISTS vehicle_energy_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    entry_type ENUM('charging','fueling') NOT NULL,
    energy_type ENUM('electricity','petrol','diesel','lpg','cng') NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit ENUM('kWh','l','kg') NOT NULL,
    unit_price DECIMAL(12,4) NULL,
    total_price DECIMAL(12,2) NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
    odometer_km DECIMAL(12,2) NULL,
    station VARCHAR(190) NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_energy_vehicle_date (vehicle_id, occurred_at),
    CONSTRAINT fk_energy_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_service_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    serviced_at DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    title VARCHAR(190) NOT NULL,
    provider VARCHAR(190) NULL,
    odometer_km DECIMAL(12,2) NULL,
    cost DECIMAL(12,2) NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_service_vehicle_date (vehicle_id, serviced_at),
    CONSTRAINT fk_service_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_service_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_record_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attachment_service (service_record_id),
    CONSTRAINT fk_attachment_service FOREIGN KEY (service_record_id) REFERENCES vehicle_service_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    occurred_at DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    title VARCHAR(190) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
    odometer_km DECIMAL(12,2) NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expense_vehicle_date (vehicle_id, occurred_at),
    CONSTRAINT fk_expense_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    category VARCHAR(80) NOT NULL,
    due_date DATE NULL,
    due_odometer_km DECIMAL(12,2) NULL,
    note VARCHAR(500) NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reminder_vehicle_due (vehicle_id, completed_at, due_date),
    CONSTRAINT fk_reminder_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- v13/v14: dokumentové centrum, AI importy a fotografie vozidel
CREATE TABLE IF NOT EXISTS vehicle_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    media_type ENUM('photo') NOT NULL DEFAULT 'photo',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    caption VARCHAR(190) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vehicle_media_vehicle (vehicle_id, is_primary, id),
    CONSTRAINT fk_vehicle_media_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_media_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('unknown','fuel_receipt','charging_invoice','service_invoice','expense_receipt','insurance','inspection','registration','other') NOT NULL DEFAULT 'unknown',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    provider VARCHAR(190) NULL,
    document_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_document_sha (vehicle_id, sha256),
    KEY idx_vehicle_documents_vehicle_date (vehicle_id, document_date, created_at),
    CONSTRAINT fk_vehicle_documents_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_documents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS document_import_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    extractor VARCHAR(80) NULL,
    status ENUM('uploaded','processing','review','confirmed','error') NOT NULL DEFAULT 'uploaded',
    confidence DECIMAL(5,4) NULL,
    extracted_json LONGTEXT NULL,
    error_message TEXT NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_document_import_vehicle_status (vehicle_id, status, created_at),
    KEY idx_document_import_document (document_id),
    CONSTRAINT fk_document_import_document FOREIGN KEY (document_id) REFERENCES vehicle_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_import_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_import_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS document_operation_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    operation_type ENUM('energy','service','expense') NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_operation (document_id, operation_type, operation_id),
    KEY idx_document_operation_vehicle (vehicle_id, operation_type, operation_id),
    KEY idx_document_operation_document (document_id),
    CONSTRAINT fk_document_operation_document FOREIGN KEY (document_id) REFERENCES vehicle_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_operation_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_trip_book_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    source_trip_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    start_address VARCHAR(255) NOT NULL,
    end_address VARCHAR(255) NOT NULL,
    distance_km DECIMAL(9,2) NOT NULL,
    start_odometer_km DECIMAL(10,2) NULL,
    end_odometer_km DECIMAL(10,2) NULL,
    classification VARCHAR(40) NULL,
    purpose VARCHAR(190) NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_trip_book_vehicle_started (vehicle_id, started_at),
    KEY idx_trip_book_source (source_trip_id),
    CONSTRAINT fk_trip_book_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_book_source FOREIGN KEY (source_trip_id) REFERENCES trips(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- v18: archiv neznámých importních formátů a ruční univerzální mapper
CREATE TABLE IF NOT EXISTS unknown_import_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(48) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    integration_import_run_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    file_format VARCHAR(20) NOT NULL,
    headers_json LONGTEXT NOT NULL,
    mapping_json LONGTEXT NULL,
    status ENUM('pending_mapping','imported','failed') NOT NULL DEFAULT 'pending_mapping',
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    mapped_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_unknown_import_user_date (user_id, created_at),
    KEY idx_unknown_import_vehicle_date (vehicle_id, created_at),
    KEY idx_unknown_import_sha (sha256),
    CONSTRAINT fk_unknown_import_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_unknown_import_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_unknown_import_run FOREIGN KEY (integration_import_run_id) REFERENCES integration_import_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- v21: direct OEM Connected Car / AutoSync foundation
CREATE TABLE IF NOT EXISTS vehicle_connector_connections (
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
    CONSTRAINT fk_vehicle_connector_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
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
    parking_address VARCHAR(255) NULL,
    air_conditioning_state VARCHAR(64) NULL,
    air_conditioning_target_c DECIMAL(5,2) NULL,
    air_conditioning_without_external_power TINYINT(1) NULL,
    air_conditioning_at_unlock TINYINT(1) NULL,
    window_heating_front VARCHAR(24) NULL,
    window_heating_rear VARCHAR(24) NULL,
    auxiliary_heating_state VARCHAR(64) NULL,
    auxiliary_heating_start_mode VARCHAR(64) NULL,
    auxiliary_heating_duration_seconds INT UNSIGNED NULL,
    auxiliary_heating_target_c DECIMAL(5,2) NULL,
    active_ventilation_state VARCHAR(64) NULL,
    active_ventilation_duration_seconds INT UNSIGNED NULL,
    raw_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_telemetry_fingerprint (connection_id, fingerprint),
    KEY idx_vehicle_telemetry_vehicle_time (vehicle_id, observed_at),
    KEY idx_vehicle_telemetry_connection_time (connection_id, observed_at),
    CONSTRAINT fk_vehicle_telemetry_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_telemetry_connection FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE SET NULL
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
    CONSTRAINT fk_vehicle_event_connection FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE SET NULL
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
    CONSTRAINT fk_vehicle_sync_connection FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- EV Stats v5.2: odvozene jizdy/nabijeni z OEM telemetrie a deduplikace importu.

ALTER TABLE vehicles
    ADD COLUMN default_electricity_price_per_kwh DECIMAL(12,4) NULL AFTER home_label,
    ADD COLUMN default_energy_currency VARCHAR(8) NOT NULL DEFAULT 'CZK' AFTER default_electricity_price_per_kwh;

ALTER TABLE trips
    ADD COLUMN canonical_key CHAR(64) NULL AFTER trip_hash,
    ADD UNIQUE KEY uq_vehicle_trip_canonical (vehicle_id, canonical_key);

ALTER TABLE vehicle_energy_entries
    ADD COLUMN ended_at DATETIME NULL AFTER occurred_at,
    ADD COLUMN start_soc DECIMAL(5,2) NULL AFTER odometer_km,
    ADD COLUMN end_soc DECIMAL(5,2) NULL AFTER start_soc,
    ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER note,
    ADD COLUMN source_key CHAR(64) NULL AFTER source,
    ADD COLUMN is_estimated TINYINT(1) NOT NULL DEFAULT 0 AFTER source_key,
    ADD COLUMN price_source ENUM('none','default','manual','document') NOT NULL DEFAULT 'none' AFTER is_estimated,
    ADD UNIQUE KEY uq_energy_vehicle_source_key (vehicle_id, source_key),
    ADD KEY idx_energy_vehicle_source (vehicle_id, source, occurred_at);

-- Historicke polozky, ktere prokazatelne vznikly potvrzenim dokladu, oznacime jako dokumentove.
UPDATE vehicle_energy_entries e
JOIN document_operation_links l
  ON l.operation_type='energy' AND l.operation_id=e.id AND l.vehicle_id=e.vehicle_id
SET e.source='document',
    e.price_source=CASE WHEN e.unit_price IS NULL AND e.total_price IS NULL THEN 'none' ELSE 'document' END;

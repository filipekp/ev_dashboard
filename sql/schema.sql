CREATE DATABASE IF NOT EXISTS ev_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
USE ev_stats;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
    active TINYINT(1) NOT NULL DEFAULT 1,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

ALTER TABLE users
    ADD KEY idx_users_default_vehicle (default_vehicle_id),
    ADD CONSTRAINT fk_users_default_vehicle FOREIGN KEY (default_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS user_vehicles (
    user_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, vehicle_id),
    KEY idx_uv_vehicle (vehicle_id),
    CONSTRAINT fk_uv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uv_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
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

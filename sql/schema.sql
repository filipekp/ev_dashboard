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

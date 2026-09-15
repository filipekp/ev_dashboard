ALTER TABLE vehicles
    ADD COLUMN powertrain_type ENUM('BEV','PHEV','HEV','PETROL','DIESEL','LPG','CNG') NOT NULL DEFAULT 'BEV' AFTER vin,
    ADD COLUMN fuel_tank_l DECIMAL(7,2) NULL AFTER battery_nominal_kwh,
    ADD COLUMN registration_plate VARCHAR(32) NULL AFTER fuel_tank_l,
    ADD COLUMN first_registration_date DATE NULL AFTER registration_plate,
    ADD COLUMN odometer_km DECIMAL(12,2) NULL AFTER first_registration_date;

ALTER TABLE trips
    ADD COLUMN trip_note VARCHAR(500) NULL AFTER classification;

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

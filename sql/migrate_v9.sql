-- EV Stats v3.x integration foundation: uložené profily Generic CSV mapperu.

CREATE TABLE IF NOT EXISTS csv_mapping_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    mapping_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_csv_mapping_user (user_id),
    CONSTRAINT fk_csv_mapping_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS integration_import_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(64) NOT NULL,
    source_label VARCHAR(190) NULL,
    status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_value VARCHAR(500) NULL,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    KEY idx_import_runs_vehicle_date (vehicle_id, started_at),
    CONSTRAINT fk_import_runs_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

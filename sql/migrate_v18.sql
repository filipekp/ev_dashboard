-- EV Stats v4.x: archiv neznámých importních formátů a ruční univerzální mapper.

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

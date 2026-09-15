-- EV Stats v4.1: samostatná ruční kniha jízd.
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

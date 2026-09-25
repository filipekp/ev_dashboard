-- EV Stats v5.8.1: bezpečné mazání jízd a ochrana proti znovuvytvoření smazaných telemetry jízd.

CREATE TABLE IF NOT EXISTS trip_deletion_tombstones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    trip_hash CHAR(64) NULL,
    telemetry_start_snapshot_id BIGINT UNSIGNED NOT NULL,
    telemetry_last_snapshot_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    deleted_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trip_deletion_range (
        vehicle_id,
        connection_id,
        telemetry_start_snapshot_id,
        telemetry_last_snapshot_id
    ),
    KEY idx_trip_deletion_connection_range (
        connection_id,
        telemetry_start_snapshot_id,
        telemetry_last_snapshot_id
    ),
    KEY idx_trip_deletion_vehicle_created (vehicle_id, created_at),
    CONSTRAINT fk_trip_deletion_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_deletion_connection
        FOREIGN KEY (connection_id) REFERENCES vehicle_connector_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_deletion_user
        FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

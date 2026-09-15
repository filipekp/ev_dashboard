-- EV Stats v5: výchozí vozidlo uživatele.
-- Spusťte nad existující databází po migrate_v4.sql.

ALTER TABLE users
    ADD COLUMN default_vehicle_id BIGINT UNSIGNED NULL AFTER active,
    ADD KEY idx_users_default_vehicle (default_vehicle_id),
    ADD CONSTRAINT fk_users_default_vehicle
        FOREIGN KEY (default_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL;

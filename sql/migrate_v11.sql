-- EV Stats v3.x: audit importů je vázaný i na uživatele a přežije odstranění prázdného vozidla.

ALTER TABLE integration_import_runs
    ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE integration_import_runs
    DROP FOREIGN KEY fk_import_runs_vehicle;

ALTER TABLE integration_import_runs
    MODIFY vehicle_id BIGINT UNSIGNED NULL;

ALTER TABLE integration_import_runs
    ADD CONSTRAINT fk_import_runs_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL;

ALTER TABLE integration_import_runs
    ADD KEY idx_import_runs_user_date (user_id, started_at);

ALTER TABLE integration_import_runs
    ADD CONSTRAINT fk_import_runs_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

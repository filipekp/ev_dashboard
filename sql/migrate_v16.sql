-- v16: hierarchie uživatelů a časově omezený přístup k vozidlům
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS parent_user_id BIGINT UNSIGNED NULL AFTER role;

ALTER TABLE users
    ADD KEY idx_users_parent (parent_user_id);

ALTER TABLE users
    ADD CONSTRAINT fk_users_parent
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE SET NULL;

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

-- Převede současná přiřazení na otevřené přístupové intervaly.
INSERT INTO user_vehicle_access (user_id, vehicle_id, assigned_by_user_id, valid_from, valid_to, home_label, acquisition_date, acquisition_price, current_value)
SELECT uv.user_id, uv.vehicle_id, NULL, COALESCE(uv.created_at, NOW()), NULL, v.home_label, v.acquisition_date, v.acquisition_price, v.current_value
FROM user_vehicles uv
JOIN vehicles v ON v.id=uv.vehicle_id
WHERE NOT EXISTS (
    SELECT 1
    FROM user_vehicle_access uva
    WHERE uva.user_id = uv.user_id
      AND uva.vehicle_id = uv.vehicle_id
      AND uva.valid_to IS NULL
);

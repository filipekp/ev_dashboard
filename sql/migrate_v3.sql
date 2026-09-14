USE ev_stats;

ALTER TABLE users
  MODIFY role ENUM('admin','manager','user') NOT NULL DEFAULT 'user';

ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS battery_nominal_kwh DECIMAL(7,2) NULL AFTER battery_kwh,
  ADD COLUMN IF NOT EXISTS soh_manual_pct DECIMAL(5,2) NULL AFTER battery_nominal_kwh,
  ADD COLUMN IF NOT EXISTS soh_manual_at DATETIME NULL AFTER soh_manual_pct;

UPDATE vehicles SET battery_nominal_kwh = battery_kwh WHERE battery_nominal_kwh IS NULL;

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

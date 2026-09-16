-- v17: veřejná registrace a dvoufázové ověření e-mailu
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER active;

-- Stávající aktivní účty považujeme za ověřené, aby se migrací nezměnilo jejich chování.
UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at, NOW())
WHERE active = 1;

CREATE TABLE IF NOT EXISTS registration_verification_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_registration_token_user (user_id),
    KEY idx_registration_token_expiry (expires_at, used_at),
    CONSTRAINT fk_registration_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

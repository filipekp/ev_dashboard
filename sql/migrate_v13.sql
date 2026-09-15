-- EV Stats v4: media vozidel, dokumentové centrum a AI import dokladů.

CREATE TABLE IF NOT EXISTS vehicle_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    media_type ENUM('photo') NOT NULL DEFAULT 'photo',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    caption VARCHAR(190) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vehicle_media_vehicle (vehicle_id, is_primary, id),
    CONSTRAINT fk_vehicle_media_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_media_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS vehicle_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('unknown','fuel_receipt','charging_invoice','service_invoice','expense_receipt','insurance','inspection','registration','other') NOT NULL DEFAULT 'unknown',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    provider VARCHAR(190) NULL,
    document_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_document_sha (vehicle_id, sha256),
    KEY idx_vehicle_documents_vehicle_date (vehicle_id, document_date, created_at),
    CONSTRAINT fk_vehicle_documents_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_documents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS document_import_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    extractor VARCHAR(80) NULL,
    status ENUM('uploaded','processing','review','confirmed','error') NOT NULL DEFAULT 'uploaded',
    confidence DECIMAL(5,4) NULL,
    extracted_json LONGTEXT NULL,
    error_message TEXT NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_document_import_vehicle_status (vehicle_id, status, created_at),
    KEY idx_document_import_document (document_id),
    CONSTRAINT fk_document_import_document FOREIGN KEY (document_id) REFERENCES vehicle_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_import_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_import_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Vazby mezi dokladem a vytvořenými provozními záznamy držíme odděleně.
-- Záměrně tím neměníme existující provozní tabulky: je to bezpečnější na
-- shared hostingu a vyhneme se drahým/poruchovým ALTER TABLE operacím.
CREATE TABLE IF NOT EXISTS document_operation_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    operation_type ENUM('energy','service','expense') NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_operation (document_id, operation_type, operation_id),
    KEY idx_document_operation_vehicle (vehicle_id, operation_type, operation_id),
    KEY idx_document_operation_document (document_id),
    CONSTRAINT fk_document_operation_document FOREIGN KEY (document_id) REFERENCES vehicle_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_operation_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

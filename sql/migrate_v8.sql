-- EV Stats v3.0 analytics: pořizovací údaje pro plné TCO.

ALTER TABLE vehicles
    ADD COLUMN acquisition_date DATE NULL AFTER first_registration_date,
    ADD COLUMN acquisition_price DECIMAL(14,2) NULL AFTER acquisition_date,
    ADD COLUMN current_value DECIMAL(14,2) NULL AFTER acquisition_price;

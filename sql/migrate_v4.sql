-- EV Stats v4: podpora CSV exportu Citigo iV a zachování nákladových/energetických údajů.
-- Spusťte nad existující databází po migrate_v3.sql.

ALTER TABLE trips
    ADD COLUMN IF NOT EXISTS source_format VARCHAR(32) NOT NULL DEFAULT 'full' AFTER short_trip,
    ADD COLUMN IF NOT EXISTS total_cost DECIMAL(12,2) NULL AFTER source_format,
    ADD COLUMN IF NOT EXISTS total_cost_currency VARCHAR(8) NULL AFTER total_cost,
    ADD COLUMN IF NOT EXISTS electricity_cost DECIMAL(12,2) NULL AFTER total_cost_currency,
    ADD COLUMN IF NOT EXISTS electricity_cost_currency VARCHAR(8) NULL AFTER electricity_cost,
    ADD COLUMN IF NOT EXISTS electricity_price_per_kwh DECIMAL(10,4) NULL AFTER electricity_cost_currency,
    ADD COLUMN IF NOT EXISTS avg_aux_consumption_kwh_100 DECIMAL(8,2) NULL AFTER electricity_price_per_kwh,
    ADD COLUMN IF NOT EXISTS avg_recuperation_kwh_100 DECIMAL(8,2) NULL AFTER avg_aux_consumption_kwh_100;

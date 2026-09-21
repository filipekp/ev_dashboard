-- EV Stats v5.2: odvozene jizdy/nabijeni z OEM telemetrie a deduplikace importu.

ALTER TABLE vehicles
    ADD COLUMN default_electricity_price_per_kwh DECIMAL(12,4) NULL AFTER home_label,
    ADD COLUMN default_energy_currency VARCHAR(8) NOT NULL DEFAULT 'CZK' AFTER default_electricity_price_per_kwh;

ALTER TABLE trips
    ADD COLUMN canonical_key CHAR(64) NULL AFTER trip_hash,
    ADD UNIQUE KEY uq_vehicle_trip_canonical (vehicle_id, canonical_key);

ALTER TABLE vehicle_energy_entries
    ADD COLUMN ended_at DATETIME NULL AFTER occurred_at,
    ADD COLUMN start_soc DECIMAL(5,2) NULL AFTER odometer_km,
    ADD COLUMN end_soc DECIMAL(5,2) NULL AFTER start_soc,
    ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER note,
    ADD COLUMN source_key CHAR(64) NULL AFTER source,
    ADD COLUMN is_estimated TINYINT(1) NOT NULL DEFAULT 0 AFTER source_key,
    ADD COLUMN price_source ENUM('none','default','manual','document') NOT NULL DEFAULT 'none' AFTER is_estimated,
    ADD UNIQUE KEY uq_energy_vehicle_source_key (vehicle_id, source_key),
    ADD KEY idx_energy_vehicle_source (vehicle_id, source, occurred_at);

-- Historicke polozky, ktere prokazatelne vznikly potvrzenim dokladu, oznacime jako dokumentove.
UPDATE vehicle_energy_entries e
JOIN document_operation_links l
  ON l.operation_type='energy' AND l.operation_id=e.id AND l.vehicle_id=e.vehicle_id
SET e.source='document',
    e.price_source=CASE WHEN e.unit_price IS NULL AND e.total_price IS NULL THEN 'none' ELSE 'document' END;

-- EV Stats v5.3: rozsirena Skoda telemetrie, parkovaci adresa a klima stav.

ALTER TABLE vehicle_telemetry_snapshots
    ADD COLUMN parking_address VARCHAR(255) NULL AFTER longitude,
    ADD COLUMN air_conditioning_state VARCHAR(64) NULL AFTER fuel_level_pct,
    ADD COLUMN air_conditioning_target_c DECIMAL(5,2) NULL AFTER air_conditioning_state,
    ADD COLUMN air_conditioning_without_external_power TINYINT(1) NULL AFTER air_conditioning_target_c,
    ADD COLUMN air_conditioning_at_unlock TINYINT(1) NULL AFTER air_conditioning_without_external_power,
    ADD COLUMN window_heating_front VARCHAR(24) NULL AFTER air_conditioning_at_unlock,
    ADD COLUMN window_heating_rear VARCHAR(24) NULL AFTER window_heating_front,
    ADD COLUMN auxiliary_heating_state VARCHAR(64) NULL AFTER window_heating_rear,
    ADD COLUMN auxiliary_heating_start_mode VARCHAR(64) NULL AFTER auxiliary_heating_state,
    ADD COLUMN auxiliary_heating_duration_seconds INT UNSIGNED NULL AFTER auxiliary_heating_start_mode,
    ADD COLUMN auxiliary_heating_target_c DECIMAL(5,2) NULL AFTER auxiliary_heating_duration_seconds,
    ADD COLUMN active_ventilation_state VARCHAR(64) NULL AFTER auxiliary_heating_target_c,
    ADD COLUMN active_ventilation_duration_seconds INT UNSIGNED NULL AFTER active_ventilation_state;

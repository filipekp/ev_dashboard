-- EV Stats v5.4: rozsirena Kia/Pleos telemetrie pro nabíjení, jízdu a klimatizaci.

ALTER TABLE vehicle_telemetry_snapshots
    ADD COLUMN vehicle_speed_kmh DECIMAL(8,2) NULL AFTER longitude,
    ADD COLUMN charging_status VARCHAR(32) NULL AFTER charging_power_kw,
    ADD COLUMN charging_plugin_status VARCHAR(24) NULL AFTER charging_status,
    ADD COLUMN charging_target_standard_pct DECIMAL(5,2) NULL AFTER charging_plugin_status,
    ADD COLUMN charging_target_quick_pct DECIMAL(5,2) NULL AFTER charging_target_standard_pct,
    ADD COLUMN charging_remaining_minutes INT UNSIGNED NULL AFTER charging_target_quick_pct,
    ADD COLUMN ignition_status VARCHAR(24) NULL AFTER fuel_level_pct,
    ADD COLUMN sleep_mode VARCHAR(24) NULL AFTER ignition_status,
    ADD COLUMN is_parked TINYINT(1) NULL AFTER sleep_mode,
    ADD COLUMN climate_control_mode VARCHAR(24) NULL AFTER air_conditioning_target_c,
    ADD COLUMN climate_blower_speed SMALLINT NULL AFTER climate_control_mode,
    ADD COLUMN windshield_defrost_state VARCHAR(24) NULL AFTER window_heating_rear,
    ADD COLUMN steering_wheel_heat_state VARCHAR(24) NULL AFTER windshield_defrost_state,
    ADD COLUMN outside_temperature_c DECIMAL(5,2) NULL AFTER steering_wheel_heat_state;

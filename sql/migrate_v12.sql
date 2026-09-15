-- Normalize Kia Connect energy to net battery consumption.
-- Kia exports gross consumed energy and recuperation separately, while other
-- vehicle sources typically expose consumption already net of recuperation.
-- avg_recuperation_kwh_100 lets us reconstruct recuperated kWh for existing rows.
UPDATE trips
SET avg_consumption_kwh_100 = CASE
        WHEN distance_km > 0 THEN
            GREATEST(
                0,
                consumed_kwh - COALESCE(avg_recuperation_kwh_100, 0) * distance_km / 100
            ) / distance_km * 100
        ELSE NULL
    END,
    consumed_kwh = GREATEST(
        0,
        consumed_kwh - COALESCE(avg_recuperation_kwh_100, 0) * distance_km / 100
    )
WHERE source_format = 'kia_ev'
  AND consumed_kwh IS NOT NULL;

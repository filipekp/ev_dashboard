-- EV Stats v4.x: veřejná landing page a read-only demo účet.
-- Demo účet nemá použitelné heslo; přihlášení probíhá pouze přes demo-login.php.

INSERT INTO users (name, email, password_hash, role, active, email_verified_at)
SELECT 'Demo uživatel', 'demo@evstats.local', '!demo-login-only!', 'user', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'demo@evstats.local');

INSERT INTO vehicles (
    name, vin, powertrain_type, manufacturer, battery_kwh, battery_nominal_kwh,
    registration_plate, first_registration_date, odometer_km, soh_manual_pct, soh_manual_at
)
SELECT
    'Škoda Elroq 85 · DEMO', 'TMBDEMOEVSTATS2026', 'BEV', 'SKODA', 77.00, 82.00,
    'DEMO EV', '2026-03-18', 28960.00, 98.40, '2026-09-01 12:00:00'
WHERE NOT EXISTS (SELECT 1 FROM vehicles WHERE vin = 'TMBDEMOEVSTATS2026');

SET @demo_user_id := (SELECT id FROM users WHERE email = 'demo@evstats.local' LIMIT 1);
SET @demo_vehicle_id := (SELECT id FROM vehicles WHERE vin = 'TMBDEMOEVSTATS2026' LIMIT 1);

INSERT IGNORE INTO user_vehicles (user_id, vehicle_id) VALUES (@demo_user_id, @demo_vehicle_id);

INSERT INTO user_vehicle_access (
    user_id, vehicle_id, assigned_by_user_id, valid_from, valid_to,
    home_label, acquisition_date, acquisition_price, current_value
)
SELECT @demo_user_id, @demo_vehicle_id, NULL, '2026-03-18 08:00:00', NULL,
       'Domov', '2026-03-18', 1049000.00, 970000.00
WHERE NOT EXISTS (
    SELECT 1 FROM user_vehicle_access
    WHERE user_id = @demo_user_id AND vehicle_id = @demo_vehicle_id AND valid_to IS NULL
);

UPDATE users SET default_vehicle_id = @demo_vehicle_id WHERE id = @demo_user_id;

INSERT INTO trips (
    vehicle_id, trip_hash, started_at, ended_at, classification, trip_note,
    start_address, end_address, distance_km, start_odometer_km, end_odometer_km,
    driving_minutes, travel_minutes, avg_speed_kmh, consumed_kwh, avg_consumption_kwh_100,
    start_soc, end_soc, public_charging_stops, public_charge_soc_gained, short_trip, source_format
)
SELECT @demo_vehicle_id, SHA2(CONCAT('demo-trip-', seq), 256), started_at, ended_at, classification, note,
       start_address, end_address, distance_km, start_odometer_km, end_odometer_km,
       driving_minutes, travel_minutes, avg_speed_kmh, consumed_kwh, avg_consumption,
       start_soc, end_soc, public_stops, soc_gained, IF(distance_km <= 5, 1, 0), 'demo'
FROM (
    SELECT 1 seq,'2026-08-03 05:42:00' started_at,'2026-08-03 06:11:00' ended_at,'business' classification,'Ranní dojíždění' note,'Olomouc' start_address,'Prostějov' end_address,24.8 distance_km,27060.0 start_odometer_km,27084.8 end_odometer_km,27 driving_minutes,29 travel_minutes,55.1 avg_speed_kmh,4.12 consumed_kwh,16.6 avg_consumption,82 start_soc,76 end_soc,0 public_stops,0 soc_gained
    UNION ALL SELECT 2,'2026-08-03 14:18:00','2026-08-03 14:53:00','business','Odpolední návrat','Prostějov','Olomouc',30.5,27084.8,27115.3,32,35,57.2,5.31,17.4,76,69,0,0
    UNION ALL SELECT 3,'2026-08-07 07:09:00','2026-08-07 07:34:00','private','Městská jízda','Olomouc','Olomouc',12.4,27198.0,27210.4,24,25,31.0,2.01,16.2,61,58,0,0
    UNION ALL SELECT 4,'2026-08-12 12:12:00','2026-08-12 13:06:00','business','Okresní trasa','Olomouc','Přerov',42.1,27322.0,27364.1,48,54,52.6,7.16,17.0,88,79,0,0
    UNION ALL SELECT 5,'2026-08-18 14:04:00','2026-08-18 14:38:00','business','Pravidelná trasa','Přerov','Olomouc',30.7,27520.2,27550.9,31,34,59.4,4.21,13.7,66,60,0,0
    UNION ALL SELECT 6,'2026-08-23 05:18:00','2026-08-23 06:26:00','private','Dálnice','Olomouc','Brno',79.8,27701.1,27780.9,62,68,77.2,17.08,21.4,91,69,1,25
    UNION ALL SELECT 7,'2026-08-23 20:05:00','2026-08-23 21:13:00','private','Návrat z výletu','Brno','Olomouc',80.2,27780.9,27861.1,63,68,76.4,15.96,19.9,78,57,0,0
    UNION ALL SELECT 8,'2026-09-02 07:07:00','2026-09-02 07:34:00','business','Ranní dojíždění','Olomouc','Prostějov',24.7,28150.0,28174.7,25,27,59.3,3.43,13.9,73,68,0,0
    UNION ALL SELECT 9,'2026-09-02 15:14:00','2026-09-02 15:48:00','business','Odpolední návrat','Prostějov','Olomouc',30.6,28174.7,28205.3,31,34,59.2,4.22,13.8,68,62,0,0
    UNION ALL SELECT 10,'2026-09-05 17:02:00','2026-09-05 17:18:00','private','Krátká jízda','Olomouc','Olomouc',7.9,28278.0,28285.9,15,16,31.6,1.29,16.3,57,55,0,0
    UNION ALL SELECT 11,'2026-09-08 12:06:00','2026-09-08 12:54:00','business','Okresní trasa','Olomouc','Přerov',41.8,28362.0,28403.8,45,48,55.7,7.06,16.9,84,75,0,0
    UNION ALL SELECT 12,'2026-09-09 14:03:00','2026-09-09 14:39:00','business','Pravidelná trasa','Přerov','Olomouc',31.2,28403.8,28435.0,33,36,56.7,4.33,13.9,75,69,0,0
    UNION ALL SELECT 13,'2026-09-12 05:22:00','2026-09-12 06:25:00','private','Dálnice','Olomouc','Brno',79.4,28612.0,28691.4,58,63,82.1,16.52,20.8,93,72,1,22
    UNION ALL SELECT 14,'2026-09-12 20:08:00','2026-09-12 21:10:00','private','Návrat','Brno','Olomouc',79.8,28691.4,28771.2,58,62,82.6,15.72,19.7,80,60,0,0
    UNION ALL SELECT 15,'2026-09-15 07:11:00','2026-09-15 07:38:00','business','Ranní dojíždění','Olomouc','Prostějov',24.6,28904.2,28928.8,25,27,59.0,4.08,16.6,55,49,0,0
    UNION ALL SELECT 16,'2026-09-15 14:14:00','2026-09-15 14:49:00','business','Odpolední návrat','Prostějov','Olomouc',31.2,28928.8,28960.0,32,35,58.5,4.31,13.8,49,43,0,0
) demo_trips
WHERE NOT EXISTS (
    SELECT 1 FROM trips t WHERE t.vehicle_id = @demo_vehicle_id AND t.trip_hash = SHA2(CONCAT('demo-trip-', seq), 256)
);

INSERT INTO vehicle_energy_entries (vehicle_id, occurred_at, entry_type, energy_type, quantity, unit, unit_price, total_price, currency, odometer_km, station, note)
SELECT @demo_vehicle_id, occurred_at, 'charging', 'electricity', quantity, 'kWh', unit_price, total_price, 'CZK', odometer, station, note
FROM (
    SELECT '2026-08-10 20:30:00' occurred_at, 46.2 quantity, 4.50 unit_price, 207.90 total_price, 27284 odometer, 'Domácí wallbox' station, 'Noční nabíjení' note
    UNION ALL SELECT '2026-08-23 07:18:00', 24.8, 12.90, 319.92, 27781, 'D1 · rychlonabíjení', 'Veřejné DC'
    UNION ALL SELECT '2026-09-04 21:05:00', 51.6, 4.50, 232.20, 28254, 'Domácí wallbox', 'Noční nabíjení'
    UNION ALL SELECT '2026-09-12 07:02:00', 28.4, 12.90, 366.36, 28691, 'D1 · rychlonabíjení', 'Veřejné DC'
    UNION ALL SELECT '2026-09-15 22:10:00', 26.1, 4.50, 117.45, 28960, 'Domácí wallbox', 'Doplnění energie'
) demo_energy
WHERE NOT EXISTS (SELECT 1 FROM vehicle_energy_entries WHERE vehicle_id = @demo_vehicle_id AND note = demo_energy.note AND occurred_at = demo_energy.occurred_at);

-- EV Stats v15: spotřeba paliva u jízd spalovacích a hybridních vozidel
ALTER TABLE trips
    ADD COLUMN fuel_consumed_l DECIMAL(9,3) NULL AFTER avg_consumption_kwh_100,
    ADD COLUMN avg_fuel_consumption_l_100 DECIMAL(7,2) NULL AFTER fuel_consumed_l;

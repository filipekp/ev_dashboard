-- EV Stats v3.x: výrobce vozidla pro bezpečné párování importních pluginů.

ALTER TABLE vehicles
    ADD COLUMN IF NOT EXISTS manufacturer VARCHAR(80) NULL AFTER vin;

-- Bezpečné doplnění pouze tam, kde je výrobce z názvu zjevný.
UPDATE vehicles
SET manufacturer='SKODA'
WHERE (manufacturer IS NULL OR manufacturer='')
  AND (UPPER(name) LIKE '%SKODA%' OR UPPER(name) LIKE '%ŠKODA%');

UPDATE vehicles
SET manufacturer='KIA'
WHERE (manufacturer IS NULL OR manufacturer='')
  AND UPPER(name) LIKE '%KIA%';

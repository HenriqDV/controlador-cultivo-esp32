-- Reverte 011 (rodar manualmente).
ALTER TABLE manejo_registros DROP KEY idx_manejo_origem;
ALTER TABLE manejo_registros DROP COLUMN origem_id;
ALTER TABLE manejo_registros DROP COLUMN repetir_dias;

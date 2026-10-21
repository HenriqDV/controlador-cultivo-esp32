-- Desfaz a 006: rode no phpMyAdmin. O ESP32 volta a usar só a API_KEY do config.php.
ALTER TABLE dispositivos DROP COLUMN token_estado;
ALTER TABLE dispositivos DROP COLUMN token_hash;
ALTER TABLE dispositivos DROP COLUMN token_final;
ALTER TABLE dispositivos DROP COLUMN token_criado_em;
ALTER TABLE dispositivos DROP COLUMN token_ultimo_uso;
ALTER TABLE dispositivos DROP COLUMN vinculo_hash;
ALTER TABLE dispositivos DROP COLUMN vinculo_expira;
DELETE FROM migracoes_aplicadas WHERE versao = '006_token_hardware';

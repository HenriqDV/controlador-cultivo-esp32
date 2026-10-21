-- Desfaz a 002: rode no phpMyAdmin.
DROP TABLE IF EXISTS dispositivos;
DELETE FROM migracoes_aplicadas WHERE versao = '002_dispositivo';

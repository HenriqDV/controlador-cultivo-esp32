-- Desfaz a 009: rode no phpMyAdmin.
DROP TABLE IF EXISTS estado_atual;
DELETE FROM migracoes_aplicadas WHERE versao = '009_estado_atual';

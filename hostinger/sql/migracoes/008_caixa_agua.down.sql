-- Desfaz a 008: rode no phpMyAdmin.
ALTER TABLE leituras_ambiente DROP COLUMN agua_distancia_cm;
ALTER TABLE leituras_ambiente DROP COLUMN agua_litros;
ALTER TABLE leituras_ambiente DROP COLUMN agua_percentual;
DELETE FROM migracoes_aplicadas WHERE versao = '008_caixa_agua';

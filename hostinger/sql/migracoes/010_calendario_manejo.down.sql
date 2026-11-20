-- Desfaz a 010: rode no phpMyAdmin. Apaga todo o histórico do calendário de manejo.
DROP TABLE IF EXISTS manejo_registro_produtos;
DROP TABLE IF EXISTS manejo_registros;
DROP TABLE IF EXISTS manejo_produtos;
DELETE FROM migracoes_aplicadas WHERE versao = '010_calendario_manejo';

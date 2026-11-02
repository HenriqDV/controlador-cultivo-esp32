-- Desfaz a 007: rode no phpMyAdmin.
DROP INDEX idx_comandos_tipo_alvo ON comandos_sistema;
DELETE FROM migracoes_aplicadas WHERE versao = '007_indice_reles';

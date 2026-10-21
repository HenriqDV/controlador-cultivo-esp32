-- Desfaz a 003: rode no phpMyAdmin.
DROP TABLE IF EXISTS trocas_fase;
DELETE FROM configuracoes WHERE chave IN ('fase_atual', 'fase_iniciada_em');
DELETE FROM migracoes_aplicadas WHERE versao = '003_automacao';

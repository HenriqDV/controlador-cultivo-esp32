-- Desfaz a 004: rode no phpMyAdmin.
DROP TABLE IF EXISTS notificacoes_estado;
DELETE FROM configuracoes WHERE chave LIKE 'telegram\_%' OR chave LIKE 'notif\_%';
DELETE FROM migracoes_aplicadas WHERE versao = '004_notificacoes';

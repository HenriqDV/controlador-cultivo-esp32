-- Desfaz a 005: rode no phpMyAdmin. A conta do admin volta a valer só pelo config.php.
DROP TABLE IF EXISTS login_falhas;
DELETE FROM usuarios WHERE email IS NULL;
ALTER TABLE usuarios MODIFY email VARCHAR(120) NOT NULL;
ALTER TABLE usuarios DROP COLUMN sessao_versao;
DELETE FROM configuracoes WHERE chave = 'admin_config_hash';
DELETE FROM migracoes_aplicadas WHERE versao = '005_conta';

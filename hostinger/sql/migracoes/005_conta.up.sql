-- Etapa 6: limite de login por IP + usuário, encerramento de sessões ao trocar a
-- senha e conta do admin do config.php no banco (sem e-mail obrigatório).
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(30) NOT NULL UNIQUE,
  email VARCHAR(120) NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  papel ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
  sessao_versao INT UNSIGNED NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE usuarios ADD COLUMN sessao_versao INT UNSIGNED NOT NULL DEFAULT 0 AFTER papel;
ALTER TABLE usuarios MODIFY email VARCHAR(120) NULL;
CREATE TABLE IF NOT EXISTS login_falhas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  chave VARCHAR(140) NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_falhas_ip (ip, criado_em),
  INDEX idx_falhas_chave (ip, chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

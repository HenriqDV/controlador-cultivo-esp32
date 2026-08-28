CREATE TABLE IF NOT EXISTS leituras_ambiente (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispositivo_timestamp DATETIME NULL,
  temp_interna DECIMAL(5,2) NULL,
  umid_interna DECIMAL(5,2) NULL,
  temp_externa DECIMAL(5,2) NULL,
  umid_externa DECIMAL(5,2) NULL,
  solo_percentual TINYINT UNSIGNED NULL,
  INDEX idx_leituras_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comandos_sistema (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispositivo_timestamp DATETIME NULL,
  tipo VARCHAR(40) NOT NULL,
  alvo VARCHAR(80) NOT NULL,
  valor VARCHAR(80) NOT NULL,
  origem VARCHAR(40) NOT NULL,
  INDEX idx_comandos_criado_em (criado_em),
  INDEX idx_comandos_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila de comandos originados nos botões do dashboard web (protegido por
-- login — ver login.php/auth.php). enviar_comando.php grava aqui; o ESP32
-- consulta (poll) por linhas 'pendente' em comandos_pendentes.php, que já
-- marca como 'entregue' na mesma consulta (ver comentário em
-- atualizarComandosPendentesHostinger() no firmware sobre esse trade-off).
CREATE TABLE IF NOT EXISTS comandos_pendentes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tipo VARCHAR(40) NOT NULL,
  alvo VARCHAR(80) NOT NULL,
  valor VARCHAR(80) NOT NULL,
  status ENUM('pendente','entregue') NOT NULL DEFAULT 'pendente',
  entregue_em TIMESTAMP NULL,
  usuario VARCHAR(60) NULL,
  INDEX idx_pendentes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle de tentativas de login por IP (bloqueio temporário após várias
-- senhas erradas seguidas — mesmo espírito do antigo bloqueio HTTP Basic
-- Auth do servidor local, agora do lado do login web).
CREATE TABLE IF NOT EXISTS login_tentativas (
  ip VARCHAR(45) PRIMARY KEY,
  tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_ate TIMESTAMP NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

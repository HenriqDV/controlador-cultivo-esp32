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

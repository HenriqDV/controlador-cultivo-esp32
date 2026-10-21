-- Etapa 4: trocas de fase programadas (executadas pelo cron.php a cada minuto).
-- O início da fase atual fica em configuracoes (fase_atual, fase_iniciada_em).
CREATE TABLE IF NOT EXISTS trocas_fase (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fase ENUM('vegetativo','floracao') NOT NULL,
  executar_em DATETIME NOT NULL,
  status ENUM('pendente','executada','cancelada') NOT NULL DEFAULT 'pendente',
  criado_por VARCHAR(30) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concluida_em DATETIME NULL,
  INDEX idx_trocas_status (status, executar_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

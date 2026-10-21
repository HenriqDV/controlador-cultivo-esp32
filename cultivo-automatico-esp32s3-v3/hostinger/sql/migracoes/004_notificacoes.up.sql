-- Etapa 5: estado de cada aviso do Telegram (limite de 1 a cada 30 min e "normalizado").
-- O chat vinculado e os interruptores ficam em configuracoes (telegram_*, notif_*).
CREATE TABLE IF NOT EXISTS notificacoes_estado (
  tipo VARCHAR(30) PRIMARY KEY,
  em_alerta TINYINT(1) NOT NULL DEFAULT 0,
  notificado TINYINT(1) NOT NULL DEFAULT 0,
  ultimo_envio DATETIME NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

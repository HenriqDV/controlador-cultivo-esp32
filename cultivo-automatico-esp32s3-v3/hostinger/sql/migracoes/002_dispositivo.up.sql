-- Etapa 2: status do dispositivo (versão do firmware, sinal Wi-Fi, tempo ligado, último contato).
CREATE TABLE IF NOT EXISTS dispositivos (
  id INT UNSIGNED PRIMARY KEY,
  nome VARCHAR(60) NOT NULL DEFAULT 'Estufa principal',
  modelo VARCHAR(40) NULL,
  mac VARCHAR(17) NULL,
  firmware_versao VARCHAR(20) NULL,
  wifi_rssi SMALLINT NULL,
  uptime_seg INT UNSIGNED NULL,
  ultimo_contato TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dispositivos (id, nome) VALUES (1, 'Estufa principal');

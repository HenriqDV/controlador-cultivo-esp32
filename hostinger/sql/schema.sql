-- Estrutura completa do banco (instalação nova). Importe no phpMyAdmin.
-- Bancos antigos são atualizados automaticamente por migracoes.php.

-- Leituras dos sensores (1 a cada 20 s; o cron apaga as mais antigas que a retenção). vpd em kPa, calculado em registrar_leitura.php.
CREATE TABLE IF NOT EXISTS leituras_ambiente (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispositivo_timestamp DATETIME NULL,
  temp_interna DECIMAL(5,2) NULL,
  umid_interna DECIMAL(5,2) NULL,
  temp_externa DECIMAL(5,2) NULL,
  umid_externa DECIMAL(5,2) NULL,
  solo_percentual TINYINT UNSIGNED NULL,
  vpd DECIMAL(4,2) NULL,
  agua_distancia_cm DECIMAL(5,1) NULL,   -- caixa d'água (HC-SR04)
  agua_litros DECIMAL(5,1) NULL,
  agua_percentual TINYINT UNSIGNED NULL,
  INDEX idx_leituras_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos executados pelo ESP32 (relés, luz, modo, trava, fotoperíodo).
CREATE TABLE IF NOT EXISTS comandos_sistema (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispositivo_timestamp DATETIME NULL,
  tipo VARCHAR(40) NOT NULL,
  alvo VARCHAR(80) NOT NULL,
  valor VARCHAR(80) NOT NULL,
  origem VARCHAR(40) NOT NULL,
  INDEX idx_comandos_criado_em (criado_em),
  INDEX idx_comandos_tipo (tipo),
  INDEX idx_comandos_tipo_alvo (tipo, alvo, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila dashboard -> ESP32 (enviar_comando.php grava, comandos_pendentes.php entrega).
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

-- Horários de luz e preferências do painel (chave/valor; padrões em migracoes.php).
CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(60) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de login erradas (limite por IP + usuário em 15 min).
CREATE TABLE IF NOT EXISTS login_falhas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  chave VARCHAR(140) NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_falhas_ip (ip, criado_em),
  INDEX idx_falhas_chave (ip, chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contas do painel (o usuário de WEB_LOGIN_USUARIO no config.php é o admin principal).
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

-- "Lembrar de mim": guarda só o hash do validador do cookie.
CREATE TABLE IF NOT EXISTS lembrar_tokens (
  seletor CHAR(24) PRIMARY KEY,
  validador_hash CHAR(64) NOT NULL,
  usuario VARCHAR(30) NOT NULL,
  expira_em DATETIME NOT NULL,
  INDEX idx_lembrar_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status do ESP32 (firmware, sinal, tempo ligado). Mudanças futuras: sql/migracoes/.
CREATE TABLE IF NOT EXISTS dispositivos (
  id INT UNSIGNED PRIMARY KEY,
  nome VARCHAR(60) NOT NULL DEFAULT 'Estufa principal',
  modelo VARCHAR(40) NULL,
  mac VARCHAR(17) NULL,
  firmware_versao VARCHAR(20) NULL,
  wifi_rssi SMALLINT NULL,
  uptime_seg INT UNSIGNED NULL,
  ultimo_contato TIMESTAMP NULL,
  token_estado ENUM('legado','ativo','revogado') NOT NULL DEFAULT 'legado',
  token_hash CHAR(64) NULL,
  token_final CHAR(4) NULL,
  token_criado_em DATETIME NULL,
  token_ultimo_uso DATETIME NULL,
  vinculo_hash CHAR(64) NULL,
  vinculo_expira DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO dispositivos (id, nome) VALUES (1, 'Estufa principal');

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

-- Etapa 5: estado de cada aviso do Telegram (limite de 1 a cada 30 min e "normalizado").
-- O chat vinculado e os interruptores ficam em configuracoes (telegram_*, notif_*).
CREATE TABLE IF NOT EXISTS notificacoes_estado (
  tipo VARCHAR(30) PRIMARY KEY,
  em_alerta TINYINT(1) NOT NULL DEFAULT 0,
  notificado TINYINT(1) NOT NULL DEFAULT 0,
  ultimo_envio DATETIME NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado atual do ESP32 (a cada 5 s, uma linha só): deixa o painel ao vivo.
CREATE TABLE IF NOT EXISTS estado_atual (
  id TINYINT UNSIGNED PRIMARY KEY,
  dados TEXT NOT NULL,
  recebido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendário de manejo: o que foi aplicado (ou está agendado) em cada dia.
-- manejo_produtos: catálogo de produtos (editável pelo painel). manejo_registros: um registro por
-- dia/aplicação, com situação 'feito' ou 'agendado' e observações. manejo_registro_produtos liga os dois.
CREATE TABLE IF NOT EXISTS manejo_produtos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(60) NOT NULL,
  ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_manejo_produto_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS manejo_registros (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data DATE NOT NULL,
  situacao ENUM('feito','agendado') NOT NULL DEFAULT 'feito',
  observacao VARCHAR(500) NOT NULL DEFAULT '',
  usuario VARCHAR(60) NULL,
  concluido_em DATETIME NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_manejo_data (data),
  KEY idx_manejo_situacao (situacao, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS manejo_registro_produtos (
  registro_id INT UNSIGNED NOT NULL,
  produto_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (registro_id, produto_id),
  KEY idx_manejo_rp_produto (produto_id),
  CONSTRAINT fk_manejo_rp_registro FOREIGN KEY (registro_id) REFERENCES manejo_registros (id) ON DELETE CASCADE,
  CONSTRAINT fk_manejo_rp_produto FOREIGN KEY (produto_id) REFERENCES manejo_produtos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO manejo_produtos (nome, ordem) VALUES
  ('Leaf SHILD', 1), ('Amazônia Boost', 2), ('Rhizo Full', 3), ('Óleo de andiroba', 4), ('Gorilla Microbes', 5),
  ('Óleo de neem', 6), ('Chá de húmus', 7), ('Gorilla Pro Mix', 8), ('Soil Root Guard', 9), ('Microarmy BTI', 10);

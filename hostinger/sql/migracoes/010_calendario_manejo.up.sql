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

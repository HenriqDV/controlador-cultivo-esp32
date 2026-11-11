-- Estado atual do ESP32 (firmware 2.8.0): leituras e relés a cada 5 s, para o painel ficar ao vivo.
-- Uma linha só (id = 1), substituída a cada envio; o histórico continua em leituras_ambiente.
CREATE TABLE IF NOT EXISTS estado_atual (
  id TINYINT UNSIGNED PRIMARY KEY,
  dados TEXT NOT NULL,
  recebido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- As opções antigas de atualização começavam em 1 min; passa para 5 s para aproveitar o ao vivo.
UPDATE configuracoes SET valor = '5' WHERE chave = 'intervalo_atualizacao_seg' AND valor IN ('60', '120', '300', '600');

-- Índice para buscar rápido o último registro de cada relé (comandos.php), mesmo com a tabela grande.
CREATE INDEX idx_comandos_tipo_alvo ON comandos_sistema (tipo, alvo, id);

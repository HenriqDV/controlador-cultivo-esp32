-- Fases do cultivo marcadas no calendário: início e fim de cada uma (fim vazio = em andamento).
CREATE TABLE IF NOT EXISTS manejo_fases (
    fase VARCHAR(12) NOT NULL PRIMARY KEY,
    inicio DATE NOT NULL,
    fim DATE NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

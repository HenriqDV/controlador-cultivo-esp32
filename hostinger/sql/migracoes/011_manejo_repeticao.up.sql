-- Calendário de manejo: aplicações que se repetem a cada N dias.
-- repetir_dias: intervalo (dias) da série; ao dar o check, a próxima aplicação é agendada sozinha.
-- origem_id: registro que gerou este agendamento (para desfazer junto se o check for desmarcado).
ALTER TABLE manejo_registros ADD COLUMN repetir_dias SMALLINT UNSIGNED NULL AFTER observacao;
ALTER TABLE manejo_registros ADD COLUMN origem_id INT UNSIGNED NULL AFTER repetir_dias;
ALTER TABLE manejo_registros ADD KEY idx_manejo_origem (origem_id);

-- Etapa 7: token do hardware (só o hash) e token de vinculação de uso único.
-- token_estado: legado = vale a API_KEY do config.php; ativo = só o token; revogado = nada.
ALTER TABLE dispositivos ADD COLUMN token_estado ENUM('legado','ativo','revogado') NOT NULL DEFAULT 'legado';
ALTER TABLE dispositivos ADD COLUMN token_hash CHAR(64) NULL;
ALTER TABLE dispositivos ADD COLUMN token_final CHAR(4) NULL;
ALTER TABLE dispositivos ADD COLUMN token_criado_em DATETIME NULL;
ALTER TABLE dispositivos ADD COLUMN token_ultimo_uso DATETIME NULL;
ALTER TABLE dispositivos ADD COLUMN vinculo_hash CHAR(64) NULL;
ALTER TABLE dispositivos ADD COLUMN vinculo_expira DATETIME NULL;

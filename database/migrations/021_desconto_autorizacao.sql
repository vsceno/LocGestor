-- =====================================================================
-- Migração 021 - desconto e acréscimo com permissão (administrador ou senha de administrador)
--
--   psql -U postgres -d locgestor -f database\migrations\021_desconto_autorizacao.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Guarda quem autorizou o desconto ou acréscimo da locação e do orçamento. O gatilho de auditoria
-- já registra a linha, então o log mostra quem lançou e quem autorizou.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE locacoes ADD COLUMN IF NOT EXISTS desconto_autorizado_por uuid;
ALTER TABLE orcamentos ADD COLUMN IF NOT EXISTS desconto_autorizado_por uuid;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'locacoes_desconto_aut_fk') THEN
        ALTER TABLE locacoes ADD CONSTRAINT locacoes_desconto_aut_fk
            FOREIGN KEY (tenant_id, desconto_autorizado_por) REFERENCES usuarios (tenant_id, id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'orcamentos_desconto_aut_fk') THEN
        ALTER TABLE orcamentos ADD CONSTRAINT orcamentos_desconto_aut_fk
            FOREIGN KEY (tenant_id, desconto_autorizado_por) REFERENCES usuarios (tenant_id, id);
    END IF;
END $$;

RESET ROLE;

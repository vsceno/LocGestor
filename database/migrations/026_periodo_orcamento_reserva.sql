-- =====================================================================
-- Migração 026 - período por item também no orçamento e na reserva
--
--   psql -U postgres -d locgestor -f database\migrations\026_periodo_orcamento_reserva.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Itens antigos ficam como "automatico" (melhor preço, como antes).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE orcamento_itens ADD COLUMN IF NOT EXISTS periodo VARCHAR(10) NOT NULL DEFAULT 'automatico';
ALTER TABLE orcamento_itens ADD COLUMN IF NOT EXISTS valor_quinzenal NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE reserva_itens ADD COLUMN IF NOT EXISTS periodo VARCHAR(10) NOT NULL DEFAULT 'automatico';

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'orcamento_itens_periodo_ck') THEN
        ALTER TABLE orcamento_itens ADD CONSTRAINT orcamento_itens_periodo_ck
            CHECK (periodo IN ('automatico', 'diaria', 'semanal', 'quinzenal', 'mensal'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'orcamento_itens_valor_quinzenal_ck') THEN
        ALTER TABLE orcamento_itens ADD CONSTRAINT orcamento_itens_valor_quinzenal_ck CHECK (valor_quinzenal >= 0);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'reserva_itens_periodo_ck') THEN
        ALTER TABLE reserva_itens ADD CONSTRAINT reserva_itens_periodo_ck
            CHECK (periodo IN ('automatico', 'diaria', 'semanal', 'quinzenal', 'mensal'));
    END IF;
END $$;

RESET ROLE;

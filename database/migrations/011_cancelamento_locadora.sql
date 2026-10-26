-- =====================================================================
-- Migração 011 - cancelamento de locadora (e números do painel da plataforma)
--
--   psql -U postgres -d locgestor -f database\migrations\011_cancelamento_locadora.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - tenants.status ganha 'cancelada' (contrato encerrado pela locadora ou pelo painel). Cancelada não
--   volta a ser ativa pelo painel: o acesso fica bloqueado e a mensalidade deixa de existir.
-- - tenants.cancelada_em guarda o dia do cancelamento, para os números mensais.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS cancelada_em DATE;

DO $$
BEGIN
    ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check;
    ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('ativa', 'suspensa', 'cancelada'));
    ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_cancelada_ck;
    ALTER TABLE tenants ADD CONSTRAINT tenants_cancelada_ck CHECK ((status = 'cancelada') = (cancelada_em IS NOT NULL));
END $$;

RESET ROLE;

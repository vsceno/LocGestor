-- =====================================================================
-- Migração 031 - números financeiros da plataforma (painel Números)
--
--   psql -U postgres -d locgestor -f database\migrations\031_numeros_financeiros.sql
--
-- teste_ate: fim do teste grátis da locadora cadastrada pelo cadastro público (vazio = não é teste, ou cadastro
-- anterior a esta migração; essas locadoras ficam fora da conta de conversão). O índice acelera a conta de
-- inadimplência por idade (cobranças pendentes, por vencimento).
-- Pode ser rodada mais de uma vez.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS teste_ate DATE;

CREATE INDEX IF NOT EXISTS assinatura_cobrancas_status_vencimento_idx ON assinatura_cobrancas (status, vencimento);

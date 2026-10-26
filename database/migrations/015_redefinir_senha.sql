-- =====================================================================
-- Migração 015 - redefinição de senha pelo painel (derruba as sessões abertas do usuário)
--
--   psql -U postgres -d locgestor -f database\migrations\015_redefinir_senha.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- senha_redefinida_em: quando o painel da plataforma redefiniu a senha deste usuário. Uma sessão aberta
-- antes dessa hora deixa de valer (o usuário precisa entrar de novo com a senha temporária).
-- NULL = nunca redefinida pelo painel (as sessões atuais continuam valendo).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS senha_redefinida_em TIMESTAMPTZ;

RESET ROLE;

-- =====================================================================
-- Migração 012 - tela de uso por locadora (painel da plataforma)
--
--   psql -U postgres -d locgestor -f database\migrations\012_uso_plataforma.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- O painel da plataforma só conta registros de cada locadora (com o contexto dela definido e o RLS
-- valendo): precisa ler as tabelas abaixo. Ele não lê nomes nem dados pessoais para essa tela.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

GRANT SELECT ON vistorias, vistoria_fotos, entregas, unidades, manutencoes, kits, contas_pagar TO locgestor_plataforma;

RESET ROLE;

-- =====================================================================
-- Migração 027 - logomarca da locadora nos documentos impressos
--
--   psql -U postgres -d locgestor -f database\migrations\027_logomarca.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- A imagem fica na pasta de arquivos da locadora (como as fotos de vistoria); aqui só o caminho.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE empresa ADD COLUMN IF NOT EXISTS logo_arquivo VARCHAR(80) NOT NULL DEFAULT '';

RESET ROLE;

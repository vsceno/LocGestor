-- =====================================================================
-- Migração 002 - dados PIX da locadora (cobrança por QR Code / copia e cola)
--
--   psql -U postgres -d locgestor -f database\migrations\002_pix.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

-- Chave PIX (CPF, CNPJ, e-mail, +55 celular ou chave aleatória), nome (até 25) e cidade (até 15)
-- do recebedor, como o banco exige no BR Code. Vazia = cobrança por PIX desligada.
ALTER TABLE empresa ADD COLUMN IF NOT EXISTS pix_chave  VARCHAR(77) NOT NULL DEFAULT '';
ALTER TABLE empresa ADD COLUMN IF NOT EXISTS pix_nome   VARCHAR(25) NOT NULL DEFAULT '';
ALTER TABLE empresa ADD COLUMN IF NOT EXISTS pix_cidade VARCHAR(15) NOT NULL DEFAULT '';

RESET ROLE;

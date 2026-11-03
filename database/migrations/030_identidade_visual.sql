-- =====================================================================
-- Migração 030 - identidade visual de cada locadora
--
--   psql -U postgres -d locgestor -f database\migrations\030_identidade_visual.sql
--
-- Cores, fontes e logotipo quadrado da locadora (Configurações > Identidade visual). Coluna vazia = usa o padrão
-- neutro (App\Support\Identidade::PADRAO). A logomarca horizontal continua em empresa.logo_arquivo (migração 027).
-- A tabela já tem SELECT e UPDATE para locgestor_app, e o RLS por locadora vale para as colunas novas.
-- Pode ser rodada mais de uma vez.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE empresa
    ADD COLUMN IF NOT EXISTS cor_principal         VARCHAR(7)   NOT NULL DEFAULT '' CHECK (cor_principal ~ '^(#[0-9A-Fa-f]{6})?$'),
    ADD COLUMN IF NOT EXISTS cor_destaque          VARCHAR(7)   NOT NULL DEFAULT '' CHECK (cor_destaque ~ '^(#[0-9A-Fa-f]{6})?$'),
    ADD COLUMN IF NOT EXISTS cor_texto_escuro      VARCHAR(7)   NOT NULL DEFAULT '' CHECK (cor_texto_escuro ~ '^(#[0-9A-Fa-f]{6})?$'),
    ADD COLUMN IF NOT EXISTS cor_texto_claro       VARCHAR(7)   NOT NULL DEFAULT '' CHECK (cor_texto_claro ~ '^(#[0-9A-Fa-f]{6})?$'),
    ADD COLUMN IF NOT EXISTS cor_fundo             VARCHAR(7)   NOT NULL DEFAULT '' CHECK (cor_fundo ~ '^(#[0-9A-Fa-f]{6})?$'),
    ADD COLUMN IF NOT EXISTS fonte_titulos         VARCHAR(10)  NOT NULL DEFAULT '' CHECK (fonte_titulos IN ('', 'inter', 'sistema')),
    ADD COLUMN IF NOT EXISTS fonte_textos          VARCHAR(10)  NOT NULL DEFAULT '' CHECK (fonte_textos IN ('', 'inter', 'sistema')),
    ADD COLUMN IF NOT EXISTS logo_quadrada_arquivo VARCHAR(255) NOT NULL DEFAULT '';

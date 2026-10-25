-- =====================================================================
-- Migração 001 - proteção do login e troca obrigatória de senha
--
-- Para bancos instalados antes desta versão (instalações novas já recebem
-- isto pelo 01_schema.sql). Roda como postgres, quantas vezes for preciso:
--
--   psql -U postgres -d locgestor -f database\migrations\001_seguranca_login.sql
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

-- Tentativas de login que falharam, para limitar tentativa e erro.
-- Guarda só hashes (do IP e da conta), nunca o IP ou o login em texto.
-- Não é dado de locadora: sem tenant_id e sem RLS.
CREATE TABLE IF NOT EXISTS login_tentativas (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    chave       CHAR(64)    NOT NULL,
    criado_em   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS login_tentativas_chave_idx ON login_tentativas (chave, criado_em);

-- Usuário com senha temporária precisa trocá-la antes de usar o sistema
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS trocar_senha BOOLEAN NOT NULL DEFAULT FALSE;

GRANT SELECT, INSERT, DELETE ON login_tentativas TO locgestor_app, locgestor_plataforma;

RESET ROLE;

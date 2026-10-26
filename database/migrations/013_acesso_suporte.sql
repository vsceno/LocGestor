-- =====================================================================
-- Migração 013 - acesso de suporte a uma locadora (somente leitura, registrado na auditoria)
--
--   psql -U postgres -d locgestor -f database\migrations\013_acesso_suporte.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - acessos_suporte: cada "entrar como a locadora" do painel. O link tem um código de uso único
--   que vale 2 minutos; quem o usa ganha uma sessão de leitura de 30 minutos.
-- - RLS: a locadora só vê os próprios acessos; o painel da plataforma vê todos (política própria).
--   A locadora só consegue usar um código que seja dela e só lê as colunas do próprio acesso.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS acessos_suporte (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid         NOT NULL REFERENCES tenants (id),
    login_suporte VARCHAR(40)  NOT NULL,
    motivo        VARCHAR(300) NOT NULL CHECK (char_length(motivo) >= 10),
    token_hash    CHAR(64)     NOT NULL,            -- sha256 do código (nunca o código em si)
    criado_em     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    expira_em     TIMESTAMPTZ  NOT NULL,            -- validade do código de uso único (2 min)
    usado_em      TIMESTAMPTZ,                      -- quando o código foi trocado pela sessão
    CONSTRAINT acessos_suporte_token_uk UNIQUE (token_hash)
);
CREATE INDEX IF NOT EXISTS acessos_suporte_tenant_idx ON acessos_suporte (tenant_id, criado_em DESC);

ALTER TABLE acessos_suporte ENABLE ROW LEVEL SECURITY;
ALTER TABLE acessos_suporte FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'acessos_suporte' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON acessos_suporte
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'acessos_suporte' AND policyname = 'plataforma_todas') THEN
        CREATE POLICY plataforma_todas ON acessos_suporte TO locgestor_plataforma USING (true) WITH CHECK (true);
    END IF;
END $$;

GRANT SELECT, INSERT ON acessos_suporte TO locgestor_plataforma;
-- A locadora troca o código: lê só o necessário (inclusive o hash, para casar o código) e marca o uso
GRANT SELECT (id, tenant_id, login_suporte, motivo, token_hash, expira_em, usado_em), UPDATE (usado_em) ON acessos_suporte TO locgestor_app;

RESET ROLE;

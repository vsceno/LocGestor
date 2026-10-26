-- =====================================================================
-- Migração 014 - recados do painel da plataforma para todas as locadoras
--
--   psql -U postgres -d locgestor -f database\migrations\014_recados.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Recados são avisos gerais (manutenção programada, novidade, aviso de cobrança): não guardam dado de
-- locadora, então a tabela não tem tenant_id (sem RLS). A locadora só lê; só o painel escreve.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS recados (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tipo       VARCHAR(12)  NOT NULL CHECK (tipo IN ('aviso', 'novidade', 'manutencao')),
    titulo     VARCHAR(120) NOT NULL CHECK (char_length(titulo) >= 3),
    texto      VARCHAR(600) NOT NULL CHECK (char_length(texto) >= 5),
    expira_em  DATE         NOT NULL,
    ativo      BOOLEAN      NOT NULL DEFAULT TRUE,
    usuario    VARCHAR(40)  NOT NULL DEFAULT '',
    criado_em  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS recados_ativos_idx ON recados (ativo, expira_em);

GRANT SELECT, INSERT, UPDATE ON recados TO locgestor_plataforma;
GRANT SELECT ON recados TO locgestor_app;

RESET ROLE;

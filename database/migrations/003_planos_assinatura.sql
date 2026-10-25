-- =====================================================================
-- Migração 003 - planos e mensalidade das locadoras (assinatura do SaaS)
--
--   psql -U postgres -d locgestor -f database\migrations\003_planos_assinatura.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Dados da PLATAFORMA (não de locadora): sem tenant_id e sem RLS. A tabela de
-- pagamentos usa "locadora_id" de propósito, para não cair na regra
-- "toda tabela com tenant_id tem RLS" (o painel precisa ver todas as locadoras).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS planos (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    nome            VARCHAR(40)   NOT NULL,
    preco_mensal    NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (preco_mensal >= 0),
    limite_usuarios INTEGER       NOT NULL DEFAULT 3 CHECK (limite_usuarios >= 1),
    ativo           BOOLEAN       NOT NULL DEFAULT TRUE,
    criado_em       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT planos_nome_uk UNIQUE (nome)
);

INSERT INTO planos (nome, preco_mensal, limite_usuarios) VALUES
    ('Básico', 99.00, 3), ('Profissional', 199.00, 5), ('Empresarial', 399.00, 15)
ON CONFLICT (nome) DO NOTHING;

-- vencimento: próxima mensalidade (NULL = sem cobrança/isenta).
-- Passados "carencia_dias" do vencimento sem pagamento, o acesso é suspenso.
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS plano_id         uuid REFERENCES planos (id);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS vencimento       DATE;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS carencia_dias    INTEGER NOT NULL DEFAULT 5;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS motivo_suspensao VARCHAR(20) NOT NULL DEFAULT '';
DO $$ BEGIN
    ALTER TABLE tenants ADD CONSTRAINT tenants_carencia_ck CHECK (carencia_dias BETWEEN 0 AND 60);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE tenants ADD CONSTRAINT tenants_motivo_ck CHECK (motivo_suspensao IN ('', 'manual', 'inadimplencia'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Locadoras já existentes: liga o plano pelo nome que estava em texto
UPDATE tenants t SET plano_id = p.id FROM planos p WHERE t.plano_id IS NULL AND p.nome = t.plano;

-- Pagamentos de mensalidade. Guarda o vencimento antes/depois para poder estornar o último.
CREATE TABLE IF NOT EXISTS assinatura_pagamentos (
    id                  uuid PRIMARY KEY DEFAULT uuidv7(),
    locadora_id         uuid          NOT NULL REFERENCES tenants (id),
    data                DATE          NOT NULL,
    meses               INTEGER       NOT NULL CHECK (meses BETWEEN 1 AND 24),
    valor               NUMERIC(12,2) NOT NULL CHECK (valor >= 0),
    forma               VARCHAR(30)   NOT NULL DEFAULT 'PIX',
    vencimento_anterior DATE          NOT NULL,
    vencimento_novo     DATE          NOT NULL,
    obs                 TEXT          NOT NULL DEFAULT '',
    usuario_id          uuid          REFERENCES plataforma_usuarios (id),
    criado_em           TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS assinatura_pagamentos_locadora_idx ON assinatura_pagamentos (locadora_id, data);

-- Painel da plataforma: gerencia planos e pagamentos
GRANT SELECT, INSERT, UPDATE, DELETE ON planos TO locgestor_plataforma;
GRANT SELECT, INSERT, DELETE ON assinatura_pagamentos TO locgestor_plataforma;
-- Sistema das locadoras: só lê o próprio vencimento (aviso e bloqueio); planos e pagamentos, não
GRANT SELECT (vencimento, carencia_dias) ON tenants TO locgestor_app;

RESET ROLE;

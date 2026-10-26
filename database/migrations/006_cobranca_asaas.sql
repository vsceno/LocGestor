-- =====================================================================
-- Migração 006 - cobrança automática das mensalidades pelo Asaas
--
--   psql -U postgres -d locgestor -f database\migrations\006_cobranca_asaas.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - tenants: cobrança automática ligada/desligada e o id do cliente no Asaas.
-- - assinatura_cobrancas: cada mensalidade gerada no Asaas (link de pagamento e situação).
--   Tabela da plataforma, mas com RLS: o painel vê tudo; o sistema da locadora só lê as
--   cobranças da própria locadora (para mostrar o botão "Pagar agora").
-- - gateway_eventos: ids dos avisos (webhook) já recebidos, para não processar duas vezes.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS cobranca_automatica BOOLEAN     NOT NULL DEFAULT FALSE;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS asaas_cliente_id    VARCHAR(40) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS assinatura_cobrancas (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    locadora_id   uuid          NOT NULL REFERENCES tenants (id),
    vencimento    DATE          NOT NULL,
    valor         NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    status        VARCHAR(10)   NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'paga', 'cancelada')),
    gateway_id    VARCHAR(40),                      -- id da cobrança no Asaas (pay_...); NULL enquanto é criada
    link          VARCHAR(300)  NOT NULL DEFAULT '', -- fatura do Asaas (PIX, boleto ou cartão)
    pagamento_id  uuid          REFERENCES assinatura_pagamentos (id),
    pago_em       DATE,
    criado_em     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    atualizado_em TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT assinatura_cobrancas_gateway_uk UNIQUE (gateway_id)
);
-- Uma cobrança em aberto (ou paga) por vencimento: rodar a rotina duas vezes não cobra duas vezes
CREATE UNIQUE INDEX IF NOT EXISTS assinatura_cobrancas_vencimento_uk
    ON assinatura_cobrancas (locadora_id, vencimento) WHERE status <> 'cancelada';

CREATE TABLE IF NOT EXISTS gateway_eventos (
    id          VARCHAR(80) PRIMARY KEY,
    tipo        VARCHAR(60) NOT NULL,
    recebido_em TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE assinatura_cobrancas ENABLE ROW LEVEL SECURITY;
ALTER TABLE assinatura_cobrancas FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS plataforma_todas ON assinatura_cobrancas;
CREATE POLICY plataforma_todas ON assinatura_cobrancas TO locgestor_plataforma USING (true) WITH CHECK (true);
DROP POLICY IF EXISTS locadora_propria ON assinatura_cobrancas;
CREATE POLICY locadora_propria ON assinatura_cobrancas FOR SELECT TO locgestor_app USING (locadora_id = app_tenant_id());

GRANT SELECT, INSERT, UPDATE, DELETE ON assinatura_cobrancas TO locgestor_plataforma;
GRANT SELECT, INSERT ON gateway_eventos TO locgestor_plataforma;
-- O sistema da locadora só lê o necessário para o botão "Pagar agora" (sem os ids do gateway)
GRANT SELECT (locadora_id, vencimento, valor, status, link) ON assinatura_cobrancas TO locgestor_app;

RESET ROLE;

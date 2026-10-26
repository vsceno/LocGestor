-- =====================================================================
-- Migração 009 - contas a pagar e contas recorrentes
--
--   psql -U postgres -d locgestor -f database\migrations\009_contas_pagar.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - contas_recorrentes: modelo de despesa fixa (aluguel, salário, internet) que vence todo mês no mesmo dia.
-- - contas_pagar: cada conta a vencer; as recorrentes são geradas a partir do modelo até ~90 dias à frente
--   (gerado_ate guarda até onde já foi gerado: excluir uma parcela não a faz voltar).
-- Pagar uma conta lança a saída no caixa (caixa_id). Tudo com tenant_id e RLS, como as demais tabelas.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS contas_recorrentes (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    descricao  VARCHAR(200)  NOT NULL,
    categoria  VARCHAR(60)   NOT NULL,
    valor      NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    dia        SMALLINT      NOT NULL CHECK (dia BETWEEN 1 AND 28),
    inicio     DATE          NOT NULL,                  -- primeiro mês com vencimento
    ativa      BOOLEAN       NOT NULL DEFAULT TRUE,
    gerado_ate DATE,                                    -- último vencimento já gerado em contas_pagar
    criado_em  TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT contas_recorrentes_tenant_id_uk UNIQUE (tenant_id, id)
);

CREATE TABLE IF NOT EXISTS contas_pagar (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    descricao     VARCHAR(200)  NOT NULL,
    categoria     VARCHAR(60)   NOT NULL,
    valor         NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    vencimento    DATE          NOT NULL,
    pago_em       DATE,                                 -- NULL = em aberto
    caixa_id      uuid,                                 -- saída lançada no caixa ao pagar
    recorrente_id uuid,
    usuario_id    uuid,
    criado_em     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT contas_pagar_tenant_id_uk  UNIQUE (tenant_id, id),
    CONSTRAINT contas_pagar_parcela_uk    UNIQUE (tenant_id, recorrente_id, vencimento),
    CONSTRAINT contas_pagar_caixa_fk      FOREIGN KEY (tenant_id, caixa_id) REFERENCES caixa (tenant_id, id),
    CONSTRAINT contas_pagar_recorrente_fk FOREIGN KEY (tenant_id, recorrente_id) REFERENCES contas_recorrentes (tenant_id, id),
    CONSTRAINT contas_pagar_usuario_fk    FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id),
    CONSTRAINT contas_pagar_pago_ck       CHECK ((pago_em IS NULL) = (caixa_id IS NULL))
);
CREATE INDEX IF NOT EXISTS contas_pagar_vencimento_idx ON contas_pagar (tenant_id, pago_em, vencimento);

DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['contas_recorrentes', 'contas_pagar'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'isolamento_tenant') THEN
            EXECUTE format('CREATE POLICY isolamento_tenant ON %I
                              USING (tenant_id = app_tenant_id())
                              WITH CHECK (tenant_id = app_tenant_id())', t);
        END IF;
    END LOOP;
END $$;

-- Auditoria (função auditar() da migração 008)
DROP TRIGGER IF EXISTS auditoria ON contas_pagar;
CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON contas_pagar
    FOR EACH ROW EXECUTE FUNCTION auditar('', '', 'descricao');
DROP TRIGGER IF EXISTS auditoria ON contas_recorrentes;
CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON contas_recorrentes
    FOR EACH ROW EXECUTE FUNCTION auditar('', 'gerado_ate', 'descricao');

GRANT SELECT, INSERT, UPDATE, DELETE ON contas_recorrentes, contas_pagar TO locgestor_app;

RESET ROLE;

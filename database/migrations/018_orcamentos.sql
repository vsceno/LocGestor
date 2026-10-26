-- =====================================================================
-- Migração 018 - orçamentos (cotação com validade, convertível em reserva ou locação)
--
--   psql -U postgres -d locgestor -f database\migrations\018_orcamentos.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Orçamento não bloqueia estoque. Ao virar locação ou reserva, fica como "convertido" e não pode mais ser
-- excluído. Preço de cada item é congelado no orçamento (o que foi negociado).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS orcamentos (
    id                uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id         uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero            INTEGER       NOT NULL,
    cliente_id        uuid          NOT NULL,
    emissao           DATE          NOT NULL DEFAULT CURRENT_DATE,
    validade          DATE          NOT NULL,
    data_saida        DATE          NOT NULL,
    data_prev_devolucao DATE        NOT NULL,
    local_uso         VARCHAR(200)  NOT NULL DEFAULT '',
    acrescimo         NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (acrescimo >= 0),
    desconto          NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (desconto >= 0),
    frete_entrega     NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_entrega >= 0),
    frete_recolhimento NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_recolhimento >= 0),
    obs               TEXT          NOT NULL DEFAULT '',
    status            VARCHAR(10)   NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'convertido')),
    locacao_id        uuid,
    reserva_id        uuid,
    usuario_id        uuid,
    criado_em         TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT orcamentos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT orcamentos_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT orcamentos_datas_ck     CHECK (data_prev_devolucao >= data_saida),
    CONSTRAINT orcamentos_cliente_fk   FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id),
    CONSTRAINT orcamentos_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT orcamentos_reserva_fk   FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id),
    CONSTRAINT orcamentos_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id),
    CONSTRAINT orcamentos_convertido_ck CHECK (status = 'aberto' OR locacao_id IS NOT NULL OR reserva_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS orcamentos_cliente_idx ON orcamentos (tenant_id, cliente_id);

CREATE TABLE IF NOT EXISTS orcamento_itens (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id     uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    orcamento_id  uuid          NOT NULL,
    produto_id    uuid          NOT NULL,
    quantidade    INTEGER       NOT NULL CHECK (quantidade > 0),
    valor_diaria  NUMERIC(12,2) NOT NULL CHECK (valor_diaria >= 0),
    valor_semanal NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_semanal >= 0),
    valor_mensal  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_mensal >= 0),
    CONSTRAINT orcamento_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT orcamento_itens_orc_fk  FOREIGN KEY (tenant_id, orcamento_id) REFERENCES orcamentos (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT orcamento_itens_prod_fk FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS orcamento_itens_orc_idx ON orcamento_itens (tenant_id, orcamento_id);

DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['orcamentos', 'orcamento_itens'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'isolamento_tenant') THEN
            EXECUTE format('CREATE POLICY isolamento_tenant ON %I
                              USING (tenant_id = app_tenant_id())
                              WITH CHECK (tenant_id = app_tenant_id())', t);
        END IF;
    END LOOP;
END $$;

DROP TRIGGER IF EXISTS auditoria ON orcamentos;
CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON orcamentos
    FOR EACH ROW EXECUTE FUNCTION auditar('', 'criado_em', 'numero');

GRANT SELECT, INSERT, UPDATE, DELETE ON orcamentos, orcamento_itens TO locgestor_app;

RESET ROLE;

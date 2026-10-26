-- =====================================================================
-- Migração 022 - perda ou extravio na devolução (cobrança pelo valor de reposição)
--
--   psql -U postgres -d locgestor -f database\migrations\022_devolucao_perdas.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Cada perda fica registrada aqui (só inclusão e leitura) e o valor vai para a avaria da devolução.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS devolucao_perdas (
    id               uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id        uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    devolucao_id     uuid          NOT NULL,
    locacao_item_id  uuid          NOT NULL,
    quantidade       INTEGER       NOT NULL CHECK (quantidade > 0),
    valor_reposicao  NUMERIC(12,2) NOT NULL CHECK (valor_reposicao >= 0),
    valor            NUMERIC(12,2) NOT NULL CHECK (valor >= 0),
    CONSTRAINT devolucao_perdas_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT devolucao_perdas_devolucao_fk FOREIGN KEY (tenant_id, devolucao_id) REFERENCES devolucoes (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT devolucao_perdas_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS devolucao_perdas_devolucao_idx ON devolucao_perdas (tenant_id, devolucao_id);

ALTER TABLE devolucao_perdas ENABLE ROW LEVEL SECURITY;
ALTER TABLE devolucao_perdas FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'devolucao_perdas' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON devolucao_perdas
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON devolucao_perdas;
CREATE TRIGGER auditoria AFTER INSERT ON devolucao_perdas
    FOR EACH ROW EXECUTE FUNCTION auditar('', 'criado_em', '');

GRANT SELECT, INSERT ON devolucao_perdas TO locgestor_app;

RESET ROLE;

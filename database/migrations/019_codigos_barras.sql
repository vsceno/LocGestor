-- =====================================================================
-- Migração 019 - códigos de barras do produto (principal e similares)
--
--   psql -U postgres -d locgestor -f database\migrations\019_codigos_barras.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Cada produto pode ter vários códigos de barras (um é o principal). O código é só número, de 5 a 20
-- dígitos, e não se repete dentro da mesma locadora (duas locadoras podem ter o mesmo código).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS produto_barras (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid        NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    produto_id uuid        NOT NULL,
    codigo     VARCHAR(20) NOT NULL CHECK (codigo ~ '^[0-9]{5,20}$'),
    principal  BOOLEAN     NOT NULL DEFAULT FALSE,
    CONSTRAINT produto_barras_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT produto_barras_codigo_uk    UNIQUE (tenant_id, codigo),
    CONSTRAINT produto_barras_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS produto_barras_produto_idx ON produto_barras (tenant_id, produto_id);
-- Um principal por produto
CREATE UNIQUE INDEX IF NOT EXISTS produto_barras_principal_uk ON produto_barras (tenant_id, produto_id) WHERE principal;

ALTER TABLE produto_barras ENABLE ROW LEVEL SECURITY;
ALTER TABLE produto_barras FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'produto_barras' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON produto_barras
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON produto_barras;
CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON produto_barras
    FOR EACH ROW EXECUTE FUNCTION auditar('', '', 'codigo');

GRANT SELECT, INSERT, UPDATE, DELETE ON produto_barras TO locgestor_app;

RESET ROLE;

-- =====================================================================
-- Migração 010 - roteiro do motorista (entregas e coletas feitas)
--
--   psql -U postgres -d locgestor -f database\migrations\010_entregas.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- O roteiro do dia é montado das locações (saída = entrega; devolução prevista = coleta);
-- esta tabela só guarda cada parada marcada como feita, por quem e quando.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS entregas (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_id uuid         NOT NULL,
    tipo       VARCHAR(7)   NOT NULL CHECK (tipo IN ('entrega', 'coleta')),
    data       DATE         NOT NULL,          -- dia do roteiro
    obs        VARCHAR(200) NOT NULL DEFAULT '',
    usuario_id uuid,
    feita_em   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT entregas_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT entregas_parada_uk    UNIQUE (tenant_id, locacao_id, tipo, data),
    CONSTRAINT entregas_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT entregas_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS entregas_data_idx ON entregas (tenant_id, data);

ALTER TABLE entregas ENABLE ROW LEVEL SECURITY;
ALTER TABLE entregas FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'entregas' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON entregas
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON entregas;
CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON entregas
    FOR EACH ROW EXECUTE FUNCTION auditar('', '', 'tipo');

GRANT SELECT, INSERT, DELETE ON entregas TO locgestor_app;

RESET ROLE;

-- =====================================================================
-- Migração 020 - recibos numerados (pagamento avulso ou de uma locação)
--
--   psql -U postgres -d locgestor -f database\migrations\020_recibos.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Recibo não se altera nem se apaga: o número segue a sequência da locadora. O documento
-- (CPF/CNPJ) fica mascarado na auditoria (LGPD).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS recibos (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero      INTEGER       NOT NULL,
    emissao     DATE          NOT NULL DEFAULT CURRENT_DATE,
    recebido_de VARCHAR(150)  NOT NULL,
    documento   VARCHAR(14)   NOT NULL DEFAULT '',
    valor       NUMERIC(12,2) NOT NULL CHECK (valor > 0),
    referente   VARCHAR(200)  NOT NULL,
    forma       VARCHAR(30)   NOT NULL DEFAULT 'Dinheiro',
    cliente_id  uuid,
    locacao_id  uuid,
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT recibos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT recibos_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT recibos_cliente_fk   FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes (tenant_id, id),
    CONSTRAINT recibos_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT recibos_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS recibos_locacao_idx ON recibos (tenant_id, locacao_id);

ALTER TABLE recibos ENABLE ROW LEVEL SECURITY;
ALTER TABLE recibos FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'recibos' AND policyname = 'isolamento_tenant') THEN
        CREATE POLICY isolamento_tenant ON recibos
            USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id());
    END IF;
END $$;

DROP TRIGGER IF EXISTS auditoria ON recibos;
CREATE TRIGGER auditoria AFTER INSERT ON recibos
    FOR EACH ROW EXECUTE FUNCTION auditar('documento', 'criado_em', 'numero');

-- Sem UPDATE nem DELETE para o sistema: o recibo emitido fica como está
GRANT SELECT, INSERT ON recibos TO locgestor_app;

RESET ROLE;

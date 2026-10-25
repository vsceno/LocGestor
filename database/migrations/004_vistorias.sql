-- =====================================================================
-- Migração 004 - vistorias (checklist de retirada/devolução com fotos e assinatura)
--
--   psql -U postgres -d locgestor -f database\migrations\004_vistorias.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- Dados de locadora: tenant_id + RLS forçado + FKs compostas, como as demais tabelas.
-- Vistoria é prova do estado do equipamento: o app só lê e insere (não altera nem apaga).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS vistorias (
    id                 uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id          uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_id         uuid         NOT NULL,
    tipo               VARCHAR(10)  NOT NULL CHECK (tipo IN ('retirada', 'devolucao')),
    obs                TEXT         NOT NULL DEFAULT '',
    assinante_nome     VARCHAR(100) NOT NULL DEFAULT '',
    assinatura_arquivo VARCHAR(120) NOT NULL DEFAULT '',   -- '' = não assinada
    assinado_em        TIMESTAMPTZ,
    usuario_id         uuid,
    criado_em          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT vistorias_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT vistorias_locacao_fk   FOREIGN KEY (tenant_id, locacao_id) REFERENCES locacoes (tenant_id, id),
    CONSTRAINT vistorias_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS vistorias_locacao_idx ON vistorias (tenant_id, locacao_id);

CREATE TABLE IF NOT EXISTS vistoria_itens (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    vistoria_id     uuid         NOT NULL,
    locacao_item_id uuid         NOT NULL,
    estado          VARCHAR(12)  NOT NULL CHECK (estado IN ('ok', 'avariado', 'sujo', 'incompleto')),
    obs             VARCHAR(200) NOT NULL DEFAULT '',
    CONSTRAINT vistoria_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT vistoria_itens_vistoria_fk  FOREIGN KEY (tenant_id, vistoria_id) REFERENCES vistorias (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT vistoria_itens_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS vistoria_itens_vistoria_idx ON vistoria_itens (tenant_id, vistoria_id);

-- arquivo: caminho relativo na pasta de arquivos (tenant/ano/nome-aleatório.jpg)
CREATE TABLE IF NOT EXISTS vistoria_fotos (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    vistoria_id uuid         NOT NULL,
    arquivo     VARCHAR(120) NOT NULL,
    tamanho     INTEGER      NOT NULL CHECK (tamanho > 0),
    largura     INTEGER      NOT NULL,
    altura      INTEGER      NOT NULL,
    criado_em   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT vistoria_fotos_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT vistoria_fotos_vistoria_fk  FOREIGN KEY (tenant_id, vistoria_id) REFERENCES vistorias (tenant_id, id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS vistoria_fotos_vistoria_idx ON vistoria_fotos (tenant_id, vistoria_id);

-- RLS forçado + política de isolamento (igual às demais tabelas de locadora)
DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['vistorias', 'vistoria_itens', 'vistoria_fotos'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        IF NOT EXISTS (SELECT 1 FROM pg_policy WHERE polrelid = t::regclass AND polname = 'isolamento_tenant') THEN
            EXECUTE format('CREATE POLICY isolamento_tenant ON %I USING (tenant_id = app_tenant_id())
                            WITH CHECK (tenant_id = app_tenant_id())', t);
        END IF;
    END LOOP;
END $$;

GRANT SELECT, INSERT ON vistorias, vistoria_itens, vistoria_fotos TO locgestor_app;

RESET ROLE;

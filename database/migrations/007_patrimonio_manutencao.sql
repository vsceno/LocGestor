-- =====================================================================
-- Migração 007 - patrimônio por unidade e manutenção
--
--   psql -U postgres -d locgestor -f database\migrations\007_patrimonio_manutencao.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - unidades: cada peça com número de patrimônio/série (opcional; o estoque continua em produtos.qtd_total).
-- - manutencoes: ordem de manutenção (de uma unidade ou de N peças). Aberta = indisponível para locar.
-- - locacao_unidades: quais unidades saíram em cada item de locação e em qual devolução voltaram.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

CREATE TABLE IF NOT EXISTS unidades (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    produto_id      uuid          NOT NULL,
    patrimonio      VARCHAR(30)   NOT NULL,
    serie           VARCHAR(60)   NOT NULL DEFAULT '',
    aquisicao_data  DATE,
    aquisicao_valor NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (aquisicao_valor >= 0),
    obs             TEXT          NOT NULL DEFAULT '',
    ativa           BOOLEAN       NOT NULL DEFAULT TRUE,   -- FALSE = baixada (vendida, perdida, sucata)
    baixa_data      DATE,
    baixa_motivo    VARCHAR(200)  NOT NULL DEFAULT '',
    criado_em       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT unidades_tenant_id_uk  UNIQUE (tenant_id, id),
    CONSTRAINT unidades_patrimonio_uk UNIQUE (tenant_id, patrimonio),
    CONSTRAINT unidades_produto_fk    FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id),
    CONSTRAINT unidades_baixa_ck      CHECK (ativa OR baixa_data IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS unidades_produto_idx ON unidades (tenant_id, produto_id);

CREATE TABLE IF NOT EXISTS manutencoes (
    id          uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id   uuid          NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    numero      INTEGER       NOT NULL,
    produto_id  uuid          NOT NULL,
    unidade_id  uuid,
    quantidade  INTEGER       NOT NULL CHECK (quantidade > 0),
    tipo        VARCHAR(12)   NOT NULL CHECK (tipo IN ('preventiva', 'corretiva')),
    descricao   VARCHAR(500)  NOT NULL,
    fornecedor  VARCHAR(100)  NOT NULL DEFAULT '',
    data_inicio DATE          NOT NULL,
    data_fim    DATE,                                   -- NULL = em manutenção (fora do estoque disponível)
    custo       NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (custo >= 0),
    solucao     VARCHAR(500)  NOT NULL DEFAULT '',
    caixa_id    uuid,                                   -- saída lançada no caixa com o custo
    usuario_id  uuid,
    criado_em   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT manutencoes_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT manutencoes_numero_uk    UNIQUE (tenant_id, numero),
    CONSTRAINT manutencoes_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id),
    CONSTRAINT manutencoes_unidade_fk   FOREIGN KEY (tenant_id, unidade_id) REFERENCES unidades (tenant_id, id),
    CONSTRAINT manutencoes_caixa_fk     FOREIGN KEY (tenant_id, caixa_id) REFERENCES caixa (tenant_id, id),
    CONSTRAINT manutencoes_usuario_fk   FOREIGN KEY (tenant_id, usuario_id) REFERENCES usuarios (tenant_id, id),
    CONSTRAINT manutencoes_unidade_ck   CHECK (unidade_id IS NULL OR quantidade = 1),
    CONSTRAINT manutencoes_datas_ck     CHECK (data_fim IS NULL OR data_fim >= data_inicio)
);
CREATE INDEX IF NOT EXISTS manutencoes_produto_idx ON manutencoes (tenant_id, produto_id, data_fim);
-- Uma unidade não fica em duas manutenções abertas ao mesmo tempo
CREATE UNIQUE INDEX IF NOT EXISTS manutencoes_unidade_aberta_uk
    ON manutencoes (tenant_id, unidade_id) WHERE data_fim IS NULL AND unidade_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS locacao_unidades (
    id              uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id       uuid NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    locacao_item_id uuid NOT NULL,
    unidade_id      uuid NOT NULL,
    devolucao_id    uuid,                               -- devolução em que a unidade voltou (NULL = na rua)
    CONSTRAINT locacao_unidades_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT locacao_unidades_item_fk      FOREIGN KEY (tenant_id, locacao_item_id) REFERENCES locacao_itens (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT locacao_unidades_unidade_fk   FOREIGN KEY (tenant_id, unidade_id) REFERENCES unidades (tenant_id, id),
    CONSTRAINT locacao_unidades_devolucao_fk FOREIGN KEY (tenant_id, devolucao_id) REFERENCES devolucoes (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS locacao_unidades_item_idx ON locacao_unidades (tenant_id, locacao_item_id);
-- Uma unidade não está na rua em duas locações ao mesmo tempo
CREATE UNIQUE INDEX IF NOT EXISTS locacao_unidades_na_rua_uk ON locacao_unidades (tenant_id, unidade_id) WHERE devolucao_id IS NULL;

DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['unidades', 'manutencoes', 'locacao_unidades'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'isolamento_tenant') THEN
            EXECUTE format('CREATE POLICY isolamento_tenant ON %I
                              USING (tenant_id = app_tenant_id())
                              WITH CHECK (tenant_id = app_tenant_id())', t);
        END IF;
    END LOOP;
END $$;

-- Peças em manutenção agora, por produto
CREATE OR REPLACE VIEW vw_produto_manutencao WITH (security_invoker = true) AS
SELECT tenant_id, produto_id, SUM(quantidade)::INTEGER AS qtd_manutencao
  FROM manutencoes
 WHERE data_fim IS NULL
 GROUP BY tenant_id, produto_id;

GRANT SELECT, INSERT, UPDATE, DELETE ON unidades, manutencoes, locacao_unidades TO locgestor_app;
GRANT SELECT ON vw_produto_manutencao TO locgestor_app;

RESET ROLE;

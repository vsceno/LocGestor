-- =====================================================================
-- Migração 005 - preço semanal/mensal e kits
--
--   psql -U postgres -d locgestor -f database\migrations\005_precos_kits.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - produtos e locacao_itens ganham valor_semanal e valor_mensal (0 = não oferece);
--   na locação os valores ficam congelados, como a diária.
-- - preco_periodo(): preço de 1 unidade por N dias (a mesma regra está em app/negocio.php e assets/js/app.js).
-- - vw_locacao_valores passa a usar preco_periodo().
-- - kits: pacote de produtos que, na locação/reserva, vira os itens que o compõem.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE produtos      ADD COLUMN IF NOT EXISTS valor_semanal NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_semanal >= 0);
ALTER TABLE produtos      ADD COLUMN IF NOT EXISTS valor_mensal  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_mensal >= 0);
ALTER TABLE locacao_itens ADD COLUMN IF NOT EXISTS valor_semanal NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_semanal >= 0);
ALTER TABLE locacao_itens ADD COLUMN IF NOT EXISTS valor_mensal  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_mensal >= 0);

-- Preço de 1 unidade por p_dias (mínimo 1): meses de 30 dias, depois semanas de 7, depois diárias.
-- As diárias que sobram nunca passam do preço da semana, e o que sobra do mês nunca passa do preço do mês.
-- Nunca custa mais que só diárias. Semanal/mensal = 0: não oferecido.
CREATE OR REPLACE FUNCTION preco_periodo(p_dias INTEGER, p_diaria NUMERIC, p_semanal NUMERIC, p_mensal NUMERIC)
RETURNS NUMERIC LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$
    SELECT LEAST(d * p_diaria, m * p_mensal + CASE WHEN p_mensal > 0 THEN LEAST(s, p_mensal) ELSE s END)
      FROM (SELECT d, m,
                   CASE WHEN p_semanal > 0 THEN (r / 7) * p_semanal + LEAST((r % 7) * p_diaria, p_semanal)
                        ELSE r * p_diaria END AS s
              FROM (SELECT d,
                           CASE WHEN p_mensal > 0 THEN d / 30 ELSE 0 END AS m,
                           CASE WHEN p_mensal > 0 THEN d % 30 ELSE d END AS r
                      FROM (SELECT GREATEST(1, p_dias) AS d) a) b) c
$$;
REVOKE EXECUTE ON FUNCTION preco_periodo(INTEGER, NUMERIC, NUMERIC, NUMERIC) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION preco_periodo(INTEGER, NUMERIC, NUMERIC, NUMERIC) TO locgestor_app;

-- Mesmas colunas de antes; muda só o cálculo dos itens
CREATE OR REPLACE VIEW vw_locacao_valores WITH (security_invoker = true) AS
SELECT l.tenant_id,
       l.id AS locacao_id,
       l.numero,
       l.status,
       (COALESCE(it.previsto_itens, 0) + l.acrescimo - l.desconto)::NUMERIC(12,2) AS valor_previsto,
       CASE WHEN l.status = 'cancelada' THEN 0
            ELSE COALESCE(dv.valor_itens, 0)
               + COALESCE(dv.multa_avaria, 0)
               + CASE WHEN l.status = 'aberta' THEN COALESCE(it.pendente_hoje, 0) ELSE 0 END
               + l.acrescimo - l.desconto
       END::NUMERIC(12,2) AS valor_atual,
       COALESCE(cx.pago, 0)::NUMERIC(12,2) AS valor_pago
  FROM locacoes l
  LEFT JOIN LATERAL (
        SELECT SUM(li.quantidade * preco_periodo(l.data_prev_devolucao - l.data_saida,
                                                 li.valor_diaria, li.valor_semanal, li.valor_mensal)) AS previsto_itens,
               SUM((li.quantidade - li.qtd_devolvida) * preco_periodo(CURRENT_DATE - l.data_saida,
                                                 li.valor_diaria, li.valor_semanal, li.valor_mensal)) AS pendente_hoje
          FROM locacao_itens li
         WHERE li.tenant_id = l.tenant_id AND li.locacao_id = l.id
       ) it ON TRUE
  LEFT JOIN LATERAL (
        SELECT SUM(d.multa + d.avaria) AS multa_avaria,
               SUM((SELECT COALESCE(SUM(di.valor), 0)
                      FROM devolucao_itens di
                     WHERE di.tenant_id = d.tenant_id AND di.devolucao_id = d.id)) AS valor_itens
          FROM devolucoes d
         WHERE d.tenant_id = l.tenant_id AND d.locacao_id = l.id
       ) dv ON TRUE
  LEFT JOIN LATERAL (
        SELECT SUM(CASE WHEN c.tipo = 'E' THEN c.valor ELSE -c.valor END) AS pago
          FROM caixa c
         WHERE c.tenant_id = l.tenant_id AND c.locacao_id = l.id
       ) cx ON TRUE;

-- Kits: atalho de cadastro. Na locação/reserva o kit vira os itens dele (estoque e preço continuam por produto).
CREATE TABLE IF NOT EXISTS kits (
    id        uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id uuid         NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    codigo    VARCHAR(30)  NOT NULL,
    nome      VARCHAR(150) NOT NULL,
    obs       TEXT         NOT NULL DEFAULT '',
    ativo     BOOLEAN      NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT kits_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT kits_codigo_uk    UNIQUE (tenant_id, codigo)
);

CREATE TABLE IF NOT EXISTS kit_itens (
    id         uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id  uuid    NOT NULL DEFAULT app_tenant_id() REFERENCES tenants (id),
    kit_id     uuid    NOT NULL,
    produto_id uuid    NOT NULL,
    quantidade INTEGER NOT NULL CHECK (quantidade > 0),
    CONSTRAINT kit_itens_tenant_id_uk UNIQUE (tenant_id, id),
    CONSTRAINT kit_itens_produto_uk   UNIQUE (tenant_id, kit_id, produto_id),
    CONSTRAINT kit_itens_kit_fk       FOREIGN KEY (tenant_id, kit_id) REFERENCES kits (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT kit_itens_produto_fk   FOREIGN KEY (tenant_id, produto_id) REFERENCES produtos (tenant_id, id)
);
CREATE INDEX IF NOT EXISTS kit_itens_produto_idx ON kit_itens (tenant_id, produto_id);

DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['kits', 'kit_itens'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'isolamento_tenant') THEN
            EXECUTE format('CREATE POLICY isolamento_tenant ON %I
                              USING (tenant_id = app_tenant_id())
                              WITH CHECK (tenant_id = app_tenant_id())', t);
        END IF;
    END LOOP;
END $$;

GRANT SELECT, INSERT, UPDATE, DELETE ON kits, kit_itens TO locgestor_app;

RESET ROLE;

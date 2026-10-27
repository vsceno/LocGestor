-- =====================================================================
-- Migração 024 - período escolhido por item (diária, semanal, quinzenal ou mensal)
--
--   psql -U postgres -d locgestor -f database\migrations\024_periodo_do_item.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- A diária é a unidade base: sem plano, cobra-se dia a dia. Um plano (semanal = 7 dias,
-- quinzenal = 15, mensal = 30) cobra cada bloco completo pelo preço dele, e os dias que
-- sobram voltam a ser diárias. Itens antigos ficam como "automatico" (melhor preço, como antes).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE produtos ADD COLUMN IF NOT EXISTS valor_quinzenal NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (valor_quinzenal >= 0);

ALTER TABLE locacao_itens ADD COLUMN IF NOT EXISTS periodo VARCHAR(10) NOT NULL DEFAULT 'automatico';
ALTER TABLE locacao_itens ADD COLUMN IF NOT EXISTS valor_quinzenal NUMERIC(12,2) NOT NULL DEFAULT 0;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'locacao_itens_periodo_ck') THEN
        ALTER TABLE locacao_itens ADD CONSTRAINT locacao_itens_periodo_ck
            CHECK (periodo IN ('automatico', 'diaria', 'semanal', 'quinzenal', 'mensal'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'locacao_itens_valor_quinzenal_ck') THEN
        ALTER TABLE locacao_itens ADD CONSTRAINT locacao_itens_valor_quinzenal_ck CHECK (valor_quinzenal >= 0);
    END IF;
END $$;

-- Cobrança por plano: blocos completos pelo preço do plano + dias que sobram em diárias
CREATE OR REPLACE FUNCTION preco_plano(p_dias INTEGER, p_periodo TEXT, p_diaria NUMERIC,
                                       p_semanal NUMERIC, p_quinzenal NUMERIC, p_mensal NUMERIC)
RETURNS NUMERIC LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$
    SELECT CASE
             WHEN p_periodo = 'semanal'   AND p_semanal > 0   THEN (d / 7)  * p_semanal   + (d % 7)  * p_diaria
             WHEN p_periodo = 'quinzenal' AND p_quinzenal > 0 THEN (d / 15) * p_quinzenal + (d % 15) * p_diaria
             WHEN p_periodo = 'mensal'    AND p_mensal > 0    THEN (d / 30) * p_mensal    + (d % 30) * p_diaria
             ELSE d * p_diaria
           END
      FROM (SELECT GREATEST(1, p_dias) AS d) a
$$;

CREATE OR REPLACE VIEW vw_locacao_valores WITH (security_invoker = true) AS
SELECT l.tenant_id,
       l.id AS locacao_id,
       l.numero,
       l.status,
       (COALESCE(it.previsto_itens, 0) + l.acrescimo + l.frete_entrega + l.frete_recolhimento - l.desconto)::NUMERIC(12,2) AS valor_previsto,
       CASE WHEN l.status = 'cancelada' THEN 0
            ELSE COALESCE(dv.valor_itens, 0)
               + COALESCE(dv.multa_avaria, 0)
               + CASE WHEN l.status = 'aberta' THEN COALESCE(it.pendente_hoje, 0) ELSE 0 END
               + l.acrescimo + l.frete_entrega + l.frete_recolhimento - l.desconto
       END::NUMERIC(12,2) AS valor_atual,
       COALESCE(cx.pago, 0)::NUMERIC(12,2) AS valor_pago
  FROM locacoes l
  LEFT JOIN LATERAL (
        SELECT SUM(li.quantidade * CASE WHEN li.periodo = 'automatico'
                                        THEN preco_periodo(li.data_prev_devolucao - l.data_saida, li.valor_diaria, li.valor_semanal, li.valor_mensal)
                                        ELSE preco_plano(li.data_prev_devolucao - l.data_saida, li.periodo, li.valor_diaria,
                                                         li.valor_semanal, li.valor_quinzenal, li.valor_mensal) END) AS previsto_itens,
               SUM((li.quantidade - li.qtd_devolvida) * CASE WHEN li.periodo = 'automatico'
                                        THEN preco_periodo(CURRENT_DATE - l.data_saida, li.valor_diaria, li.valor_semanal, li.valor_mensal)
                                        ELSE preco_plano(CURRENT_DATE - l.data_saida, li.periodo, li.valor_diaria,
                                                         li.valor_semanal, li.valor_quinzenal, li.valor_mensal) END) AS pendente_hoje
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

RESET ROLE;

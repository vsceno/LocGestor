-- =====================================================================
-- Migração 025 - dias cobrados contam o dia da saída
--
--   psql -U postgres -d locgestor -f database\migrations\025_dias_cobranca.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- 27/10 a 05/11 são 10 dias (conta o dia da saída). A view passa a cobrar assim.
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

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
                                        THEN preco_periodo(li.data_prev_devolucao - l.data_saida + 1, li.valor_diaria, li.valor_semanal, li.valor_mensal)
                                        ELSE preco_plano(li.data_prev_devolucao - l.data_saida + 1, li.periodo, li.valor_diaria,
                                                         li.valor_semanal, li.valor_quinzenal, li.valor_mensal) END) AS previsto_itens,
               SUM((li.quantidade - li.qtd_devolvida) * CASE WHEN li.periodo = 'automatico'
                                        THEN preco_periodo(CURRENT_DATE - l.data_saida + 1, li.valor_diaria, li.valor_semanal, li.valor_mensal)
                                        ELSE preco_plano(CURRENT_DATE - l.data_saida + 1, li.periodo, li.valor_diaria,
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
       ) cx ON TRUE;;

RESET ROLE;

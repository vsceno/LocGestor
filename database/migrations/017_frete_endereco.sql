-- =====================================================================
-- Migração 017 - frete de entrega e de recolhimento, endereço de entrega
--
--   psql -U postgres -d locgestor -f database\migrations\017_frete_endereco.sql
--
-- Pode ser rodada mais de uma vez. Instalações novas já recebem isto pelo 01_schema.sql.
-- - locacoes ganha frete_entrega, frete_recolhimento (cobrados uma vez, somados ao valor) e endereco_entrega.
-- - vw_locacao_valores soma os fretes ao previsto e ao valor até hoje (cancelada continua valendo zero).
-- =====================================================================

\set ON_ERROR_STOP on
SET ROLE locgestor_owner;

ALTER TABLE locacoes ADD COLUMN IF NOT EXISTS frete_entrega       NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_entrega >= 0);
ALTER TABLE locacoes ADD COLUMN IF NOT EXISTS frete_recolhimento  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (frete_recolhimento >= 0);
ALTER TABLE locacoes ADD COLUMN IF NOT EXISTS endereco_entrega    VARCHAR(200)  NOT NULL DEFAULT '';

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

RESET ROLE;

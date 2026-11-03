<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Números financeiros da assinatura da LocGestor (painel Números): valor recorrente, inadimplência por idade,
 * cobranças do mês, conversão do teste grátis e receita por plano. Só leitura, na conexão da plataforma.
 *
 * Contas:
 *   MRR          = mensalidades das locadoras não canceladas com plano pago (preço do plano); ARR = MRR × 12;
 *   churn        = mensalidades das locadoras canceladas no mês;
 *   inadimplência = cobranças do Asaas pendentes com vencimento passado, por dias de atraso (locadoras não canceladas);
 *   conversão    = locadoras cujo teste grátis já acabou e que têm pagamento confirmado ÷ as que acabaram o teste.
 * O banco não guarda o histórico de troca de plano: as contas usam o plano atual de cada locadora.
 */
class NumerosFinanceiros
{
    /** Faixas de atraso das cobranças pendentes: rótulo, de (dias) e até (dias; null = sem limite). */
    public const FAIXAS = [['1 a 30 dias', 1, 30], ['31 a 60 dias', 31, 60], ['Mais de 60 dias', 61, null]];

    private const MESES = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    public static function painel(): array
    {
        $db = DB::connection('plataforma');

        $mrr = (float) $db->selectOne("SELECT COALESCE(SUM(pl.preco_mensal), 0) AS v
                                         FROM tenants t JOIN planos pl ON pl.id = t.plano_id
                                        WHERE t.status <> 'cancelada' AND pl.preco_mensal > 0")->v;
        $churn = $db->selectOne("SELECT count(*) AS quantidade, COALESCE(SUM(pl.preco_mensal), 0) AS valor
                                   FROM tenants t LEFT JOIN planos pl ON pl.id = t.plano_id
                                  WHERE t.cancelada_em >= date_trunc('month', CURRENT_DATE)");

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'churnQuantidade' => (int) $churn->quantidade,
            'churnValor' => (float) $churn->valor,
            'mrrRotulos' => self::rotulosDosMeses(),
            'mrrSerie' => self::mrrSerie(),
            'inadimplencia' => self::inadimplencia(),
            'cobrancasMes' => self::cobrancasDoMes(),
            'teste' => self::conversaoDoTeste(),
            'receitaPlanos' => self::receitaPorPlano(),
        ];
    }

    /** MRR no fim de cada um dos últimos 12 meses: locadoras criadas até o mês e não canceladas antes do fim dele. */
    private static function mrrSerie(): array
    {
        $linhas = DB::connection('plataforma')->select("
            SELECT to_char(m.mes, 'YYYY-MM') AS mes, COALESCE(SUM(pl.preco_mensal) FILTER (WHERE pl.preco_mensal > 0), 0) AS valor
              FROM generate_series(date_trunc('month', CURRENT_DATE) - INTERVAL '11 months', date_trunc('month', CURRENT_DATE), INTERVAL '1 month') AS m(mes)
              LEFT JOIN tenants t ON t.criado_em < m.mes + INTERVAL '1 month'
                                 AND (t.cancelada_em IS NULL OR t.cancelada_em >= m.mes + INTERVAL '1 month')
              LEFT JOIN planos pl ON pl.id = t.plano_id
             GROUP BY m.mes ORDER BY m.mes");

        return array_map(fn ($l) => (float) $l->valor, $linhas);
    }

    /** Rótulos dos 12 meses da série de MRR (mesma ordem de mrrSerie()). */
    private static function rotulosDosMeses(): array
    {
        return array_map(fn ($m) => self::MESES[(int) substr($m, 5)].'/'.substr($m, 2, 2), NumerosNegocio::meses());
    }

    /** Inadimplência: total, por faixa de atraso e as locadoras com mais dias em atraso. */
    private static function inadimplencia(): array
    {
        $db = DB::connection('plataforma');
        $base = "FROM assinatura_cobrancas c JOIN tenants t ON t.id = c.locadora_id
                  WHERE c.status = 'pendente' AND c.vencimento < CURRENT_DATE AND t.status <> 'cancelada'";

        $porFaixa = $db->select("SELECT CASE WHEN CURRENT_DATE - c.vencimento <= 30 THEN 1
                                             WHEN CURRENT_DATE - c.vencimento <= 60 THEN 2 ELSE 3 END AS faixa,
                                        count(*) AS quantidade, COALESCE(SUM(c.valor), 0) AS valor {$base} GROUP BY 1");
        $faixas = [];
        foreach (self::FAIXAS as $i => [$rotulo]) {
            $linha = collect($porFaixa)->firstWhere('faixa', $i + 1);
            $faixas[] = ['rotulo' => $rotulo, 'quantidade' => (int) ($linha->quantidade ?? 0), 'valor' => (float) ($linha->valor ?? 0)];
        }

        return [
            'quantidade' => array_sum(array_column($faixas, 'quantidade')),
            'valor' => array_sum(array_column($faixas, 'valor')),
            'faixas' => $faixas,
            'maiores' => $db->select("SELECT t.id, t.nome, t.slug, c.valor, c.vencimento, (CURRENT_DATE - c.vencimento) AS dias
                                        {$base} ORDER BY dias DESC, t.nome LIMIT 15"),
        ];
    }

    /** Cobranças com vencimento no mês atual, por situação. */
    private static function cobrancasDoMes(): array
    {
        $linhas = DB::connection('plataforma')->select("
            SELECT c.status, count(*) AS quantidade, COALESCE(SUM(c.valor), 0) AS valor
              FROM assinatura_cobrancas c
             WHERE c.vencimento >= date_trunc('month', CURRENT_DATE)
               AND c.vencimento < date_trunc('month', CURRENT_DATE) + INTERVAL '1 month'
             GROUP BY c.status");

        $situacoes = ['paga' => 'Pagas', 'pendente' => 'Pendentes', 'cancelada' => 'Canceladas'];
        $saida = [];
        foreach ($situacoes as $status => $rotulo) {
            $linha = collect($linhas)->firstWhere('status', $status);
            $saida[] = ['rotulo' => $rotulo, 'quantidade' => (int) ($linha->quantidade ?? 0), 'valor' => (float) ($linha->valor ?? 0)];
        }

        return $saida;
    }

    /** Teste grátis: quantas locadoras acabaram o teste, quantas pagaram e quantas ainda estão nele. */
    private static function conversaoDoTeste(): array
    {
        $paga = 'EXISTS (SELECT 1 FROM assinatura_pagamentos p WHERE p.locadora_id = t.id AND p.estornado_em IS NULL)';
        $linha = DB::connection('plataforma')->selectOne("
            SELECT count(*) FILTER (WHERE t.teste_ate < CURRENT_DATE) AS encerrados,
                   count(*) FILTER (WHERE t.teste_ate < CURRENT_DATE AND {$paga}) AS pagaram,
                   count(*) FILTER (WHERE t.teste_ate >= CURRENT_DATE AND NOT {$paga}) AS em_teste
              FROM tenants t
             WHERE t.teste_ate IS NOT NULL");

        $encerrados = (int) $linha->encerrados;
        $pagaram = (int) $linha->pagaram;

        return [
            'encerrados' => $encerrados,
            'pagaram' => $pagaram,
            'emTeste' => (int) $linha->em_teste,
            'taxa' => $encerrados > 0 ? 100.0 * $pagaram / $encerrados : 0.0,
        ];
    }

    /** Mensalidades recebidas no mês, por plano atual da locadora. */
    private static function receitaPorPlano(): array
    {
        return DB::connection('plataforma')->select("
            SELECT COALESCE(pl.nome, 'Sem plano') AS plano, count(*) AS pagamentos, COALESCE(SUM(p.valor), 0) AS valor
              FROM assinatura_pagamentos p
              JOIN tenants t ON t.id = p.locadora_id
              LEFT JOIN planos pl ON pl.id = t.plano_id
             WHERE p.estornado_em IS NULL
               AND p.data >= date_trunc('month', CURRENT_DATE)
               AND p.data < date_trunc('month', CURRENT_DATE) + INTERVAL '1 month'
             GROUP BY 1 ORDER BY valor DESC");
    }
}

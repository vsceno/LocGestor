<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resultado da locadora no painel: o mês atual comparado com o anterior, 12 meses de faturado × recebido, a idade
 * do saldo a receber e o prazo até o primeiro pagamento. Só leitura; o RLS limita tudo à locadora logada.
 *
 * Contas:
 *   faturado  = valor previsto das locações iniciadas no mês (canceladas de fora);
 *   recebido  = entradas do caixa no mês (o mesmo número da linha "Entradas" do relatório de faturamento);
 *   idade     = dias desde a saída da locação, para o saldo que ainda não foi pago;
 *   prazo     = dias da saída até o primeiro pagamento da locação (adiantado conta como 0), nas locações dos últimos 12 meses.
 */
class PainelFinanceiro
{
    /** Faixas de idade do saldo a receber: rótulo, de (dias) e até (dias; null = sem limite). */
    public const FAIXAS = [['0 a 30 dias', 0, 30], ['31 a 60 dias', 31, 60], ['Mais de 60 dias', 61, null]];

    private const MESES = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    public static function painel(): array
    {
        $mes = self::mes(0);
        $anterior = self::mes(1);
        $serie = self::serie();

        return [
            'mes' => $mes,
            'anterior' => $anterior,
            'variacaoLocacoes' => self::variacao($mes['locacoes'], $anterior['locacoes']),
            'variacaoFaturado' => self::variacao($mes['faturado'], $anterior['faturado']),
            'variacaoRecebido' => self::variacao($mes['recebido'], $anterior['recebido']),
            'ticket' => self::ticket($mes),
            'ticketAnterior' => self::ticket($anterior),
            'variacaoTicket' => self::variacao(self::ticket($mes), self::ticket($anterior)),
            'prazoPagamento' => self::prazoAtePrimeiroPagamento(),
            'serieRotulos' => $serie['rotulos'],
            'serieFaturado' => $serie['faturado'],
            'serieRecebido' => $serie['recebido'],
            'idade' => self::idadeDoSaldo(),
        ];
    }

    /** Números de um mês, contado a partir de $atras meses antes do atual (0 = este mês). */
    private static function mes(int $atras): array
    {
        $inicio = now()->startOfMonth()->subMonths($atras)->toDateString();
        $fim = now()->startOfMonth()->subMonths($atras - 1)->toDateString();

        $linha = DB::selectOne("
            SELECT (SELECT count(*) FROM locacoes WHERE status <> 'cancelada' AND data_saida >= ? AND data_saida < ?) AS locacoes,
                   (SELECT COALESCE(SUM(v.valor_previsto), 0) FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                     WHERE l.status <> 'cancelada' AND l.data_saida >= ? AND l.data_saida < ?) AS faturado,
                   (SELECT COALESCE(SUM(valor), 0) FROM caixa WHERE tipo = 'E' AND data >= ? AND data < ?) AS recebido,
                   (SELECT COALESCE(SUM(valor), 0) FROM caixa WHERE tipo = 'S' AND data >= ? AND data < ?) AS saidas",
            [$inicio, $fim, $inicio, $fim, $inicio, $fim, $inicio, $fim]);

        return [
            'rotulo' => self::rotulo($inicio),
            'locacoes' => (int) $linha->locacoes,
            'faturado' => (float) $linha->faturado,
            'recebido' => (float) $linha->recebido,
            'saidas' => (float) $linha->saidas,
        ];
    }

    /** Faturado × recebido dos últimos 12 meses (do mais antigo ao atual). */
    private static function serie(): array
    {
        $inicio = now()->startOfMonth()->subMonths(11)->toDateString();
        $linhas = DB::select("
            SELECT to_char(m.mes, 'YYYY-MM') AS mes,
                   COALESCE((SELECT SUM(v.valor_previsto) FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                              WHERE l.status <> 'cancelada' AND date_trunc('month', l.data_saida) = m.mes), 0) AS faturado,
                   COALESCE((SELECT SUM(c.valor) FROM caixa c WHERE c.tipo = 'E' AND date_trunc('month', c.data) = m.mes), 0) AS recebido
              FROM generate_series(CAST(? AS timestamp), date_trunc('month', CURRENT_DATE), INTERVAL '1 month') AS m(mes)
             ORDER BY m.mes", [$inicio]);

        return [
            'rotulos' => array_map(fn ($l) => self::MESES[(int) substr($l->mes, 5)].'/'.substr($l->mes, 2, 2), $linhas),
            'faturado' => array_map(fn ($l) => (float) $l->faturado, $linhas),
            'recebido' => array_map(fn ($l) => (float) $l->recebido, $linhas),
        ];
    }

    /** Saldo ainda não pago, por idade (dias desde a saída da locação). A soma é o "Total a receber" do painel. */
    private static function idadeDoSaldo(): array
    {
        $linhas = DB::select("
            SELECT CASE WHEN CURRENT_DATE - l.data_saida <= 30 THEN 1
                        WHEN CURRENT_DATE - l.data_saida <= 60 THEN 2 ELSE 3 END AS faixa,
                   count(*) AS quantidade, SUM(v.valor_atual - v.valor_pago) AS valor
              FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
             WHERE l.status <> 'cancelada' AND v.valor_atual - v.valor_pago > 0
             GROUP BY 1");

        $faixas = [];
        foreach (self::FAIXAS as $i => [$rotulo]) {
            $linha = collect($linhas)->firstWhere('faixa', $i + 1);
            $faixas[] = ['rotulo' => $rotulo, 'quantidade' => (int) ($linha->quantidade ?? 0), 'valor' => (float) ($linha->valor ?? 0)];
        }

        return ['faixas' => $faixas, 'total' => array_sum(array_column($faixas, 'valor'))];
    }

    /** Média de dias da saída até o primeiro pagamento, nas locações dos últimos 12 meses; null se não há nenhuma. */
    private static function prazoAtePrimeiroPagamento(): ?float
    {
        $linha = DB::selectOne("
            SELECT AVG(GREATEST(x.primeiro - x.saida, 0)) AS dias
              FROM (SELECT l.data_saida AS saida, MIN(c.data) AS primeiro
                      FROM locacoes l JOIN caixa c ON c.locacao_id = l.id AND c.tipo = 'E'
                     WHERE l.status <> 'cancelada' AND l.data_saida >= CURRENT_DATE - INTERVAL '12 months'
                     GROUP BY l.id, l.data_saida) x");

        return $linha->dias === null ? null : (float) $linha->dias;
    }

    private static function ticket(array $mes): float
    {
        return $mes['locacoes'] > 0 ? $mes['faturado'] / $mes['locacoes'] : 0.0;
    }

    /** Variação em % de $atual sobre $anterior; null quando o mês anterior não tem base (zero). */
    private static function variacao(float|int $atual, float|int $anterior): ?float
    {
        return $anterior > 0 ? 100.0 * ($atual - $anterior) / $anterior : null;
    }

    private static function rotulo(string $inicio): string
    {
        return self::MESES[(int) substr($inicio, 5, 2)].'/'.substr($inicio, 0, 4);
    }
}

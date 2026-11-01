<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Números do negócio (painel da plataforma): receita, locadoras novas e canceladas, com os últimos 12 meses. Só leitura.
 * Mesmas contas de numeros_index() do sistema antigo:
 *   receita       = pagamentos de mensalidade registrados (manuais e Asaas);
 *   novas         = locadoras criadas no mês;
 *   cancelamentos = contratos cancelados no mês;
 *   taxa de cancelamento = cancelamentos do mês ÷ (ativas agora + cancelamentos do mês);
 *   ticket médio  = receita do mês ÷ locadoras ativas com mensalidade.
 */
class NumerosNegocio
{
    private const MESES = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /** Os últimos $quantos meses (aaaa-mm), do mais antigo ao atual. */
    public static function meses(int $quantos = 12): array
    {
        return array_map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'), range($quantos - 1, 0));
    }

    /** Tudo o que a tela de números mostra. */
    public static function painel(): array
    {
        $db = DB::connection('plataforma');
        $meses = self::meses();
        $ini = $meses[0] . '-01';

        $receita = self::serie($db->select("SELECT to_char(date_trunc('month', data), 'YYYY-MM') AS mes, SUM(valor) AS total
                                              FROM assinatura_pagamentos WHERE data >= CAST(? AS date) GROUP BY 1", [$ini]), $meses);
        $novas = self::serie($db->select("SELECT to_char(date_trunc('month', criado_em), 'YYYY-MM') AS mes, count(*) AS total
                                            FROM tenants WHERE criado_em >= CAST(? AS date) GROUP BY 1", [$ini]), $meses);
        $canceladas = self::serie($db->select("SELECT to_char(date_trunc('month', cancelada_em), 'YYYY-MM') AS mes, count(*) AS total
                                                 FROM tenants WHERE cancelada_em >= CAST(? AS date) GROUP BY 1", [$ini]), $meses);
        $agora = $db->selectOne("SELECT count(*) FILTER (WHERE t.status = 'ativa') AS ativas,
                                        count(*) FILTER (WHERE t.status = 'ativa' AND t.vencimento IS NOT NULL AND p.preco_mensal > 0) AS pagantes
                                   FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id");

        $recMes = end($receita);
        $cancMes = (int) end($canceladas);
        $base = (int) $agora->ativas + $cancMes;
        $pagantes = (int) $agora->pagantes;
        $mesAtual = (int) substr(end($meses), 5);

        return [
            'rotulos' => array_map(fn ($m) => self::MESES[(int) substr($m, 5)] . '/' . substr($m, 2, 2), $meses),
            'mesAtual' => self::MESES[$mesAtual],
            'receita' => $receita,
            'novas' => $novas,
            'canceladas' => $canceladas,
            'receitaMes' => $recMes,
            'novasMes' => (int) end($novas),
            'canceladasMes' => $cancMes,
            'taxa' => $base > 0 ? 100.0 * $cancMes / $base : 0.0,
            'ticket' => $pagantes > 0 ? $recMes / $pagantes : 0.0,
            'pagantes' => $pagantes,
        ];
    }

    /** Valor de cada mês, na ordem de $meses (0 onde não houve nada). */
    private static function serie(array $linhas, array $meses): array
    {
        $por = array_column($linhas, 'total', 'mes');
        return array_map(fn ($m) => (float) ($por[$m] ?? 0), $meses);
    }
}

<?php
/*
 * Números do negócio (painel da plataforma): receita, novas locadoras, cancelamentos e
 * indicadores do mês, com os últimos 12 meses. Só leitura.
 *   receita       = pagamentos de mensalidade registrados (manuais e Asaas);
 *   novas         = locadoras criadas no mês;
 *   cancelamentos = contratos cancelados no mês (cancelada_em);
 *   taxa de cancelamento = cancelamentos do mês ÷ (ativas agora + cancelamentos do mês);
 *   ticket médio  = receita do mês ÷ locadoras ativas com mensalidade.
 */

declare(strict_types=1);

const NUMEROS_MESES = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** Os 12 primeiros dias de cada mês, do mais antigo ao atual. */
function numeros_meses(int $quantos = 12): array
{
    $meses = [];
    for ($i = $quantos - 1; $i >= 0; $i--) {
        $meses[] = date('Y-m', strtotime("first day of -$i month"));
    }
    return $meses;
}

function numeros_serie(array $linhas, array $meses, string $campo): array
{
    $por = array_column($linhas, $campo, 'mes');
    return array_map(fn($m) => (float) ($por[$m] ?? 0), $meses);
}

function numeros_grafico(string $tipo, array $rotulos, array $series, bool $moeda): string
{
    $cfg = ['tipo' => $tipo, 'rotulos' => $rotulos, 'moeda' => $moeda, 'series' => $series];
    return '<div class="grafico"><canvas data-grafico="' . e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"></canvas></div>';
}

function numeros_index(): void
{
    $meses = numeros_meses();
    $ini = $meses[0] . '-01';
    $mesAtual = end($meses);
    $rotulos = array_map(fn($m) => NUMEROS_MESES[(int) substr($m, 5)] . '/' . substr($m, 2, 2), $meses);

    $receita = all("SELECT to_char(date_trunc('month', data), 'YYYY-MM') AS mes, SUM(valor) AS total
                      FROM assinatura_pagamentos WHERE data >= CAST(:i AS date) GROUP BY 1", ['i' => $ini]);
    $novas = all("SELECT to_char(date_trunc('month', criado_em), 'YYYY-MM') AS mes, count(*) AS total
                    FROM tenants WHERE criado_em >= CAST(:i AS date) GROUP BY 1", ['i' => $ini]);
    $canc = all("SELECT to_char(date_trunc('month', cancelada_em), 'YYYY-MM') AS mes, count(*) AS total
                   FROM tenants WHERE cancelada_em >= CAST(:i AS date) GROUP BY 1", ['i' => $ini]);
    $agora = one("SELECT count(*) FILTER (WHERE t.status = 'ativa') AS ativas,
                         count(*) FILTER (WHERE t.status = 'ativa' AND t.vencimento IS NOT NULL AND p.preco_mensal > 0) AS pagantes
                    FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id");

    $serieReceita = numeros_serie($receita, $meses, 'total');
    $serieNovas = numeros_serie($novas, $meses, 'total');
    $serieCanc = numeros_serie($canc, $meses, 'total');
    $recMes = end($serieReceita);
    $cancMes = (int) end($serieCanc);
    $novasMes = (int) end($serieNovas);
    $base = (int) $agora['ativas'] + $cancMes;
    $taxa = $base > 0 ? 100.0 * $cancMes / $base : 0.0;
    $ticket = (int) $agora['pagantes'] > 0 ? $recMes / (int) $agora['pagantes'] : 0.0;

    layout_start('Números', 'numeros');
    ?>
    <div class="kpis">
        <div class="kpi verde"><span>Receita do mês</span><strong><?= money($recMes) ?></strong><small>mensalidades recebidas em <?= e(NUMEROS_MESES[(int) substr($mesAtual, 5)]) ?></small></div>
        <div class="kpi azul"><span>Novas locadoras no mês</span><strong><?= num($novasMes) ?></strong><small>cadastradas em <?= e(NUMEROS_MESES[(int) substr($mesAtual, 5)]) ?></small></div>
        <div class="kpi vermelho"><span>Cancelamentos no mês</span><strong><?= num($cancMes) ?></strong>
            <small>taxa de cancelamento <?= e(num($taxa, 1)) ?>%</small></div>
        <div class="kpi"><span>Ticket médio</span><strong><?= money($ticket) ?></strong>
            <small><?= num((int) $agora['pagantes']) ?> locadora(s) pagante(s)</small></div>
    </div>
    <div class="grid-2">
        <div class="card">
            <h2>Receita mensal</h2>
            <p class="ajuda">Mensalidades recebidas, mês a mês (últimos 12 meses).</p>
            <?= numeros_grafico('bar', $rotulos, [['nome' => 'Receita', 'cor' => '#1e8e3e', 'dados' => $serieReceita]], true) ?>
        </div>
        <div class="card">
            <h2>Locadoras: entradas e saídas</h2>
            <p class="ajuda">Locadoras novas e contratos cancelados, por mês.</p>
            <?= numeros_grafico('bar', $rotulos, [
                ['nome' => 'Novas', 'cor' => '#1a73e8', 'dados' => $serieNovas],
                ['nome' => 'Canceladas', 'cor' => '#c5221f', 'dados' => $serieCanc],
            ], false) ?>
        </div>
    </div>
    <?php
    layout_end(['assets/vendor/chart.umd.js', 'assets/js/graficos.js']);
}

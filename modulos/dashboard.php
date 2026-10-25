<?php
/*
 * Painel: indicadores, gráficos dos últimos 12 meses e listas do dia a dia.
 * Tudo filtrado pelo RLS: cada locadora só vê os próprios números.
 */

declare(strict_types=1);

const MESES_CURTOS = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** Rótulo "out/26" para o mês aaaa-mm-01. */
function rotulo_mes(string $mes): string
{
    $ts = strtotime($mes);
    return MESES_CURTOS[(int) date('n', $ts)] . '/' . date('y', $ts);
}

/** Atributo data-grafico com a configuração em JSON (desenhada por graficos.js). */
function grafico_attr(array $cfg): string
{
    return 'data-grafico="' . e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"';
}

function dashboard_index(): void
{
    $k = one("SELECT
        (SELECT count(*) FROM locacoes WHERE status = 'aberta')                                                  AS abertas,
        (SELECT COALESCE(SUM(qtd_alugada), 0) FROM vw_produto_alugado)                                            AS na_rua,
        (SELECT count(*) FROM locacoes WHERE status = 'aberta' AND data_prev_devolucao < CURRENT_DATE)            AS atrasadas,
        (SELECT count(*) FROM locacoes WHERE status = 'aberta' AND data_prev_devolucao = CURRENT_DATE)            AS vencem_hoje,
        (SELECT COALESCE(SUM(valor), 0) FROM caixa WHERE tipo = 'E'
            AND data >= date_trunc('month', CURRENT_DATE))                                                        AS recebido_mes,
        (SELECT COALESCE(SUM(valor), 0) FROM caixa WHERE tipo = 'E'
            AND data >= date_trunc('month', CURRENT_DATE) - INTERVAL '1 month'
            AND data <  date_trunc('month', CURRENT_DATE))                                                        AS recebido_anterior,
        (SELECT COALESCE(SUM(GREATEST(valor_atual - valor_pago, 0)), 0) FROM vw_locacao_valores
            WHERE status <> 'cancelada')                                                                          AS a_receber");
    $variacao = $k['recebido_anterior'] > 0 ? ($k['recebido_mes'] / $k['recebido_anterior'] - 1) * 100 : null;

    // Últimos 12 meses, incluindo o atual
    $meses = all("SELECT to_char(m, 'YYYY-MM-DD') AS mes,
                         COALESCE((SELECT SUM(valor) FROM caixa WHERE tipo = 'E' AND date_trunc('month', data) = m), 0) AS entradas,
                         COALESCE((SELECT SUM(valor) FROM caixa WHERE tipo = 'S' AND date_trunc('month', data) = m), 0) AS saidas,
                         (SELECT count(*) FROM locacoes WHERE status <> 'cancelada' AND date_trunc('month', data_saida) = m) AS locacoes
                    FROM generate_series(date_trunc('month', CURRENT_DATE) - INTERVAL '11 months',
                                         date_trunc('month', CURRENT_DATE), INTERVAL '1 month') AS m
                   ORDER BY m");
    $rotulos = array_map(fn($m) => rotulo_mes($m['mes']), $meses);

    // Os 8 produtos que mais faturaram nos últimos 12 meses (valor apurado nas devoluções)
    $ranking = all("SELECT p.descricao, SUM(di.valor) AS faturado
                      FROM devolucao_itens di
                      JOIN devolucoes d ON d.id = di.devolucao_id
                      JOIN locacao_itens li ON li.id = di.locacao_item_id
                      JOIN produtos p ON p.id = li.produto_id
                     WHERE d.data >= date_trunc('month', CURRENT_DATE) - INTERVAL '11 months'
                     GROUP BY p.descricao ORDER BY faturado DESC LIMIT 8");

    $devolucoes = all("SELECT l.id, l.numero, l.data_prev_devolucao, l.status, c.nome AS cliente, c.celular, c.telefone,
                              (SELECT SUM(li.quantidade - li.qtd_devolvida) FROM locacao_itens li WHERE li.locacao_id = l.id) AS pendentes
                         FROM locacoes l JOIN clientes c ON c.id = l.cliente_id
                        WHERE l.status = 'aberta' AND l.data_prev_devolucao <= CURRENT_DATE + 7
                        ORDER BY l.data_prev_devolucao, l.numero LIMIT 15");
    $reservas = all("SELECT r.numero, r.data_inicio, r.data_fim, r.status, c.nome AS cliente,
                            (SELECT string_agg(ri.quantidade || ' ' || p.descricao, ', ' ORDER BY p.descricao)
                               FROM reserva_itens ri JOIN produtos p ON p.id = ri.produto_id WHERE ri.reserva_id = r.id) AS itens
                       FROM reservas r JOIN clientes c ON c.id = r.cliente_id
                      WHERE r.status = 'ativa' AND r.data_inicio <= CURRENT_DATE + 7
                      ORDER BY r.data_inicio, r.numero LIMIT 15");

    layout_start('Painel', 'dashboard');
    ?>
    <div class="kpis">
        <a class="kpi" href="<?= e(url('locacoes')) ?>"><span>Locações em aberto</span><strong><?= num($k['abertas']) ?></strong>
            <small><?= num($k['na_rua']) ?> item(ns) na rua</small></a>
        <a class="kpi <?= $k['atrasadas'] > 0 ? 'vermelho' : 'verde' ?>" href="<?= e(url('locacoes', ['aba' => 'atrasadas'])) ?>">
            <span>Atrasadas</span><strong><?= num($k['atrasadas']) ?></strong><small><?= num($k['vencem_hoje']) ?> vence(m) hoje</small></a>
        <a class="kpi verde" href="<?= e(url('caixa')) ?>"><span>Recebido no mês</span><strong><?= money($k['recebido_mes']) ?></strong>
            <small>mês anterior <?= money($k['recebido_anterior']) ?><?php if ($variacao !== null): ?>
                (<?= $variacao >= 0 ? '+' : '' ?><?= num($variacao, 1) ?>%)<?php endif; ?></small></a>
        <a class="kpi azul" href="<?= e(url('relatorios', ['a' => 'ver', 'r' => 'debito'])) ?>"><span>Total a receber</span>
            <strong><?= money($k['a_receber']) ?></strong><small>saldo das locações</small></a>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>Entradas × saídas (12 meses)</h2>
            <div class="grafico"><canvas <?= grafico_attr(['tipo' => 'bar', 'moeda' => true, 'rotulos' => $rotulos, 'series' => [
                ['nome' => 'Entradas', 'cor' => '#1e8e3e', 'dados' => array_map(fn($m) => (float) $m['entradas'], $meses)],
                ['nome' => 'Saídas', 'cor' => '#c5221f', 'dados' => array_map(fn($m) => (float) $m['saidas'], $meses)],
            ]]) ?> role="img" aria-label="Gráfico de entradas e saídas por mês"></canvas></div>
        </div>
        <div class="card">
            <h2>Locações por mês</h2>
            <div class="grafico"><canvas <?= grafico_attr(['tipo' => 'line', 'rotulos' => $rotulos, 'series' => [
                ['nome' => 'Locações', 'cor' => '#e8710a', 'dados' => array_map(fn($m) => (int) $m['locacoes'], $meses)],
            ]]) ?> role="img" aria-label="Gráfico de locações por mês"></canvas></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>Produtos que mais faturaram (12 meses)</h2>
            <?php if ($ranking): ?>
            <div class="grafico"><canvas <?= grafico_attr(['tipo' => 'bar', 'horizontal' => true, 'moeda' => true,
                'rotulos' => array_column($ranking, 'descricao'), 'series' => [
                ['nome' => 'Faturado', 'cor' => '#e8710a', 'dados' => array_map(fn($r) => (float) $r['faturado'], $ranking)],
            ]]) ?> role="img" aria-label="Ranking dos produtos que mais faturaram"></canvas></div>
            <?php else: ?><p class="muted">Ainda não há devoluções nos últimos 12 meses.</p><?php endif; ?>
        </div>
        <div class="card">
            <div class="card-titulo"><h2>Devoluções atrasadas e próximas</h2><a href="<?= e(url('locacoes')) ?>">ver todas</a></div>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Nº</th><th>Cliente</th><th>Prevista</th><th>Situação</th><th class="num">Itens</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($devolucoes as $l): ?>
                        <tr><td><a href="<?= e(url('locacoes', ['a' => 'ver', 'n' => $l['numero']])) ?>"><?= e($l['numero']) ?></a></td>
                            <td><?= e($l['cliente']) ?><br><span class="muted nowrap"><?= e($l['celular'] ?: $l['telefone']) ?></span></td>
                            <td><?= e(dt($l['data_prev_devolucao'])) ?></td><td><?= selo_status_locacao($l) ?></td>
                            <td class="num"><?= num($l['pendentes']) ?></td>
                            <td><?= botao_whatsapp(whatsapp_locacao(locacao_buscar($l['id']))['links']['lembrete'] ?? null, 'Lembrete', true) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$devolucoes): ?><tr><td colspan="6" class="vazio">Nenhuma devolução para os próximos 7 dias.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-titulo"><h2>Reservas dos próximos 7 dias</h2><a href="<?= e(url('reservas')) ?>">ver todas</a></div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Cliente</th><th>Retirada</th><th>Devolução</th><th>Itens</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($reservas as $r): ?>
                    <tr><td><a href="<?= e(url('reservas', ['a' => 'ver', 'n' => $r['numero']])) ?>"><?= e($r['numero']) ?></a></td>
                        <td><?= e($r['cliente']) ?></td><td><?= e(dt($r['data_inicio'])) ?></td><td><?= e(dt($r['data_fim'])) ?></td>
                        <td><?= e($r['itens']) ?></td>
                        <td><?= $r['data_fim'] < today() ? '<span class="selo vermelho">vencida</span>' : ($r['data_inicio'] <= today() ? '<span class="selo laranja">retirar hoje</span>' : '') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$reservas): ?><tr><td colspan="6" class="vazio">Nenhuma reserva para os próximos 7 dias.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end(['assets/vendor/chart.umd.js', 'assets/js/graficos.js']);
}

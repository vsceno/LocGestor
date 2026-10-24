<?php
/*
 * Relatórios. Cada relatório é um array com título, descrição, uso de período, SQL e
 * colunas tipadas (txt|num|money|pct|date|link). Todos podem ser impressos e exportados
 * em CSV para o Excel (BOM UTF-8 e separador ";").
 *
 * No SQL, :de e :ate podem aparecer várias vezes: o executor numera cada ocorrência,
 * porque o PDO com prepares nativos não aceita o mesmo parâmetro repetido.
 */

declare(strict_types=1);

function relatorios_definicoes(): array
{
    return [
        'atraso' => [
            'titulo'    => 'Locações em atraso',
            'descricao' => 'Locações abertas com a devolução prevista vencida, das mais atrasadas para as menos.',
            'periodo'   => false,
            'sql'       => "SELECT l.numero, c.nome AS cliente, COALESCE(NULLIF(c.celular, ''), c.telefone) AS telefone,
                                   l.data_saida, l.data_prev_devolucao, CURRENT_DATE - l.data_prev_devolucao AS dias_atraso,
                                   v.valor_atual, v.valor_pago, v.valor_atual - v.valor_pago AS saldo
                              FROM locacoes l JOIN clientes c ON c.id = l.cliente_id JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.status = 'aberta' AND l.data_prev_devolucao < CURRENT_DATE
                             ORDER BY dias_atraso DESC, l.numero",
            'colunas'   => ['numero' => ['Nº', 'link'], 'cliente' => ['Cliente', 'txt'], 'telefone' => ['Telefone', 'txt'],
                            'data_saida' => ['Saída', 'date'], 'data_prev_devolucao' => ['Prevista', 'date'],
                            'dias_atraso' => ['Dias de atraso', 'num'], 'valor_atual' => ['Valor até hoje', 'money'],
                            'valor_pago' => ['Pago', 'money'], 'saldo' => ['Saldo', 'money']],
            'soma'      => ['valor_atual', 'valor_pago', 'saldo'],
        ],
        'debito' => [
            'titulo'    => 'Clientes com débito',
            'descricao' => 'Clientes com saldo a pagar (valor apurado menos o pago), somando todas as locações não canceladas.',
            'periodo'   => false,
            'sql'       => "SELECT c.nome AS cliente, COALESCE(NULLIF(c.celular, ''), c.telefone) AS telefone, count(*) AS locacoes,
                                   SUM(v.valor_atual) AS total, SUM(v.valor_pago) AS pago, SUM(v.valor_atual - v.valor_pago) AS saldo
                              FROM locacoes l JOIN clientes c ON c.id = l.cliente_id JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.status <> 'cancelada' AND v.valor_atual - v.valor_pago > 0.004
                             GROUP BY c.id, c.nome, c.celular, c.telefone
                             ORDER BY saldo DESC",
            'colunas'   => ['cliente' => ['Cliente', 'txt'], 'telefone' => ['Telefone', 'txt'], 'locacoes' => ['Locações com saldo', 'num'],
                            'total' => ['Valor', 'money'], 'pago' => ['Pago', 'money'], 'saldo' => ['Saldo devedor', 'money']],
            'soma'      => ['locacoes', 'total', 'pago', 'saldo'],
            'grafico'   => ['rotulo' => 'cliente', 'series' => ['saldo' => ['Saldo devedor', '#c5221f']], 'moeda' => true, 'horizontal' => true],
        ],
        'estoque' => [
            'titulo'    => 'Disponibilidade de estoque',
            'descricao' => 'Estoque, itens na rua, reservados para hoje e disponíveis, com o patrimônio a preço de reposição.',
            'periodo'   => false,
            'sql'       => "SELECT p.codigo, p.descricao, COALESCE(cat.nome, '') AS categoria, p.qtd_total,
                                   COALESCE(a.qtd_alugada, 0) AS alugado,
                                   COALESCE((SELECT SUM(ri.quantidade) FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                                              WHERE r.status = 'ativa' AND ri.produto_id = p.id
                                                AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS reservado,
                                   p.qtd_total - COALESCE(a.qtd_alugada, 0)
                                     - COALESCE((SELECT SUM(ri.quantidade) FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                                                  WHERE r.status = 'ativa' AND ri.produto_id = p.id
                                                    AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS disponivel,
                                   p.valor_reposicao, p.qtd_total * p.valor_reposicao AS patrimonio
                              FROM produtos p
                              LEFT JOIN categorias cat ON cat.id = p.categoria_id
                              LEFT JOIN vw_produto_alugado a ON a.produto_id = p.id
                             WHERE p.ativo
                             ORDER BY p.descricao",
            'colunas'   => ['codigo' => ['Código', 'txt'], 'descricao' => ['Produto', 'txt'], 'categoria' => ['Categoria', 'txt'],
                            'qtd_total' => ['Estoque', 'num'], 'alugado' => ['Alugado', 'num'], 'reservado' => ['Reservado hoje', 'num'],
                            'disponivel' => ['Disponível', 'num'], 'valor_reposicao' => ['Reposição (un.)', 'money'],
                            'patrimonio' => ['Patrimônio', 'money']],
            'soma'      => ['qtd_total', 'alugado', 'reservado', 'disponivel', 'patrimonio'],
        ],
        'mais_alugados' => [
            'titulo'    => 'Produtos mais alugados',
            'descricao' => 'Quantidade locada, faturamento e ocupação no período. Ocupação = unidades × dias alugados '
                         . '÷ (estoque × dias do período).',
            'periodo'   => true,
            'sql'       => "WITH uso AS (
                                SELECT li.produto_id,
                                       di.quantidade * GREATEST(0, LEAST(d.data, CAST(:ate AS date) + 1) - GREATEST(l.data_saida, CAST(:de AS date))) AS ud
                                  FROM devolucao_itens di
                                  JOIN devolucoes d ON d.id = di.devolucao_id
                                  JOIN locacao_itens li ON li.id = di.locacao_item_id
                                  JOIN locacoes l ON l.id = li.locacao_id
                                UNION ALL
                                SELECT li.produto_id,
                                       (li.quantidade - li.qtd_devolvida)
                                       * GREATEST(0, LEAST(CURRENT_DATE, CAST(:ate AS date) + 1) - GREATEST(l.data_saida, CAST(:de AS date)))
                                  FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                                 WHERE l.status = 'aberta'
                            )
                            SELECT p.codigo, p.descricao, p.qtd_total,
                                   (SELECT count(DISTINCT l.id) FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                                     WHERE li.produto_id = p.id AND l.status <> 'cancelada'
                                       AND l.data_saida BETWEEN CAST(:de AS date) AND CAST(:ate AS date)) AS locacoes,
                                   (SELECT COALESCE(SUM(li.quantidade), 0) FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                                     WHERE li.produto_id = p.id AND l.status <> 'cancelada'
                                       AND l.data_saida BETWEEN CAST(:de AS date) AND CAST(:ate AS date)) AS qtd_locada,
                                   (SELECT COALESCE(SUM(di.valor), 0) FROM devolucao_itens di
                                      JOIN devolucoes d ON d.id = di.devolucao_id JOIN locacao_itens li ON li.id = di.locacao_item_id
                                     WHERE li.produto_id = p.id AND d.data BETWEEN CAST(:de AS date) AND CAST(:ate AS date)) AS faturamento,
                                   CASE WHEN p.qtd_total > 0 THEN
                                        100.0 * COALESCE((SELECT SUM(ud) FROM uso WHERE uso.produto_id = p.id), 0)
                                        / (p.qtd_total * (CAST(:ate AS date) - CAST(:de AS date) + 1)) ELSE 0 END AS ocupacao
                              FROM produtos p
                             ORDER BY qtd_locada DESC, faturamento DESC",
            'colunas'   => ['codigo' => ['Código', 'txt'], 'descricao' => ['Produto', 'txt'], 'qtd_total' => ['Estoque', 'num'],
                            'locacoes' => ['Locações', 'num'], 'qtd_locada' => ['Qtd. locada', 'num'],
                            'faturamento' => ['Faturamento', 'money'], 'ocupacao' => ['Ocupação', 'pct']],
            'soma'      => ['locacoes', 'qtd_locada', 'faturamento'],
            'grafico'   => ['rotulo' => 'descricao', 'series' => ['faturamento' => ['Faturamento', '#e8710a']], 'moeda' => true, 'horizontal' => true],
        ],
        'sem_movimento' => [
            'titulo'    => 'Produtos sem movimento',
            'descricao' => 'Produtos ativos que não saíram em nenhuma locação no período, com o patrimônio parado.',
            'periodo'   => true,
            'sql'       => "SELECT p.codigo, p.descricao, p.qtd_total,
                                   (SELECT max(l.data_saida) FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                                     WHERE li.produto_id = p.id AND l.status <> 'cancelada') AS ultima_saida,
                                   p.qtd_total * p.valor_reposicao AS patrimonio
                              FROM produtos p
                             WHERE p.ativo AND NOT EXISTS (
                                   SELECT 1 FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                                    WHERE li.produto_id = p.id AND l.status <> 'cancelada'
                                      AND l.data_saida BETWEEN CAST(:de AS date) AND CAST(:ate AS date))
                             ORDER BY patrimonio DESC",
            'colunas'   => ['codigo' => ['Código', 'txt'], 'descricao' => ['Produto', 'txt'], 'qtd_total' => ['Estoque', 'num'],
                            'ultima_saida' => ['Última saída', 'date'], 'patrimonio' => ['Patrimônio parado', 'money']],
            'soma'      => ['qtd_total', 'patrimonio'],
        ],
        'faturamento' => [
            'titulo'    => 'Faturamento por mês',
            'descricao' => 'Entradas, saídas e resultado do caixa em cada mês, com o número de locações iniciadas.',
            'periodo'   => true,
            'sql'       => "SELECT to_char(m, 'MM/YYYY') AS mes,
                                   COALESCE((SELECT SUM(valor) FROM caixa WHERE tipo = 'E' AND date_trunc('month', data) = m
                                              AND data BETWEEN CAST(:de AS date) AND CAST(:ate AS date)), 0) AS entradas,
                                   COALESCE((SELECT SUM(valor) FROM caixa WHERE tipo = 'S' AND date_trunc('month', data) = m
                                              AND data BETWEEN CAST(:de AS date) AND CAST(:ate AS date)), 0) AS saidas,
                                   COALESCE((SELECT SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END) FROM caixa
                                              WHERE date_trunc('month', data) = m AND data BETWEEN CAST(:de AS date) AND CAST(:ate AS date)), 0) AS resultado,
                                   (SELECT count(*) FROM locacoes WHERE status <> 'cancelada' AND date_trunc('month', data_saida) = m
                                       AND data_saida BETWEEN CAST(:de AS date) AND CAST(:ate AS date)) AS locacoes
                              FROM generate_series(date_trunc('month', CAST(:de AS date)), date_trunc('month', CAST(:ate AS date)),
                                                   INTERVAL '1 month') AS m
                             ORDER BY m",
            'colunas'   => ['mes' => ['Mês', 'txt'], 'entradas' => ['Entradas', 'money'], 'saidas' => ['Saídas', 'money'],
                            'resultado' => ['Resultado', 'money'], 'locacoes' => ['Locações', 'num']],
            'soma'      => ['entradas', 'saidas', 'resultado', 'locacoes'],
            'grafico'   => ['rotulo' => 'mes', 'series' => ['entradas' => ['Entradas', '#1e8e3e'], 'saidas' => ['Saídas', '#c5221f']], 'moeda' => true],
        ],
        'clientes_top' => [
            'titulo'    => 'Clientes que mais alugam',
            'descricao' => 'Clientes ordenados pelo valor das locações iniciadas no período.',
            'periodo'   => true,
            'sql'       => "SELECT c.nome AS cliente, count(*) AS locacoes, SUM(v.valor_atual) AS valor,
                                   SUM(v.valor_atual) / count(*) AS ticket, max(l.data_saida) AS ultima
                              FROM locacoes l JOIN clientes c ON c.id = l.cliente_id JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.status <> 'cancelada' AND l.data_saida BETWEEN CAST(:de AS date) AND CAST(:ate AS date)
                             GROUP BY c.id, c.nome
                             ORDER BY valor DESC
                             LIMIT 50",
            'colunas'   => ['cliente' => ['Cliente', 'txt'], 'locacoes' => ['Locações', 'num'], 'valor' => ['Valor', 'money'],
                            'ticket' => ['Ticket médio', 'money'], 'ultima' => ['Última locação', 'date']],
            'soma'      => ['locacoes', 'valor'],
            'grafico'   => ['rotulo' => 'cliente', 'series' => ['valor' => ['Valor', '#e8710a']], 'moeda' => true, 'horizontal' => true],
        ],
        'periodo' => [
            'titulo'    => 'Locações no período',
            'descricao' => 'Todas as locações com saída no período, com valores e saldo.',
            'periodo'   => true,
            'sql'       => "SELECT l.numero, c.nome AS cliente, l.data_saida, l.data_prev_devolucao, l.status,
                                   v.valor_atual, v.valor_pago,
                                   CASE WHEN l.status = 'cancelada' THEN 0 ELSE v.valor_atual - v.valor_pago END AS saldo
                              FROM locacoes l JOIN clientes c ON c.id = l.cliente_id JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.data_saida BETWEEN CAST(:de AS date) AND CAST(:ate AS date)
                             ORDER BY l.data_saida, l.numero",
            'colunas'   => ['numero' => ['Nº', 'link'], 'cliente' => ['Cliente', 'txt'], 'data_saida' => ['Saída', 'date'],
                            'data_prev_devolucao' => ['Prevista', 'date'], 'status' => ['Situação', 'txt'],
                            'valor_atual' => ['Valor', 'money'], 'valor_pago' => ['Pago', 'money'], 'saldo' => ['Saldo', 'money']],
            'soma'      => ['valor_atual', 'valor_pago', 'saldo'],
        ],
    ];
}

/** Executa o SQL do relatório, numerando cada :de/:ate (o PDO nativo não aceita parâmetro repetido). */
function relatorio_executar(array $rel, string $de, string $ate): array
{
    $params = [];
    $n = 0;
    $sql = preg_replace_callback('/:(de|ate)\b/', function ($m) use (&$params, &$n, $de, $ate) {
        $nome = $m[1] . (++$n);
        $params[$nome] = $m[1] === 'de' ? $de : $ate;
        return ':' . $nome;
    }, $rel['sql']);
    return all($sql, $rel['periodo'] ? $params : []);
}

/** Valor formatado para a tela/impressão. */
function relatorio_celula(string $tipo, $v): string
{
    return match ($tipo) {
        'money' => money($v),
        'num'   => num($v),
        'pct'   => num($v, 1) . '%',
        'date'  => e(dt($v)),
        'link'  => '<a href="' . e(url('locacoes', ['a' => 'ver', 'n' => $v])) . '">' . e($v) . '</a>',
        default => e($v),
    };
}

/** Valor para o CSV: números sem separador de milhar e com vírgula decimal (Excel em português). */
function relatorio_csv_valor(string $tipo, $v): string
{
    $s = match ($tipo) {
        'money' => number_format((float) $v, 2, ',', ''),
        'pct'   => number_format((float) $v, 1, ',', ''),
        'num'   => (string) (int) $v,
        'date'  => dt($v),
        default => (string) ($v ?? ''),
    };
    // Evita que o Excel interprete texto como fórmula (CSV injection)
    return preg_match('/^[=+\-@\t\r]/', $s) && !in_array($tipo, ['money', 'pct', 'num'], true) ? "'" . $s : $s;
}

function relatorios_index(): void
{
    layout_start('Relatórios', 'relatorios');
    echo '<div class="relatorios-lista">';
    foreach (relatorios_definicoes() as $chave => $rel) {
        echo '<a href="' . e(url('relatorios', ['a' => 'ver', 'r' => $chave])) . '"><div class="card"><h2>' . e($rel['titulo'])
           . '</h2><p class="muted">' . e($rel['descricao']) . '</p>'
           . ($rel['periodo'] ? '<span class="selo azul">por período</span>' : '<span class="selo">situação atual</span>') . '</div></a>';
    }
    echo '</div>';
    layout_end();
}

function relatorios_ver(): void
{
    $defs = relatorios_definicoes();
    $chave = get('r');
    if (!isset($defs[$chave])) {
        pagina_erro(404, 'Relatório não encontrado.');
    }
    $rel = $defs[$chave];

    // Período padrão: os últimos 12 meses
    $de = get('de', date('Y-m-01', strtotime('-11 months')));
    $ate = get('ate', today());
    $de = valid_date($de) ? $de : date('Y-m-01', strtotime('-11 months'));
    $ate = valid_date($ate) ? $ate : today();
    if ($ate < $de) {
        [$de, $ate] = [$ate, $de];
    }
    $linhas = relatorio_executar($rel, $de, $ate);
    $totais = [];
    foreach ($rel['soma'] ?? [] as $c) {
        $totais[$c] = array_sum(array_map(fn($l) => (float) $l[$c], $linhas));
    }
    $subtitulo = $rel['periodo'] ? 'Período: ' . dt($de) . ' a ' . dt($ate) : 'Situação em ' . dt(today(), true);
    $params = ['a' => 'ver', 'r' => $chave] + ($rel['periodo'] ? ['de' => $de, 'ate' => $ate] : []);

    if (get('csv') === '1') {
        relatorio_csv($chave, $rel, $linhas, $totais);
    }
    if (get('imprimir') === '1') {
        relatorio_impressao($rel, $linhas, $totais, $subtitulo);
        return;
    }

    layout_start($rel['titulo'], 'relatorios');
    ?>
    <div class="card">
        <div class="card-titulo">
            <div><h2><?= e($rel['titulo']) ?></h2><p class="muted" style="margin:0"><?= e($rel['descricao']) ?></p></div>
            <div class="acoes">
                <a class="btn btn-sec" href="<?= e(url('relatorios', $params + ['imprimir' => '1'])) ?>" target="_blank">Imprimir</a>
                <a class="btn btn-sec" href="<?= e(url('relatorios', $params + ['csv' => '1'])) ?>">Exportar CSV</a>
                <a class="btn btn-sec" href="<?= e(url('relatorios')) ?>">Todos os relatórios</a>
            </div>
        </div>
        <?php if ($rel['periodo']): ?>
            <form method="get" class="filtros">
                <input type="hidden" name="p" value="relatorios"><input type="hidden" name="a" value="ver"><input type="hidden" name="r" value="<?= e($chave) ?>">
                <label>De <input type="date" name="de" value="<?= e($de) ?>"></label>
                <label>Até <input type="date" name="ate" value="<?= e($ate) ?>"></label>
                <button class="btn btn-sec">Atualizar</button>
            </form>
        <?php else: ?>
            <p class="muted"><?= e($subtitulo) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($rel['grafico']) && $linhas):
        $g = $rel['grafico'];
        $base = array_slice($linhas, 0, 12);
        $cfg = ['tipo' => 'bar', 'moeda' => $g['moeda'] ?? false, 'horizontal' => $g['horizontal'] ?? false,
                'rotulos' => array_map(fn($l) => mb_strimwidth((string) $l[$g['rotulo']], 0, 32, '…'), $base), 'series' => []];
        foreach ($g['series'] as $campo => [$nome, $cor]) {
            $cfg['series'][] = ['nome' => $nome, 'cor' => $cor, 'dados' => array_map(fn($l) => (float) $l[$campo], $base)];
        } ?>
        <div class="card"><div class="grafico"><canvas data-grafico="<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>"
             role="img" aria-label="Gráfico do relatório"></canvas></div></div>
    <?php endif; ?>

    <div class="card">
        <?php relatorio_tabela($rel, $linhas, $totais); ?>
    </div>
    <?php
    layout_end(!empty($rel['grafico']) ? ['assets/vendor/chart.umd.js', 'assets/js/graficos.js'] : []);
}

function relatorio_tabela(array $rel, array $linhas, array $totais): void
{
    $ehNum = fn($t) => in_array($t, ['num', 'money', 'pct'], true);
    ?>
    <div class="tabela-wrap">
        <table class="tabela">
            <thead><tr><?php foreach ($rel['colunas'] as [$rot, $tipo]): ?><th<?= $ehNum($tipo) ? ' class="num"' : '' ?>><?= e($rot) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($linhas as $l): ?>
                <tr><?php foreach ($rel['colunas'] as $campo => [$rot, $tipo]): ?>
                    <td<?= $ehNum($tipo) ? ' class="num"' : '' ?>><?= relatorio_celula($tipo, $l[$campo]) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$linhas): ?><tr><td colspan="<?= count($rel['colunas']) ?>" class="vazio">Nenhum registro.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($totais && $linhas): ?>
            <tfoot><tr><?php $primeira = true; foreach ($rel['colunas'] as $campo => [$rot, $tipo]): ?>
                <td<?= $ehNum($tipo) ? ' class="num"' : '' ?>><?= isset($totais[$campo]) ? relatorio_celula($tipo, $totais[$campo])
                    : ($primeira ? 'Total (' . num(count($linhas)) . ')' : '') ?></td>
            <?php $primeira = false; endforeach; ?></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php
}

function relatorio_impressao(array $rel, array $linhas, array $totais, string $subtitulo): void
{
    $emp = empresa();
    print_start($rel['titulo']);
    ?>
    <div class="cabecalho">
        <div><h1><?= e($emp['nome_fantasia'] ?: $emp['razao_social']) ?></h1><p class="muted"><?= e($rel['descricao']) ?></p></div>
        <div class="doc"><h1><?= e($rel['titulo']) ?></h1><p class="muted"><?= e($subtitulo) ?></p>
            <p class="muted">Emitido em <?= e(dt(date('Y-m-d H:i'), true)) ?></p></div>
    </div>
    <?php
    relatorio_tabela($rel, $linhas, $totais);
    print_end();
}

/** CSV para o Excel: BOM UTF-8 (acentos corretos), separador ";" e números com vírgula. */
function relatorio_csv(string $chave, array $rel, array $linhas, array $totais): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio-' . $chave . '-' . today() . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($c) => $c[0], $rel['colunas']), ';', '"', '');
    foreach ($linhas as $l) {
        $linha = [];
        foreach ($rel['colunas'] as $campo => [$rot, $tipo]) {
            $linha[] = relatorio_csv_valor($tipo, $l[$campo]);
        }
        fputcsv($out, $linha, ';', '"', '');
    }
    if ($totais && $linhas) {
        $linha = [];
        $primeira = true;
        foreach ($rel['colunas'] as $campo => [$rot, $tipo]) {
            $linha[] = isset($totais[$campo]) ? relatorio_csv_valor($tipo, $totais[$campo]) : ($primeira ? 'Total' : '');
            $primeira = false;
        }
        fputcsv($out, $linha, ';', '"', '');
    }
    fclose($out);
    exit;
}

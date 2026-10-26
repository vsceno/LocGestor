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
            'descricao' => 'Estoque, itens na rua, reservados para hoje, em manutenção e disponíveis, com o patrimônio a preço de reposição.',
            'periodo'   => false,
            'sql'       => "SELECT p.codigo, p.descricao, COALESCE(cat.nome, '') AS categoria, p.qtd_total,
                                   COALESCE(a.qtd_alugada, 0) AS alugado,
                                   COALESCE((SELECT SUM(ri.quantidade) FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                                              WHERE r.status = 'ativa' AND ri.produto_id = p.id
                                                AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS reservado,
                                   COALESCE(mn.qtd_manutencao, 0) AS manutencao,
                                   p.qtd_total - COALESCE(a.qtd_alugada, 0) - COALESCE(mn.qtd_manutencao, 0)
                                     - COALESCE((SELECT SUM(ri.quantidade) FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                                                  WHERE r.status = 'ativa' AND ri.produto_id = p.id
                                                    AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS disponivel,
                                   p.valor_reposicao, p.qtd_total * p.valor_reposicao AS patrimonio
                              FROM produtos p
                              LEFT JOIN categorias cat ON cat.id = p.categoria_id
                              LEFT JOIN vw_produto_alugado a ON a.produto_id = p.id
                              LEFT JOIN vw_produto_manutencao mn ON mn.produto_id = p.id
                             WHERE p.ativo
                             ORDER BY p.descricao",
            'colunas'   => ['codigo' => ['Código', 'txt'], 'descricao' => ['Produto', 'txt'], 'categoria' => ['Categoria', 'txt'],
                            'qtd_total' => ['Estoque', 'num'], 'alugado' => ['Alugado', 'num'], 'reservado' => ['Reservado hoje', 'num'],
                            'manutencao' => ['Manutenção', 'num'],
                            'disponivel' => ['Disponível', 'num'], 'valor_reposicao' => ['Reposição (un.)', 'money'],
                            'patrimonio' => ['Patrimônio', 'money']],
            'soma'      => ['qtd_total', 'alugado', 'reservado', 'manutencao', 'disponivel', 'patrimonio'],
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
        'rentabilidade' => [
            'titulo'    => 'Rentabilidade por equipamento',
            'descricao' => 'Faturamento (itens devolvidos no período) menos o custo das manutenções concluídas no período. '
                         . 'Investimento = valor de aquisição das unidades de patrimônio ou, sem elas, estoque × valor de reposição. '
                         . '"Já recuperado" compara todo o faturamento menos manutenções, desde sempre, com o investimento.',
            'periodo'   => true,
            'sql'       => "WITH fat AS (
                                SELECT li.produto_id,
                                       SUM(di.valor) FILTER (WHERE d.data BETWEEN CAST(:de AS date) AND CAST(:ate AS date)) AS periodo,
                                       SUM(di.valor) AS total
                                  FROM devolucao_itens di
                                  JOIN devolucoes d ON d.id = di.devolucao_id
                                  JOIN locacao_itens li ON li.id = di.locacao_item_id
                                 GROUP BY li.produto_id
                            ), man AS (
                                SELECT produto_id,
                                       SUM(custo) FILTER (WHERE data_fim BETWEEN CAST(:de AS date) AND CAST(:ate AS date)) AS periodo,
                                       SUM(custo) FILTER (WHERE data_fim IS NOT NULL) AS total
                                  FROM manutencoes GROUP BY produto_id
                            ), inv AS (
                                SELECT p.id AS produto_id,
                                       COALESCE((SELECT SUM(u.aquisicao_valor) FROM unidades u
                                                  WHERE u.produto_id = p.id AND u.ativa AND u.aquisicao_valor > 0),
                                                p.qtd_total * p.valor_reposicao) AS investimento
                                  FROM produtos p
                            )
                            SELECT p.codigo, p.descricao,
                                   COALESCE(fat.periodo, 0) AS faturamento,
                                   COALESCE(man.periodo, 0) AS manutencao,
                                   COALESCE(fat.periodo, 0) - COALESCE(man.periodo, 0) AS resultado,
                                   inv.investimento,
                                   CASE WHEN inv.investimento > 0
                                        THEN 100.0 * (COALESCE(fat.total, 0) - COALESCE(man.total, 0)) / inv.investimento ELSE 0 END AS recuperado
                              FROM produtos p
                              JOIN inv ON inv.produto_id = p.id
                              LEFT JOIN fat ON fat.produto_id = p.id
                              LEFT JOIN man ON man.produto_id = p.id
                             WHERE p.ativo OR fat.periodo > 0 OR man.periodo > 0
                             ORDER BY resultado DESC, p.descricao",
            'colunas'   => ['codigo' => ['Código', 'txt'], 'descricao' => ['Produto', 'txt'], 'faturamento' => ['Faturamento', 'money'],
                            'manutencao' => ['Manutenção', 'money'], 'resultado' => ['Resultado', 'money'],
                            'investimento' => ['Investimento', 'money'], 'recuperado' => ['Já recuperado', 'pct']],
            'soma'      => ['faturamento', 'manutencao', 'resultado', 'investimento'],
            'grafico'   => ['rotulo' => 'descricao', 'series' => ['resultado' => ['Resultado', '#1e8e3e']], 'moeda' => true, 'horizontal' => true],
        ],
        'rentabilidade_unidade' => [
            'titulo'    => 'Rentabilidade por unidade (patrimônio)',
            'descricao' => 'Para as peças com número de patrimônio: dias na rua e faturamento das locações em que a unidade voltou '
                         . 'no período (a parte dela no valor do item), menos as manutenções dela concluídas no período.',
            'periodo'   => true,
            'sql'       => "WITH uso AS (
                                SELECT lu.unidade_id, d.data - l.data_saida AS dias,
                                       di.valor / NULLIF(di.quantidade, 0) AS valor
                                  FROM locacao_unidades lu
                                  JOIN devolucoes d ON d.id = lu.devolucao_id
                                  JOIN devolucao_itens di ON di.devolucao_id = lu.devolucao_id AND di.locacao_item_id = lu.locacao_item_id
                                  JOIN locacao_itens li ON li.id = lu.locacao_item_id
                                  JOIN locacoes l ON l.id = li.locacao_id
                                 WHERE d.data BETWEEN CAST(:de AS date) AND CAST(:ate AS date)
                            )
                            SELECT u.patrimonio, p.descricao AS produto, u.aquisicao_valor,
                                   (SELECT count(*) FROM uso WHERE uso.unidade_id = u.id) AS locacoes,
                                   COALESCE((SELECT SUM(GREATEST(1, dias)) FROM uso WHERE uso.unidade_id = u.id), 0) AS dias,
                                   COALESCE((SELECT SUM(valor) FROM uso WHERE uso.unidade_id = u.id), 0) AS faturamento,
                                   COALESCE((SELECT SUM(m.custo) FROM manutencoes m WHERE m.unidade_id = u.id
                                              AND m.data_fim BETWEEN CAST(:de AS date) AND CAST(:ate AS date)), 0) AS manutencao,
                                   COALESCE((SELECT SUM(valor) FROM uso WHERE uso.unidade_id = u.id), 0)
                                     - COALESCE((SELECT SUM(m.custo) FROM manutencoes m WHERE m.unidade_id = u.id
                                                  AND m.data_fim BETWEEN CAST(:de AS date) AND CAST(:ate AS date)), 0) AS resultado
                              FROM unidades u JOIN produtos p ON p.id = u.produto_id
                             WHERE u.ativa
                             ORDER BY resultado DESC, u.patrimonio",
            'colunas'   => ['patrimonio' => ['Patrimônio', 'txt'], 'produto' => ['Produto', 'txt'], 'aquisicao_valor' => ['Aquisição', 'money'],
                            'locacoes' => ['Locações', 'num'], 'dias' => ['Dias na rua', 'num'], 'faturamento' => ['Faturamento', 'money'],
                            'manutencao' => ['Manutenção', 'money'], 'resultado' => ['Resultado', 'money']],
            'soma'      => ['locacoes', 'dias', 'faturamento', 'manutencao', 'resultado'],
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
    if (is_admin()) {
        echo '<a href="' . e(url('relatorios', ['a' => 'contador'])) . '"><div class="card"><h2>Exportação para o contador</h2>'
           . '<p class="muted">Lançamentos do caixa, recebimentos de locação com CPF/CNPJ, contas pagas e resumo do mês, em CSV ou .zip.</p>'
           . '<span class="selo azul">por mês</span></div></a>';
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
    $de = get('de', date('Y-m-d', strtotime('first day of -11 months')));
    $ate = get('ate', today());
    $de = valid_date($de) ? $de : date('Y-m-d', strtotime('first day of -11 months'));
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
        relatorio_impressao($rel, $linhas, $totais, $subtitulo, url('relatorios', $params));
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

function relatorio_impressao(array $rel, array $linhas, array $totais, string $subtitulo, string $voltar): void
{
    print_start($rel['titulo'], $voltar);
    cabecalho_impressao($rel['titulo'], '', $subtitulo);
    echo '<p class="muted">' . e($rel['descricao']) . '</p>';
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

// =====================================================================
// EXPORTAÇÃO PARA O CONTADOR (só administrador: traz CPF/CNPJ dos clientes)
// Regime de caixa: o que entrou e saiu no mês, pelos lançamentos do fluxo de caixa.
// =====================================================================

const MESES_CONTADOR = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto',
                          'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** CSV no padrão dos relatórios (BOM UTF-8, ";", vírgula decimal, proteção contra fórmula). */
function csv_texto(array $colunas, array $linhas): string
{
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($c) => $c[0], $colunas), ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($out, array_map(fn($campo, $c) => ($l[$campo] ?? null) === null ? '' : relatorio_csv_valor($c[1], $l[$campo]),
                                    array_keys($colunas), $colunas), ';', '"', '');
    }
    rewind($out);
    $txt = (string) stream_get_contents($out);
    fclose($out);
    return $txt;
}

/** Os arquivos do mês 'AAAA-MM': [nome => [titulo, colunas, linhas]]. */
function contador_arquivos(string $mes): array
{
    $de = "$mes-01";
    $ate = date('Y-m-t', strtotime($de));
    $p = ['de' => $de, 'ate' => $ate];
    $caixa = all("SELECT c.data, CASE c.tipo WHEN 'E' THEN 'Entrada' ELSE 'Saída' END AS tipo, c.categoria, c.descricao, c.forma,
                         CASE WHEN c.tipo = 'E' THEN c.valor END AS entrada, CASE WHEN c.tipo = 'S' THEN c.valor END AS saida,
                         l.numero AS locacao
                    FROM caixa c LEFT JOIN locacoes l ON l.id = c.locacao_id
                   WHERE c.data BETWEEN :de AND :ate ORDER BY c.data, c.criado_em", $p);
    $receb = all("SELECT c.data, l.numero AS locacao, cl.nome AS cliente, cl.cpf_cnpj, c.forma,
                         CASE c.tipo WHEN 'E' THEN 'Recebimento' ELSE 'Estorno' END AS tipo,
                         CASE WHEN c.tipo = 'E' THEN c.valor ELSE -c.valor END AS valor
                    FROM caixa c JOIN locacoes l ON l.id = c.locacao_id JOIN clientes cl ON cl.id = l.cliente_id
                   WHERE c.data BETWEEN :de AND :ate ORDER BY c.data, l.numero", $p);
    foreach ($receb as &$r) {
        $r['cpf_cnpj'] = doc_formatar($r['cpf_cnpj']);
    }
    unset($r);
    $contas = all('SELECT vencimento, pago_em, descricao, categoria, valor FROM contas_pagar
                    WHERE pago_em BETWEEN :de AND :ate ORDER BY pago_em, descricao', $p);
    $anterior = (float) val("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) FROM caixa WHERE data < :de", ['de' => $de]);
    $resumo = all("SELECT categoria, COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas,
                          COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas,
                          COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS resultado
                     FROM caixa WHERE data BETWEEN :de AND :ate GROUP BY categoria ORDER BY categoria", $p);
    $ent = array_sum(array_column($resumo, 'entradas'));
    $sai = array_sum(array_column($resumo, 'saidas'));
    $resumo[] = ['categoria' => 'TOTAL DO MÊS', 'entradas' => $ent, 'saidas' => $sai, 'resultado' => $ent - $sai];
    $resumo[] = ['categoria' => 'Saldo inicial (' . dt($de) . ')', 'resultado' => $anterior];
    $resumo[] = ['categoria' => 'Saldo final (' . dt($ate) . ')', 'resultado' => $anterior + $ent - $sai];

    return [
        "caixa_$mes.csv" => ['Lançamentos do caixa', ['data' => ['Data', 'date'], 'tipo' => ['Tipo', 'txt'], 'categoria' => ['Categoria', 'txt'],
            'descricao' => ['Descrição', 'txt'], 'forma' => ['Forma', 'txt'], 'entrada' => ['Entrada', 'money'], 'saida' => ['Saída', 'money'],
            'locacao' => ['Locação nº', 'txt']], $caixa],
        "recebimentos_locacao_$mes.csv" => ['Recebimentos de locação (com CPF/CNPJ do cliente)', ['data' => ['Data', 'date'],
            'locacao' => ['Locação nº', 'txt'], 'cliente' => ['Cliente', 'txt'], 'cpf_cnpj' => ['CPF/CNPJ', 'txt'], 'tipo' => ['Tipo', 'txt'],
            'forma' => ['Forma', 'txt'], 'valor' => ['Valor', 'money']], $receb],
        "contas_pagas_$mes.csv" => ['Contas pagas', ['vencimento' => ['Vencimento', 'date'], 'pago_em' => ['Pagamento', 'date'],
            'descricao' => ['Descrição', 'txt'], 'categoria' => ['Categoria', 'txt'], 'valor' => ['Valor', 'money']], $contas],
        "resumo_$mes.csv" => ['Resumo por categoria e saldos', ['categoria' => ['Categoria', 'txt'], 'entradas' => ['Entradas', 'money'],
            'saidas' => ['Saídas', 'money'], 'resultado' => ['Resultado', 'money']], $resumo],
    ];
}

/** Mês 'AAAA-MM' da URL (padrão: mês passado). */
function contador_mes(): string
{
    $mes = get('mes');
    return preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $mes) ? $mes : date('Y-m', strtotime('first day of last month'));
}

function relatorios_contador(): void
{
    require_admin();
    $mes = contador_mes();
    $meses = [];
    for ($i = 0; $i < 24; $i++) {
        $m = date('Y-m', strtotime("first day of -$i month"));
        $meses[$m] = MESES_CONTADOR[(int) substr($m, 5)] . ' de ' . substr($m, 0, 4);
    }
    $arquivos = contador_arquivos($mes);
    layout_start('Exportação para o contador', 'relatorios');
    ?>
    <div class="card">
        <h2>Exportação para o contador</h2>
        <p class="ajuda">Arquivos do mês em CSV (abrem no Excel), no regime de caixa: o que entrou e saiu pelo fluxo de caixa.
            Os recebimentos de locação trazem o CPF/CNPJ do cliente: envie só ao seu contador.</p>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="relatorios"><input type="hidden" name="a" value="contador">
            <select name="mes" aria-label="Mês"><?= options($meses, $mes) ?></select>
            <button class="btn btn-sec">Ver</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Arquivo</th><th>Conteúdo</th><th class="num">Linhas</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($arquivos as $nome => [$titulo, , $linhas]): ?>
                    <tr><td><code><?= e($nome) ?></code></td><td><?= e($titulo) ?></td><td class="num"><?= num(count($linhas)) ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('relatorios', ['a' => 'contador_baixar', 'mes' => $mes, 'arquivo' => $nome])) ?>">Baixar</a></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (class_exists('ZipArchive')): ?>
            <a class="btn" href="<?= e(url('relatorios', ['a' => 'contador_baixar', 'mes' => $mes, 'arquivo' => 'zip'])) ?>">Baixar tudo (.zip)</a>
        <?php endif; ?>
    </div>
    <?php
    layout_end();
}

function relatorios_contador_baixar(): never
{
    require_admin();
    $mes = contador_mes();
    $arquivo = get('arquivo');
    $arquivos = contador_arquivos($mes);
    if (!isset($arquivos[$arquivo]) && !($arquivo === 'zip' && class_exists('ZipArchive'))) {
        pagina_erro(404, 'Arquivo não encontrado.');
    }
    log_app('INFO', "Exportação para o contador: $mes ($arquivo).");
    header('Cache-Control: no-store');
    if ($arquivo === 'zip') {
        $tmp = tempnam(sys_get_temp_dir(), 'lgz');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $leia = "LocGestor - exportação para o contador\r\nEmpresa: " . (empresa()['razao_social'] ?? '') . "\r\nMês: $mes\r\n"
              . "Regime de caixa: entradas e saídas registradas no fluxo de caixa do sistema.\r\n\r\n";
        foreach ($arquivos as $nome => [$titulo, $colunas, $linhas]) {
            $zip->addFromString($nome, csv_texto($colunas, $linhas));
            $leia .= "$nome: $titulo (" . count($linhas) . " linhas)\r\n";
        }
        $zip->addFromString('LEIAME.txt', $leia);
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="contador_' . $mes . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
    [, $colunas, $linhas] = $arquivos[$arquivo];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    echo csv_texto($colunas, $linhas);
    exit;
}

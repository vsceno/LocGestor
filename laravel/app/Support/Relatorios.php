<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Relatórios e exportação para o contador: as mesmas consultas, colunas e totais de modulos/relatorios.php.
 * Cada relatório tem título, descrição, se usa período, SQL, colunas tipadas (txt, num, money, pct, date, link),
 * as colunas somadas e, se houver, o gráfico. No SQL, :de e :ate podem aparecer várias vezes.
 */
class Relatorios
{
    public const MESES = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto',
                          'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** @return array<string, array<string, mixed>> */
    public static function definicoes(): array
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

    /**
     * Executa o SQL do relatório. Cada :de e :ate vira um ? (o Laravel não repete parâmetro nomeado),
     * na mesma ordem em que aparecem.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function executar(array $rel, string $de, string $ate): array
    {
        $bind = [];
        $sql = preg_replace_callback('/:(de|ate)\b/', function ($m) use (&$bind, $de, $ate) {
            $bind[] = $m[1] === 'de' ? $de : $ate;
            return '?';
        }, $rel['sql']);
        return array_map(fn ($r) => (array) $r, DB::select($sql, $bind));
    }

    /** Totais das colunas somadas (soma de todas as linhas). */
    public static function totais(array $rel, array $linhas): array
    {
        $totais = [];
        foreach ($rel['soma'] ?? [] as $c) {
            $totais[$c] = array_sum(array_map(fn ($l) => (float) $l[$c], $linhas));
        }
        return $totais;
    }

    /** Valor para a tela e a impressão (texto; o link de locação é montado na tela). */
    public static function celula(string $tipo, $v): string
    {
        return match ($tipo) {
            'money' => moeda($v),
            'num'   => numero($v),
            'pct'   => numero($v, 1) . '%',
            'date'  => data_br($v),
            default => (string) ($v ?? ''),
        };
    }

    /** Valor para o CSV: números sem separador de milhar e com vírgula decimal (Excel em português). */
    public static function csvValor(string $tipo, $v): string
    {
        $s = match ($tipo) {
            'money' => number_format((float) $v, 2, ',', ''),
            'pct'   => number_format((float) $v, 1, ',', ''),
            'num'   => (string) (int) $v,
            'date'  => data_br($v),
            default => (string) ($v ?? ''),
        };
        // Evita que o Excel interprete texto como fórmula (CSV injection)
        return preg_match('/^[=+\-@\t\r]/', $s) && !in_array($tipo, ['money', 'pct', 'num'], true) ? "'" . $s : $s;
    }

    /** CSV de um relatório: BOM UTF-8, ";" e o total no fim, como o sistema antigo. */
    public static function csvRelatorio(array $rel, array $linhas, array $totais): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($c) => $c[0], $rel['colunas']), ';', '"', '');
        foreach ($linhas as $l) {
            $linha = [];
            foreach ($rel['colunas'] as $campo => [$rot, $tipo]) {
                $linha[] = self::csvValor($tipo, $l[$campo]);
            }
            fputcsv($out, $linha, ';', '"', '');
        }
        if ($totais && $linhas) {
            $linha = [];
            $primeira = true;
            foreach ($rel['colunas'] as $campo => [$rot, $tipo]) {
                $linha[] = isset($totais[$campo]) ? self::csvValor($tipo, $totais[$campo]) : ($primeira ? 'Total' : '');
                $primeira = false;
            }
            fputcsv($out, $linha, ';', '"', '');
        }
        rewind($out);
        $txt = (string) stream_get_contents($out);
        fclose($out);
        return $txt;
    }

    /** CSV de uma tabela do contador (linhas em que um campo vazio sai em branco). */
    public static function csvTexto(array $colunas, array $linhas): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($c) => $c[0], $colunas), ';', '"', '');
        foreach ($linhas as $l) {
            fputcsv($out, array_map(fn ($campo, $c) => ($l[$campo] ?? null) === null ? '' : self::csvValor($c[1], $l[$campo]),
                                    array_keys($colunas), $colunas), ';', '"', '');
        }
        rewind($out);
        $txt = (string) stream_get_contents($out);
        fclose($out);
        return $txt;
    }

    /**
     * Os arquivos do mês 'AAAA-MM' para o contador (regime de caixa): [nome => [título, colunas, linhas]].
     * Traz CPF/CNPJ dos clientes: só para o administrador.
     */
    public static function contadorArquivos(string $mes): array
    {
        $de = "$mes-01";
        $ate = date('Y-m-t', strtotime($de));
        $caixa = array_map(fn ($r) => (array) $r, DB::select("SELECT c.data, CASE c.tipo WHEN 'E' THEN 'Entrada' ELSE 'Saída' END AS tipo, c.categoria, c.descricao, c.forma,
                                    CASE WHEN c.tipo = 'E' THEN c.valor END AS entrada, CASE WHEN c.tipo = 'S' THEN c.valor END AS saida,
                                    l.numero AS locacao
                               FROM caixa c LEFT JOIN locacoes l ON l.id = c.locacao_id
                              WHERE c.data BETWEEN ? AND ? ORDER BY c.data, c.criado_em", [$de, $ate]));
        $receb = array_map(fn ($r) => (array) $r, DB::select("SELECT c.data, l.numero AS locacao, cl.nome AS cliente, cl.cpf_cnpj, c.forma,
                                    CASE c.tipo WHEN 'E' THEN 'Recebimento' ELSE 'Estorno' END AS tipo,
                                    CASE WHEN c.tipo = 'E' THEN c.valor ELSE -c.valor END AS valor
                               FROM caixa c JOIN locacoes l ON l.id = c.locacao_id JOIN clientes cl ON cl.id = l.cliente_id
                              WHERE c.data BETWEEN ? AND ? ORDER BY c.data, l.numero", [$de, $ate]));
        foreach ($receb as &$r) {
            $r['cpf_cnpj'] = doc_formatar($r['cpf_cnpj']);
        }
        unset($r);
        $contas = array_map(fn ($r) => (array) $r, DB::select('SELECT vencimento, pago_em, descricao, categoria, valor FROM contas_pagar
                                  WHERE pago_em BETWEEN ? AND ? ORDER BY pago_em, descricao', [$de, $ate]));
        $anterior = (float) DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS v FROM caixa WHERE data < ?", [$de])->v;
        $resumo = array_map(fn ($r) => (array) $r, DB::select("SELECT categoria, COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas,
                                    COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas,
                                    COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) AS resultado
                               FROM caixa WHERE data BETWEEN ? AND ? GROUP BY categoria ORDER BY categoria", [$de, $ate]));
        $ent = array_sum(array_column($resumo, 'entradas'));
        $sai = array_sum(array_column($resumo, 'saidas'));
        $resumo[] = ['categoria' => 'TOTAL DO MÊS', 'entradas' => $ent, 'saidas' => $sai, 'resultado' => $ent - $sai];
        $resumo[] = ['categoria' => 'Saldo inicial (' . data_br($de) . ')', 'resultado' => $anterior];
        $resumo[] = ['categoria' => 'Saldo final (' . data_br($ate) . ')', 'resultado' => $anterior + $ent - $sai];

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
}

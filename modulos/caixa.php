<?php
/*
 * Fluxo de caixa: extrato do período com saldo anterior, entradas, saídas e saldo final.
 * Recebimentos, adiantamentos e estornos de locação entram sozinhos pelas telas de locação.
 */

declare(strict_types=1);

/** Período e tipo do filtro (padrão: mês atual, todos os tipos). */
function caixa_filtro(): array
{
    $de = get('de', date('Y-m-01'));
    $ate = get('ate', today());
    $de = valid_date($de) ? $de : date('Y-m-01');
    $ate = valid_date($ate) ? $ate : today();
    if ($ate < $de) {
        [$de, $ate] = [$ate, $de];
    }
    $tipo = in_array(get('tipo'), ['E', 'S'], true) ? get('tipo') : '';
    return ['de' => $de, 'ate' => $ate, 'tipo' => $tipo];
}

function caixa_index(): void
{
    $f = caixa_filtro();
    $p = ['de' => $f['de'], 'ate' => $f['ate']];
    $condTipo = $f['tipo'] !== '' ? ' AND c.tipo = :tipo' : '';
    $pt = $f['tipo'] !== '' ? $p + ['tipo' => $f['tipo']] : $p;

    $anterior = (float) val("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) FROM caixa WHERE data < :de",
                            ['de' => $f['de']]);
    $tot = one("SELECT COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas,
                       COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas
                  FROM caixa WHERE data BETWEEN :de AND :ate", $p);
    $final = $anterior + $tot['entradas'] - $tot['saidas'];

    $linhas = all("SELECT c.id, c.data, c.tipo, c.categoria, c.descricao, c.valor, c.forma, c.locacao_id, l.numero AS locacao,
                          u.nome AS usuario
                     FROM caixa c
                     LEFT JOIN locacoes l ON l.id = c.locacao_id
                     LEFT JOIN usuarios u ON u.id = c.usuario_id
                    WHERE c.data BETWEEN :de AND :ate $condTipo
                    ORDER BY c.data, c.criado_em", $pt);
    $porForma = all("SELECT forma, COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas,
                            COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas
                       FROM caixa c WHERE data BETWEEN :de AND :ate $condTipo GROUP BY forma ORDER BY forma", $pt);
    $porCategoria = all("SELECT categoria, COALESCE(SUM(valor) FILTER (WHERE tipo = 'E'), 0) AS entradas,
                                COALESCE(SUM(valor) FILTER (WHERE tipo = 'S'), 0) AS saidas
                           FROM caixa c WHERE data BETWEEN :de AND :ate $condTipo GROUP BY categoria ORDER BY categoria", $pt);
    $categorias = array_column(all("SELECT DISTINCT categoria FROM caixa WHERE categoria <> '' ORDER BY categoria"), 'categoria');

    layout_start('Fluxo de caixa', 'caixa');
    ?>
    <div class="card">
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="caixa">
            <label>De <input type="date" name="de" value="<?= e($f['de']) ?>"></label>
            <label>Até <input type="date" name="ate" value="<?= e($f['ate']) ?>"></label>
            <select name="tipo" aria-label="Tipo"><?= options(['' => 'Entradas e saídas', 'E' => 'Só entradas', 'S' => 'Só saídas'], $f['tipo']) ?></select>
            <button class="btn btn-sec">Filtrar</button>
        </form>
    </div>
    <div class="kpis">
        <div class="kpi azul"><span>Saldo anterior (até <?= e(dt(date('Y-m-d', strtotime($f['de'] . ' -1 day')))) ?>)</span><strong><?= money($anterior) ?></strong></div>
        <div class="kpi verde"><span>Entradas</span><strong><?= money($tot['entradas']) ?></strong></div>
        <div class="kpi vermelho"><span>Saídas</span><strong><?= money($tot['saidas']) ?></strong></div>
        <div class="kpi"><span>Saldo final (<?= e(dt($f['ate'])) ?>)</span><strong><?= money($final) ?></strong></div>
    </div>

    <div class="card">
        <h2>Novo lançamento</h2>
        <form method="post" action="<?= e(url('caixa', ['a' => 'lancar'])) ?>" class="form">
            <?= csrf_field() ?>
            <div class="linha">
                <label class="obrig">Data<input type="date" name="data" value="<?= e(old('data', today())) ?>" max="<?= e(today()) ?>" required></label>
                <label class="obrig">Tipo<select name="tipo" required><?= options(['S' => 'Saída (despesa)', 'E' => 'Entrada'], old('tipo', 'S')) ?></select></label>
                <label class="obrig">Categoria<input type="text" name="categoria" value="<?= e(old('categoria')) ?>" list="cats" required maxlength="60">
                    <datalist id="cats"><?= options($categorias) ?></datalist></label>
                <label class="obrig">Valor R$<input type="text" name="valor" value="<?= e(old('valor')) ?>" inputmode="decimal" required placeholder="0,00"></label>
                <label class="obrig">Forma<select name="forma" required><?= options(FORMAS_PGTO, old('forma', 'PIX')) ?></select></label>
            </div>
            <label class="obrig">Descrição<input type="text" name="descricao" value="<?= e(old('descricao')) ?>" required maxlength="200"></label>
            <button class="btn">Lançar</button>
        </form>
    </div>

    <div class="card">
        <h2>Extrato de <?= e(dt($f['de'])) ?> a <?= e(dt($f['ate'])) ?></h2>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Forma</th><th class="num">Entrada</th><th class="num">Saída</th>
                    <?php if ($f['tipo'] === ''): ?><th class="num">Saldo</th><?php endif; ?><th></th></tr></thead>
                <tbody>
                <?php if ($f['tipo'] === ''): ?>
                    <tr><td><?= e(dt($f['de'])) ?></td><td colspan="5"><em>Saldo anterior</em></td><td class="num"><?= money($anterior) ?></td><td></td></tr>
                <?php endif; ?>
                <?php $acum = $anterior; foreach ($linhas as $l): $acum += $l['tipo'] === 'E' ? $l['valor'] : -$l['valor']; ?>
                    <tr>
                        <td class="nowrap"><?= e(dt($l['data'])) ?></td>
                        <td><?= $l['locacao'] ? '<a href="' . e(url('locacoes', ['a' => 'ver', 'n' => $l['locacao']])) . '">' . e($l['descricao']) . '</a>' : e($l['descricao']) ?>
                            <?php if ($l['usuario']): ?><br><span class="muted"><?= e($l['usuario']) ?></span><?php endif; ?></td>
                        <td><?= e($l['categoria']) ?></td>
                        <td><?= e($l['forma']) ?></td>
                        <td class="num"><?= $l['tipo'] === 'E' ? money($l['valor']) : '' ?></td>
                        <td class="num alerta"><?= $l['tipo'] === 'S' ? money($l['valor']) : '' ?></td>
                        <?php if ($f['tipo'] === ''): ?><td class="num"><?= money($acum) ?></td><?php endif; ?>
                        <td>
                            <?php if (is_admin() && $l['locacao_id'] === null): ?>
                                <form method="post" action="<?= e(url('caixa', ['a' => 'excluir'])) ?>" data-confirmar="Excluir o lançamento '<?= e($l['descricao']) ?>'?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($l['id']) ?>">
                                    <button class="btn btn-sec btn-peq" title="Excluir">✕</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$linhas): ?><tr><td colspan="8" class="vazio">Nenhum lançamento no período.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid-2">
        <?php foreach (['Por forma de pagamento' => [$porForma, 'forma'], 'Por categoria' => [$porCategoria, 'categoria']] as $titulo => [$dados, $campo]): ?>
        <div class="card">
            <h2><?= e($titulo) ?></h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th><?= $campo === 'forma' ? 'Forma' : 'Categoria' ?></th><th class="num">Entradas</th><th class="num">Saídas</th></tr></thead>
                    <tbody>
                    <?php foreach ($dados as $d): ?>
                        <tr><td><?= e($d[$campo] ?: '—') ?></td><td class="num"><?= money($d['entradas']) ?></td><td class="num"><?= money($d['saidas']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$dados): ?><tr><td colspan="3" class="vazio">—</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><td>Total</td><td class="num"><?= money(array_sum(array_column($dados, 'entradas'))) ?></td>
                        <td class="num"><?= money(array_sum(array_column($dados, 'saidas'))) ?></td></tr></tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    layout_end();
}

function caixa_lancar(): void
{
    lancamento_registrar($_POST);
    flash('Lançamento registrado.');
    voltar(url('caixa'));
}

function caixa_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Lançamento inválido.');
    }
    lancamento_excluir($id);
    flash('Lançamento excluído.');
    voltar(url('caixa'));
}

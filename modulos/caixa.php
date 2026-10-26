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
    caixa_abas('index');
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

function caixa_abas(string $ativa): void
{
    echo '<nav class="abas">';
    foreach (['index' => 'Lançamentos', 'contas' => 'Contas a pagar', 'previsao' => 'Previsão'] as $a => $rot) {
        echo '<a href="' . e(url('caixa', $a === 'index' ? [] : ['a' => $a])) . '"' . ($a === $ativa ? ' class="ativa"' : '') . '>' . e($rot) . '</a>';
    }
    echo '</nav>';
}

// ---------------------------------------------------------------------
// Contas a pagar e recorrentes
// ---------------------------------------------------------------------
const SITUACOES_CONTA = ['abertas' => 'Em aberto', 'pagas' => 'Pagas', 'todas' => 'Todas'];

function caixa_contas(): void
{
    contas_gerar_recorrentes();
    $situacao = isset(SITUACOES_CONTA[get('situacao')]) ? get('situacao') : 'abertas';
    $where = match ($situacao) { 'pagas' => 'c.pago_em IS NOT NULL', 'todas' => 'TRUE', default => 'c.pago_em IS NULL' };
    $pg = paginate("SELECT count(*) FROM contas_pagar c WHERE $where", [], 30);
    $contas = all("SELECT c.*, r.ativa AS recorrente_ativa FROM contas_pagar c LEFT JOIN contas_recorrentes r ON r.id = c.recorrente_id
                    WHERE $where ORDER BY " . ($situacao === 'pagas' ? 'c.pago_em DESC' : 'c.vencimento, c.descricao') . "
                    LIMIT {$pg['por']} OFFSET {$pg['offset']}");
    $resumo = one("SELECT COALESCE(SUM(valor) FILTER (WHERE vencimento < CURRENT_DATE), 0) AS vencidas,
                          COALESCE(SUM(valor) FILTER (WHERE vencimento BETWEEN CURRENT_DATE AND CURRENT_DATE + 30), 0) AS proximas
                     FROM contas_pagar WHERE pago_em IS NULL");
    $recorrentes = all('SELECT * FROM contas_recorrentes ORDER BY ativa DESC, dia, descricao');
    $categorias = array_column(all("SELECT categoria FROM caixa WHERE categoria <> '' UNION SELECT categoria FROM contas_pagar ORDER BY 1"), 'categoria');
    $editar = is_uuid(get('recorrente')) ? one('SELECT * FROM contas_recorrentes WHERE id = :id', ['id' => get('recorrente')]) : null;
    $r = $editar ?? [];

    layout_start('Fluxo de caixa', 'caixa');
    caixa_abas('contas');
    ?>
    <div class="kpis">
        <div class="kpi vermelho"><span>Vencidas</span><strong><?= money($resumo['vencidas']) ?></strong><small>em aberto, com vencimento passado</small></div>
        <div class="kpi amarelo"><span>Próximos 30 dias</span><strong><?= money($resumo['proximas']) ?></strong><small>a vencer</small></div>
    </div>
    <div class="grid-2">
        <form method="post" action="<?= e(url('caixa', ['a' => 'conta_salvar'])) ?>" class="form card">
            <?= csrf_field() ?>
            <h2>Nova conta</h2>
            <label class="obrig">Descrição<input type="text" name="descricao" value="<?= e(old('descricao')) ?>" required maxlength="200" placeholder="Ex.: Conta de luz de outubro"></label>
            <div class="linha">
                <label class="obrig">Categoria<input type="text" name="categoria" value="<?= e(old('categoria')) ?>" list="cats-conta" required maxlength="60"></label>
                <label class="obrig">Valor R$<input type="text" name="valor" value="<?= e(old('valor')) ?>" inputmode="decimal" required placeholder="0,00"></label>
                <label class="obrig">Vencimento<input type="date" name="vencimento" value="<?= e(old('vencimento')) ?>" required></label>
            </div>
            <datalist id="cats-conta"><?= options($categorias) ?></datalist>
            <button class="btn">Incluir conta</button>
        </form>
        <div class="card">
            <h2><?= $editar ? 'Editar conta recorrente' : 'Contas recorrentes' ?></h2>
            <?php if (!$editar && $recorrentes): ?>
                <div class="tabela-wrap">
                    <table class="tabela">
                        <thead><tr><th>Descrição</th><th class="num">Dia</th><th class="num">Valor</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($recorrentes as $x): ?>
                            <tr><td><?= e($x['descricao']) ?> <?= $x['ativa'] ? '' : '<span class="selo">inativa</span>' ?><br><span class="muted"><?= e($x['categoria']) ?></span></td>
                                <td class="num"><?= num($x['dia']) ?></td><td class="num"><?= money($x['valor']) ?></td>
                                <td><?php if (is_admin()): ?><a class="btn btn-sec btn-peq" href="<?= e(url('caixa', ['a' => 'contas', 'recorrente' => $x['id']])) ?>">Editar</a><?php endif; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if (is_admin()): ?>
                <form method="post" action="<?= e(url('caixa', ['a' => 'recorrente_salvar'])) ?>" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($r['id'] ?? '') ?>">
                    <?php if (!$editar): ?><p class="ajuda">Despesa fixa que vence todo mês (aluguel, salário, internet): as parcelas aparecem sozinhas até 90 dias à frente.</p><?php endif; ?>
                    <label class="obrig">Descrição<input type="text" name="descricao" value="<?= e(valor_form($r, 'descricao')) ?>" required maxlength="200" placeholder="Ex.: Aluguel do galpão"></label>
                    <div class="linha">
                        <label class="obrig">Categoria<input type="text" name="categoria" value="<?= e(valor_form($r, 'categoria')) ?>" list="cats-conta" required maxlength="60"></label>
                        <label class="obrig">Valor R$<input type="text" name="valor" inputmode="decimal" required placeholder="0,00"
                               value="<?= e(has_old() ? old('valor') : (isset($r['valor']) ? num($r['valor'], 2) : '')) ?>"></label>
                    </div>
                    <div class="linha">
                        <label class="obrig">Vence todo dia<input type="number" name="dia" value="<?= e(valor_form($r, 'dia', '10')) ?>" min="1" max="28" required></label>
                        <label class="obrig">A partir de<input type="date" name="inicio" value="<?= e(valor_form($r, 'inicio', today())) ?>" required></label>
                    </div>
                    <label class="check"><input type="checkbox" name="ativa" value="1" <?= marcado_form($r, 'ativa', true) ? 'checked' : '' ?>> Ativa (desmarcar remove as parcelas futuras em aberto)</label>
                    <div class="acoes">
                        <button class="btn btn-sec"><?= $editar ? 'Salvar' : 'Incluir recorrente' ?></button>
                        <?php if ($editar): ?><a class="btn btn-sec" href="<?= e(url('caixa', ['a' => 'contas'])) ?>">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-titulo">
            <h2>Contas</h2>
            <form method="get" class="filtros" style="margin:0">
                <input type="hidden" name="p" value="caixa"><input type="hidden" name="a" value="contas">
                <select name="situacao" aria-label="Situação"><?= options(SITUACOES_CONTA, $situacao) ?></select>
                <button class="btn btn-sec btn-peq">Filtrar</button>
            </form>
        </div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($contas as $c): $vencida = $c['pago_em'] === null && $c['vencimento'] < today(); ?>
                    <tr>
                        <td class="nowrap"><?= e(dt($c['vencimento'])) ?></td>
                        <td><?= e($c['descricao']) ?><?= $c['recorrente_id'] ? ' <span class="selo azul">recorrente</span>' : '' ?></td>
                        <td><?= e($c['categoria']) ?></td>
                        <td class="num"><?= money($c['valor']) ?></td>
                        <td><?= $c['pago_em'] ? '<span class="selo verde">paga em ' . e(dt($c['pago_em'])) . '</span>'
                                               : ($vencida ? '<span class="selo vermelho">vencida</span>' : '<span class="selo amarelo">a vencer</span>') ?></td>
                        <td>
                            <?php if ($c['pago_em'] === null): ?>
                                <form method="post" action="<?= e(url('caixa', ['a' => 'conta_pagar'])) ?>" class="filtros" style="margin:0">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
                                    <input type="date" name="data" value="<?= e(today()) ?>" max="<?= e(today()) ?>" aria-label="Data do pagamento" style="width:150px">
                                    <select name="forma" aria-label="Forma"><?= options(FORMAS_PGTO, 'PIX') ?></select>
                                    <button class="btn btn-peq">Pagar</button>
                                </form>
                                <?php if (is_admin()): ?>
                                    <form method="post" action="<?= e(url('caixa', ['a' => 'conta_excluir'])) ?>" data-confirmar="Excluir esta conta?" style="margin-top:4px">
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
                                        <button class="btn btn-sec btn-peq">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif (is_admin()): ?>
                                <form method="post" action="<?= e(url('caixa', ['a' => 'conta_desfazer'])) ?>" data-confirmar="Desfazer o pagamento? A saída é apagada do caixa.">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
                                    <button class="btn btn-sec btn-peq">Desfazer pagamento</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$contas): ?><tr><td colspan="6" class="vazio">Nenhuma conta <?= $situacao === 'abertas' ? 'em aberto' : 'encontrada' ?>.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'caixa', ['a' => 'contas', 'situacao' => $situacao]) ?>
    </div>
    <?php
    layout_end();
}

function caixa_conta_salvar(): void
{
    conta_salvar($_POST);
    flash('Conta incluída.');
    redirect(url('caixa', ['a' => 'contas']));
}

function caixa_recorrente_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Conta recorrente inválida.');
    }
    recorrente_salvar($_POST, $id === '' ? null : $id);
    flash($id === '' ? 'Conta recorrente incluída: as parcelas já aparecem na lista.' : 'Conta recorrente atualizada.');
    redirect(url('caixa', ['a' => 'contas']));
}

function caixa_conta_pagar(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Conta não encontrada.');
    }
    conta_pagar($id, $_POST);
    flash('Conta paga e lançada no caixa.');
    redirect(url('caixa', ['a' => 'contas']));
}

function caixa_conta_desfazer(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Conta paga não encontrada.');
    }
    conta_desfazer_pagamento($id);
    flash('Pagamento desfeito: a conta voltou a ficar em aberto.');
    redirect(url('caixa', ['a' => 'contas', 'situacao' => 'todas']));
}

function caixa_conta_excluir(): void
{
    require_admin();
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM contas_pagar WHERE id = :id AND pago_em IS NULL', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Só é possível excluir conta em aberto.');
    }
    flash('Conta excluída.');
    redirect(url('caixa', ['a' => 'contas']));
}

// ---------------------------------------------------------------------
// Previsão de caixa
// ---------------------------------------------------------------------
function caixa_previsao(): void
{
    $dias = in_array((int) get('dias'), [30, 60, 90], true) ? (int) get('dias') : 60;
    $p = previsao_caixa($dias);
    $final = end($p['semanas'])['saldo'] ?? $p['saldo'];
    $menor = min(array_merge([$p['saldo']], array_column($p['semanas'], 'saldo')));   // inclui o ponto de partida
    $grafico = ['tipo' => 'line', 'moeda' => true,
                'rotulos' => array_map(fn($s) => date('d/m', strtotime($s['inicio'])), $p['semanas']),
                'series' => [['nome' => 'Saldo projetado', 'cor' => '#1a73e8', 'dados' => array_column($p['semanas'], 'saldo')]]];

    layout_start('Fluxo de caixa', 'caixa');
    caixa_abas('previsao');
    ?>
    <div class="kpis">
        <div class="kpi azul"><span>Saldo hoje</span><strong><?= money($p['saldo']) ?></strong><small>tudo o que entrou menos o que saiu</small></div>
        <div class="kpi <?= $final < 0 ? 'vermelho' : 'verde' ?>"><span>Saldo em <?= num($dias) ?> dias</span><strong><?= money($final) ?></strong><small>se tudo for recebido e pago em dia</small></div>
        <div class="kpi <?= $menor < 0 ? 'vermelho' : '' ?>"><span>Menor saldo no período</span><strong><?= money($menor) ?></strong>
            <small><?= $menor < 0 ? 'atenção: o caixa fica negativo' : 'o caixa não fica negativo' ?></small></div>
        <div class="kpi amarelo"><span>Vencidos</span><strong><?= money($p['receber_vencido']) ?></strong>
            <small>a receber · <?= money($p['pagar_vencido']) ?> a pagar</small></div>
    </div>
    <div class="card">
        <div class="card-titulo">
            <h2>Previsão semana a semana</h2>
            <form method="get" class="filtros" style="margin:0">
                <input type="hidden" name="p" value="caixa"><input type="hidden" name="a" value="previsao">
                <select name="dias" aria-label="Horizonte"><?= options([30 => 'Próximos 30 dias', 60 => 'Próximos 60 dias', 90 => 'Próximos 90 dias'], (string) $dias) ?></select>
                <button class="btn btn-sec btn-peq">Ver</button>
            </form>
        </div>
        <p class="ajuda">Entradas: saldo das locações em aberto na data prevista de devolução (as atrasadas e as devolvidas com saldo, nesta semana)
            e reservas ativas estimadas pelos preços atuais. Saídas: contas a pagar em aberto (as vencidas, nesta semana).</p>
        <div class="grafico"><canvas <?= 'data-grafico="' . e(json_encode($grafico, JSON_UNESCAPED_UNICODE)) . '"' ?>></canvas></div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Semana</th><th class="num">Entradas previstas</th><th class="num">Saídas previstas</th><th class="num">Saldo projetado</th></tr></thead>
                <tbody>
                <?php foreach ($p['semanas'] as $s): ?>
                    <tr><td><?= e(dt($s['inicio'])) ?> a <?= e(dt($s['fim'])) ?></td><td class="num"><?= money($s['entradas']) ?></td>
                        <td class="num alerta"><?= money($s['saidas']) ?></td>
                        <td class="num"><strong<?= $s['saldo'] < 0 ? ' class="alerta"' : '' ?>><?= money($s['saldo']) ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h2>O que compõe a previsão</h2>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Data</th><th>Descrição</th><th class="num">Entra</th><th class="num">Sai</th></tr></thead>
                <tbody>
                <?php foreach ($p['itens'] as $x): ?>
                    <tr><td class="nowrap"><?= e(dt($x['data'])) ?><?= $x['vencido'] ? ' <span class="selo vermelho">vencido</span>' : '' ?></td>
                        <td><?= e($x['descricao']) ?></td>
                        <td class="num"><?= $x['tipo'] === 'E' ? money($x['valor']) : '' ?></td>
                        <td class="num alerta"><?= $x['tipo'] === 'S' ? money($x['valor']) : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$p['itens']): ?><tr><td colspan="4" class="vazio">Nada previsto no período.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end(['assets/vendor/chart.umd.js', 'assets/js/graficos.js']);
}

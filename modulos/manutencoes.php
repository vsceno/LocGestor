<?php
/*
 * Manutenção: ordens de conserto/revisão. Enquanto aberta, as peças ficam fora do estoque disponível.
 * Pode ser de uma unidade (patrimônio) ou de N peças de um produto sem patrimônio.
 */

declare(strict_types=1);

const SITUACOES_MANUTENCAO = ['abertas' => 'Em manutenção', 'concluidas' => 'Concluídas', 'todas' => 'Todas'];

function manutencoes_index(): void
{
    $situacao = get('situacao', 'abertas');
    $situacao = isset(SITUACOES_MANUTENCAO[$situacao]) ? $situacao : 'abertas';
    $where = match ($situacao) { 'concluidas' => 'm.data_fim IS NOT NULL', 'todas' => 'TRUE', default => 'm.data_fim IS NULL' };
    $pg = paginate("SELECT count(*) FROM manutencoes m WHERE $where");
    $lista = all("SELECT m.id, m.numero, m.quantidade, m.tipo, m.descricao, m.data_inicio, m.data_fim, m.custo,
                         p.descricao AS produto, u.patrimonio
                    FROM manutencoes m JOIN produtos p ON p.id = m.produto_id LEFT JOIN unidades u ON u.id = m.unidade_id
                   WHERE $where ORDER BY m.data_fim IS NULL DESC, m.numero DESC
                   LIMIT {$pg['por']} OFFSET {$pg['offset']}");
    $custoAno = (float) val("SELECT COALESCE(SUM(custo), 0) FROM manutencoes WHERE data_fim >= date_trunc('year', CURRENT_DATE)");
    $abertas = (int) val('SELECT COALESCE(SUM(quantidade), 0) FROM manutencoes WHERE data_fim IS NULL');

    layout_start('Manutenção', 'manutencoes');
    ?>
    <div class="kpis">
        <div class="kpi amarelo"><span>Peças em manutenção</span><strong><?= num($abertas) ?></strong><small>fora do estoque disponível</small></div>
        <div class="kpi"><span>Custo no ano</span><strong><?= money($custoAno) ?></strong><small>manutenções concluídas em <?= date('Y') ?></small></div>
    </div>
    <div class="card">
        <div class="card-titulo">
            <h2>Ordens de manutenção</h2>
            <a class="btn" href="<?= e(url('manutencoes', ['a' => 'nova'])) ?>">+ Nova manutenção</a>
        </div>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="manutencoes">
            <select name="situacao" aria-label="Situação"><?= options(SITUACOES_MANUTENCAO, $situacao) ?></select>
            <button class="btn btn-sec">Filtrar</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Produto</th><th class="num">Qtd.</th><th>Tipo</th><th>Problema / serviço</th>
                    <th>Início</th><th>Conclusão</th><th class="num">Custo</th></tr></thead>
                <tbody>
                <?php foreach ($lista as $m): ?>
                    <tr>
                        <td><a href="<?= e(url('manutencoes', ['a' => 'ver', 'id' => $m['id']])) ?>"><?= num($m['numero']) ?></a></td>
                        <td><?= e($m['produto']) ?><?= $m['patrimonio'] ? ' <code>' . e($m['patrimonio']) . '</code>' : '' ?></td>
                        <td class="num"><?= num($m['quantidade']) ?></td>
                        <td><?= e($m['tipo']) ?></td>
                        <td><?= e(mb_strimwidth($m['descricao'], 0, 80, '…')) ?></td>
                        <td><?= e(dt($m['data_inicio'])) ?></td>
                        <td><?= $m['data_fim'] ? e(dt($m['data_fim'])) : '<span class="selo amarelo">em manutenção</span>' ?></td>
                        <td class="num"><?= $m['data_fim'] ? money($m['custo']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lista): ?>
                    <tr><td colspan="8" class="vazio">Nenhuma manutenção <?= $situacao === 'abertas' ? 'em aberto' : 'encontrada' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'manutencoes', ['situacao' => $situacao]) ?>
    </div>
    <?php
    layout_end();
}

function manutencoes_nova(): void
{
    $produtos = array_column(all("SELECT id, codigo || ' — ' || descricao AS nome FROM produtos WHERE ativo ORDER BY descricao"), 'nome', 'id');
    // Só unidades livres agora (ativas, fora de manutenção e fora da rua)
    $unidades = array_column(all("SELECT u.id, u.patrimonio || ' — ' || p.descricao AS nome
                                    FROM unidades u JOIN produtos p ON p.id = u.produto_id
                                   WHERE u.ativa
                                     AND NOT EXISTS (SELECT 1 FROM manutencoes m WHERE m.unidade_id = u.id AND m.data_fim IS NULL)
                                     AND NOT EXISTS (SELECT 1 FROM locacao_unidades lu WHERE lu.unidade_id = u.id AND lu.devolucao_id IS NULL)
                                   ORDER BY u.patrimonio"), 'nome', 'id');
    $prod = old('produto_id', is_uuid(get('produto')) ? get('produto') : '');
    $unid = old('unidade_id', is_uuid(get('unidade')) ? get('unidade') : '');

    layout_start('Nova manutenção', 'manutencoes');
    ?>
    <form method="post" action="<?= e(url('manutencoes', ['a' => 'abrir'])) ?>" class="form">
        <?= csrf_field() ?>
        <div class="card">
            <h2>O que vai para a manutenção</h2>
            <div class="linha">
                <label>Unidade (patrimônio)
                    <select name="unidade_id"><?= options($unidades, $unid, '— nenhuma: informar produto e quantidade —') ?></select>
                    <span class="ajuda">Escolhida a unidade, o produto e a quantidade vêm dela.</span>
                </label>
            </div>
            <div class="linha">
                <label>Produto
                    <select name="produto_id"><?= options($produtos, $prod, '— escolha —') ?></select>
                </label>
                <label>Quantidade
                    <input type="number" name="quantidade" value="<?= e(old('quantidade', '1')) ?>" min="1" step="1">
                </label>
            </div>
            <p class="ajuda">Enquanto a manutenção estiver aberta, as peças não podem ser alugadas nem reservadas.</p>
        </div>
        <div class="card">
            <h2>Serviço</h2>
            <div class="linha">
                <label class="obrig">Tipo<select name="tipo" required><?= options(TIPOS_MANUTENCAO, old('tipo', 'corretiva')) ?></select></label>
                <label class="obrig">Início<input type="date" name="data_inicio" value="<?= e(old('data_inicio', today())) ?>" max="<?= e(today()) ?>" required></label>
                <label>Fornecedor / oficina<input type="text" name="fornecedor" value="<?= e(old('fornecedor')) ?>" maxlength="100"></label>
            </div>
            <label class="obrig">Problema ou serviço a fazer
                <textarea name="descricao" required maxlength="500"><?= e(old('descricao')) ?></textarea>
            </label>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Abrir manutenção</button>
            <a class="btn btn-sec" href="<?= e(url('manutencoes')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end();
}

function manutencoes_abrir(): void
{
    $r = manutencao_abrir($_POST);
    flash("Manutenção nº {$r['numero']} aberta. As peças saíram do estoque disponível.");
    redirect(url('manutencoes', ['a' => 'ver', 'id' => $r['id']]));
}

function manutencao_da_url(): array
{
    $id = get('id');
    $m = is_uuid($id) ? one('SELECT m.*, p.descricao AS produto, p.codigo, u.patrimonio, u.serie, us.nome AS usuario
                                FROM manutencoes m JOIN produtos p ON p.id = m.produto_id
                                LEFT JOIN unidades u ON u.id = m.unidade_id LEFT JOIN usuarios us ON us.id = m.usuario_id
                               WHERE m.id = :id', ['id' => $id]) : null;
    if ($m === null) {
        pagina_erro(404, 'Manutenção não encontrada.');
    }
    return $m;
}

function manutencoes_ver(): void
{
    $m = manutencao_da_url();
    $aberta = $m['data_fim'] === null;
    layout_start('Manutenção nº ' . $m['numero'], 'manutencoes');
    ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-titulo">
                <h2>Manutenção nº <?= num($m['numero']) ?></h2>
                <?= $aberta ? '<span class="selo amarelo">em manutenção</span>' : '<span class="selo verde">concluída</span>' ?>
            </div>
            <dl class="dados">
                <dt>Produto</dt><dd><code><?= e($m['codigo']) ?></code> <?= e($m['produto']) ?></dd>
                <?php if ($m['patrimonio']): ?>
                    <dt>Unidade</dt><dd><a href="<?= e(url('produtos', ['a' => 'unidade', 'id' => $m['unidade_id']])) ?>"><?= e($m['patrimonio']) ?></a>
                        <?= $m['serie'] !== '' ? '<span class="muted">· série ' . e($m['serie']) . '</span>' : '' ?></dd>
                <?php endif; ?>
                <dt>Quantidade</dt><dd><?= num($m['quantidade']) ?></dd>
                <dt>Tipo</dt><dd><?= e(TIPOS_MANUTENCAO[$m['tipo']] ?? $m['tipo']) ?></dd>
                <dt>Problema / serviço</dt><dd><?= nl2br(e($m['descricao'])) ?></dd>
                <dt>Fornecedor</dt><dd><?= e($m['fornecedor'] ?: '—') ?></dd>
                <dt>Início</dt><dd><?= e(dt($m['data_inicio'])) ?> <span class="muted">· aberta por <?= e($m['usuario'] ?? '—') ?></span></dd>
                <?php if (!$aberta): ?>
                    <dt>Conclusão</dt><dd><?= e(dt($m['data_fim'])) ?></dd>
                    <dt>Serviço realizado</dt><dd><?= nl2br(e($m['solucao'] ?: '—')) ?></dd>
                    <dt>Custo</dt><dd><?= money($m['custo']) ?><?= $m['caixa_id'] ? ' <span class="muted">· lançado no caixa</span>' : '' ?></dd>
                <?php endif; ?>
            </dl>
        </div>
        <?php if ($aberta): ?>
            <div>
                <form method="post" action="<?= e(url('manutencoes', ['a' => 'concluir'])) ?>" class="form card">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                    <h2>Concluir</h2>
                    <div class="linha">
                        <label class="obrig">Data de volta<input type="date" name="data_fim" value="<?= e(old('data_fim', today())) ?>"
                               min="<?= e($m['data_inicio']) ?>" max="<?= e(today()) ?>" required></label>
                        <label>Custo R$<input type="text" name="custo" value="<?= e(old('custo')) ?>" inputmode="decimal" placeholder="0,00"></label>
                    </div>
                    <label>Serviço realizado<textarea name="solucao" maxlength="500"><?= e(old('solucao')) ?></textarea></label>
                    <div class="linha">
                        <label class="check"><input type="checkbox" name="lancar_caixa" value="1" <?= old('lancar_caixa', '1') ? 'checked' : '' ?>> Lançar o custo como saída no caixa</label>
                        <label>Forma de pagamento<select name="forma"><?= options(FORMAS_PGTO, old('forma', 'PIX')) ?></select></label>
                    </div>
                    <p class="ajuda">As peças voltam para o estoque disponível.</p>
                    <button class="btn">Concluir manutenção</button>
                </form>
                <form method="post" action="<?= e(url('manutencoes', ['a' => 'excluir'])) ?>" data-confirmar="Excluir esta manutenção (aberta por engano)?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                    <button class="btn btn-perigo btn-peq">Excluir (aberta por engano)</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <a class="btn btn-sec" href="<?= e(url('manutencoes')) ?>">Voltar</a>
    <?php
    layout_end();
}

function manutencoes_concluir(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Manutenção não encontrada.');
    }
    manutencao_concluir($id, $_POST);
    flash('Manutenção concluída. As peças voltaram ao estoque disponível.');
    redirect(url('manutencoes', ['a' => 'ver', 'id' => $id]));
}

function manutencoes_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM manutencoes WHERE id = :id AND data_fim IS NULL', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Só é possível excluir manutenção em aberto.');
    }
    flash('Manutenção excluída.');
    redirect(url('manutencoes'));
}

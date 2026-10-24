<?php
/*
 * Produtos e categorias.
 * Disponível hoje = estoque - alugado (na rua) - reservado para hoje.
 */

declare(strict_types=1);

const SITUACOES_PRODUTO = ['ativos' => 'Ativos', 'todos' => 'Todos', 'inativos' => 'Inativos'];

/** Selo de disponibilidade: vermelho sem estoque, amarelo com 20% ou menos, verde no resto. */
function selo_disponivel(int $disp, int $total): string
{
    if ($disp <= 0) {
        return '<span class="selo vermelho">' . num($disp) . '</span>';
    }
    $cor = $disp <= max(1, (int) ceil($total * 0.2)) ? 'amarelo' : 'verde';
    return '<span class="selo ' . $cor . '">' . num($disp) . '</span>';
}

function categorias_lista(): array
{
    return array_column(all('SELECT id, nome FROM categorias ORDER BY nome'), 'nome', 'id');
}

function produtos_index(): void
{
    $q = mb_substr(get('q'), 0, 100);
    $cat = get('categoria');
    $situacao = get('situacao', 'ativos');
    $situacao = isset(SITUACOES_PRODUTO[$situacao]) ? $situacao : 'ativos';

    $where = [match ($situacao) { 'todos' => 'TRUE', 'inativos' => 'NOT p.ativo', default => 'p.ativo' }];
    $params = [];
    if ($q !== '') {
        $where[] = '(lower(p.codigo) LIKE lower(:q1) OR lower(p.descricao) LIKE lower(:q2))';
        $params += ['q1' => like($q), 'q2' => like($q)];
    }
    if (is_uuid($cat)) {
        $where[] = 'p.categoria_id = :cat';
        $params['cat'] = $cat;
    }
    $sqlWhere = implode(' AND ', $where);

    $pg = paginate("SELECT count(*) FROM produtos p WHERE $sqlWhere", $params);
    $produtos = all("
        SELECT p.id, p.codigo, p.descricao, p.unidade, p.qtd_total, p.valor_diaria, p.ativo, c.nome AS categoria,
               COALESCE(a.qtd_alugada, 0) AS alugado,
               COALESCE((SELECT SUM(ri.quantidade)
                           FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                          WHERE r.status = 'ativa' AND ri.produto_id = p.id
                            AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS reservado
          FROM produtos p
          LEFT JOIN categorias c ON c.id = p.categoria_id
          LEFT JOIN vw_produto_alugado a ON a.produto_id = p.id
         WHERE $sqlWhere
         ORDER BY p.descricao
         LIMIT {$pg['por']} OFFSET {$pg['offset']}", $params);

    layout_start('Produtos', 'produtos');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Produtos e equipamentos</h2>
            <div class="acoes">
                <a class="btn btn-sec" href="<?= e(url('produtos', ['a' => 'categorias'])) ?>">Categorias</a>
                <a class="btn" href="<?= e(url('produtos', ['a' => 'novo'])) ?>">+ Novo produto</a>
            </div>
        </div>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="produtos">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Código ou descrição" aria-label="Buscar">
            <select name="categoria" aria-label="Categoria"><?= options(categorias_lista(), $cat, 'Todas as categorias') ?></select>
            <select name="situacao" aria-label="Situação"><?= options(SITUACOES_PRODUTO, $situacao) ?></select>
            <button class="btn btn-sec">Filtrar</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead>
                <tr><th>Código</th><th>Descrição</th><th>Categoria</th><th class="num">Diária</th>
                    <th class="num">Estoque</th><th class="num">Alugado</th><th class="num">Reservado hoje</th><th class="num">Disponível</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($produtos as $p): $disp = $p['qtd_total'] - $p['alugado'] - $p['reservado']; ?>
                    <tr>
                        <td class="nowrap"><code><?= e($p['codigo']) ?></code></td>
                        <td><?= e($p['descricao']) ?> <?= $p['ativo'] ? '' : '<span class="selo">inativo</span>' ?></td>
                        <td><?= e($p['categoria'] ?? '—') ?></td>
                        <td class="num"><?= money($p['valor_diaria']) ?></td>
                        <td class="num"><?= num($p['qtd_total']) ?> <span class="muted"><?= e($p['unidade']) ?></span></td>
                        <td class="num"><?= num($p['alugado']) ?></td>
                        <td class="num"><?= num($p['reservado']) ?></td>
                        <td class="num"><?= selo_disponivel((int) $disp, (int) $p['qtd_total']) ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('produtos', ['a' => 'editar', 'id' => $p['id']])) ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$produtos): ?>
                    <tr><td colspan="9" class="vazio">Nenhum produto encontrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'produtos', array_filter(['q' => $q, 'categoria' => is_uuid($cat) ? $cat : '', 'situacao' => $situacao])) ?>
    </div>
    <?php
    layout_end();
}

function produto_da_url(): array
{
    $id = get('id');
    $p = is_uuid($id) ? one('SELECT * FROM produtos WHERE id = :id', ['id' => $id]) : null;
    if ($p === null) {
        pagina_erro(404, 'Produto não encontrado.');
    }
    return $p;
}

function produtos_novo(): void
{
    produto_form([], 'Novo produto');
}

function produtos_editar(): void
{
    produto_form(produto_da_url(), 'Editar produto');
}

function produto_form(array $p, string $titulo): void
{
    $id = $p['id'] ?? '';
    $alugado = $id ? qtd_alugada($id) : 0;
    $usado = $id && val('SELECT 1 FROM locacao_itens WHERE produto_id = :a UNION ALL SELECT 1 FROM reserva_itens WHERE produto_id = :b LIMIT 1',
                        ['a' => $id, 'b' => $id]) !== null;
    $moeda = fn($campo) => has_old() ? old($campo) : (isset($p[$campo]) ? num($p[$campo], 2) : '');

    layout_start($titulo, 'produtos');
    ?>
    <form method="post" action="<?= e(url('produtos', ['a' => 'salvar'])) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <div class="card">
            <h2>Produto</h2>
            <div class="linha">
                <label class="obrig">Código
                    <input type="text" name="codigo" value="<?= e(valor_form($p, 'codigo')) ?>" required maxlength="30" style="text-transform:uppercase">
                </label>
                <label>Categoria
                    <select name="categoria_id"><?= options(categorias_lista(), valor_form($p, 'categoria_id'), '— sem categoria —') ?></select>
                </label>
                <label class="obrig">Unidade
                    <input type="text" name="unidade" value="<?= e(valor_form($p, 'unidade', 'UN')) ?>" required maxlength="10" list="unidades">
                    <datalist id="unidades"><?= options(['UN', 'PC', 'JG', 'CJ', 'M', 'M2']) ?></datalist>
                </label>
            </div>
            <label class="obrig">Descrição
                <input type="text" name="descricao" value="<?= e(valor_form($p, 'descricao')) ?>" required maxlength="150">
            </label>
        </div>
        <div class="card">
            <h2>Estoque e valores</h2>
            <div class="linha">
                <label class="obrig">Quantidade em estoque
                    <input type="number" name="qtd_total" value="<?= e(valor_form($p, 'qtd_total', '0')) ?>" min="<?= $alugado ?>" step="1" required>
                    <?php if ($alugado > 0): ?><span class="ajuda"><?= num($alugado) ?> na rua agora: o estoque não pode ser menor que isso.</span><?php endif; ?>
                </label>
                <label class="obrig">Valor da diária (R$)
                    <input type="text" name="valor_diaria" value="<?= e($moeda('valor_diaria')) ?>" inputmode="decimal" required placeholder="0,00">
                </label>
                <label>Valor de reposição (R$)
                    <input type="text" name="valor_reposicao" value="<?= e($moeda('valor_reposicao')) ?>" inputmode="decimal" placeholder="0,00">
                    <span class="ajuda">Cobrado em caso de perda ou avaria total.</span>
                </label>
                <label>NCM
                    <input type="text" name="ncm" value="<?= e(valor_form($p, 'ncm')) ?>" inputmode="numeric" maxlength="8" pattern="\d{8}">
                    <span class="ajuda">8 dígitos. Reservado para a futura NF-e de remessa.</span>
                </label>
            </div>
            <label class="check"><input type="checkbox" name="ativo" value="1" <?= marcado_form($p, 'ativo', true) ? 'checked' : '' ?>> Ativo (aparece para novas locações)</label>
            <label>Observações
                <textarea name="obs" maxlength="2000"><?= e(valor_form($p, 'obs')) ?></textarea>
            </label>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Salvar</button>
            <a class="btn btn-sec" href="<?= e(url('produtos')) ?>">Cancelar</a>
        </div>
    </form>
    <?php if ($id && !$usado): ?>
        <form method="post" action="<?= e(url('produtos', ['a' => 'excluir'])) ?>" data-confirmar="Excluir este produto definitivamente?" style="margin-top:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <button class="btn btn-perigo btn-peq">Excluir produto</button>
        </form>
    <?php endif; ?>
    <?php
    layout_end();
}

function produtos_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Produto inválido.');
    }
    $novo = $id === '';
    produto_salvar($_POST, $novo ? null : $id);
    flash($novo ? 'Produto cadastrado.' : 'Produto atualizado.');
    redirect(url('produtos'));
}

function produtos_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM produtos WHERE id = :id', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Produto não encontrado.');
    }
    flash('Produto excluído.');
    redirect(url('produtos'));
}

// ---------------------------------------------------------------------
// Categorias
// ---------------------------------------------------------------------
function produtos_categorias(): void
{
    $cats = all('SELECT c.id, c.nome, (SELECT count(*) FROM produtos p WHERE p.categoria_id = c.id) AS produtos
                   FROM categorias c ORDER BY c.nome');
    layout_start('Categorias', 'produtos');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Categorias de produtos</h2>
            <a class="btn btn-sec" href="<?= e(url('produtos')) ?>">Voltar aos produtos</a>
        </div>
        <form method="post" action="<?= e(url('produtos', ['a' => 'categoria_salvar'])) ?>" class="filtros">
            <?= csrf_field() ?>
            <input type="text" name="nome" placeholder="Nova categoria" required maxlength="60" aria-label="Nova categoria">
            <button class="btn">Adicionar</button>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Nome</th><th class="num">Produtos</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($cats as $c): ?>
                    <tr>
                        <td>
                            <form method="post" action="<?= e(url('produtos', ['a' => 'categoria_salvar'])) ?>" class="filtros" style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                                <input type="text" name="nome" value="<?= e($c['nome']) ?>" required maxlength="60" aria-label="Nome da categoria">
                                <button class="btn btn-sec btn-peq">Renomear</button>
                            </form>
                        </td>
                        <td class="num"><?= num($c['produtos']) ?></td>
                        <td>
                            <?php if ((int) $c['produtos'] === 0): ?>
                                <form method="post" action="<?= e(url('produtos', ['a' => 'categoria_excluir'])) ?>" data-confirmar="Excluir a categoria <?= e($c['nome']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                                    <button class="btn btn-perigo btn-peq">Excluir</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$cats): ?>
                    <tr><td colspan="3" class="vazio">Nenhuma categoria.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}

function produtos_categoria_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Categoria inválida.');
    }
    categoria_salvar(post('nome'), $id === '' ? null : $id);
    flash($id === '' ? 'Categoria adicionada.' : 'Categoria renomeada.');
    redirect(url('produtos', ['a' => 'categorias']));
}

function produtos_categoria_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM categorias WHERE id = :id', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Categoria não encontrada.');
    }
    flash('Categoria excluída.');
    redirect(url('produtos', ['a' => 'categorias']));
}

<?php
/*
 * Produtos, categorias, kits e patrimônio (unidades).
 * Disponível hoje = estoque - alugado (na rua) - reservado para hoje - em manutenção.
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
        SELECT p.id, p.codigo, p.descricao, p.unidade, p.qtd_total, p.valor_diaria, p.valor_semanal, p.valor_mensal, p.ativo, c.nome AS categoria,
               COALESCE(a.qtd_alugada, 0) AS alugado, COALESCE(mn.qtd_manutencao, 0) AS manutencao,
               COALESCE((SELECT SUM(ri.quantidade)
                           FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id
                          WHERE r.status = 'ativa' AND ri.produto_id = p.id
                            AND CURRENT_DATE BETWEEN r.data_inicio AND r.data_fim), 0) AS reservado
          FROM produtos p
          LEFT JOIN categorias c ON c.id = p.categoria_id
          LEFT JOIN vw_produto_alugado a ON a.produto_id = p.id
          LEFT JOIN vw_produto_manutencao mn ON mn.produto_id = p.id
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
                <a class="btn btn-sec" href="<?= e(url('produtos', ['a' => 'kits'])) ?>">Kits</a>
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
                <tr><th>Código</th><th>Descrição</th><th>Categoria</th><th class="num">Preço</th>
                    <th class="num">Estoque</th><th class="num">Alugado</th><th class="num">Reservado hoje</th><th class="num">Manutenção</th><th class="num">Disponível</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($produtos as $p): $disp = $p['qtd_total'] - $p['alugado'] - $p['reservado'] - $p['manutencao']; ?>
                    <tr>
                        <td class="nowrap"><code><?= e($p['codigo']) ?></code></td>
                        <td><?= e($p['descricao']) ?> <?= $p['ativo'] ? '' : '<span class="selo">inativo</span>' ?></td>
                        <td><?= e($p['categoria'] ?? '—') ?></td>
                        <td class="num"><?= precos_html($p) ?></td>
                        <td class="num"><?= num($p['qtd_total']) ?> <span class="muted"><?= e($p['unidade']) ?></span></td>
                        <td class="num"><?= num($p['alugado']) ?></td>
                        <td class="num"><?= num($p['reservado']) ?></td>
                        <td class="num"><?= $p['manutencao'] > 0 ? '<a href="' . e(url('manutencoes')) . '">' . num($p['manutencao']) . '</a>' : '0' ?></td>
                        <td class="num"><?= selo_disponivel((int) $disp, (int) $p['qtd_total']) ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('produtos', ['a' => 'editar', 'id' => $p['id']])) ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$produtos): ?>
                    <tr><td colspan="10" class="vazio">Nenhum produto encontrado.</td></tr>
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
    $usado = $id && val('SELECT 1 FROM locacao_itens WHERE produto_id = :a UNION ALL SELECT 1 FROM reserva_itens WHERE produto_id = :b
                         UNION ALL SELECT 1 FROM kit_itens WHERE produto_id = :c LIMIT 1',
                        ['a' => $id, 'b' => $id, 'c' => $id]) !== null
             || ($id && val('SELECT 1 FROM unidades WHERE produto_id = :a UNION ALL SELECT 1 FROM manutencoes WHERE produto_id = :b LIMIT 1',
                            ['a' => $id, 'b' => $id]) !== null);
    $moeda = fn($campo) => has_old() ? old($campo) : (isset($p[$campo]) ? num($p[$campo], 2) : '');
    // Semanal/mensal zerados = não oferece: campo vazio
    $opcional = fn($campo) => has_old() ? old($campo) : ((float) ($p[$campo] ?? 0) > 0 ? num($p[$campo], 2) : '');

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
                <label>Valor da semana (R$)
                    <input type="text" name="valor_semanal" value="<?= e($opcional('valor_semanal')) ?>" inputmode="decimal" placeholder="não oferece">
                    <span class="ajuda">Preço de 7 dias. Vazio: só diárias.</span>
                </label>
                <label>Valor do mês (R$)
                    <input type="text" name="valor_mensal" value="<?= e($opcional('valor_mensal')) ?>" inputmode="decimal" placeholder="não oferece">
                    <span class="ajuda">Preço de 30 dias.</span>
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
    <?php if ($id) { produto_unidades_card($p); } ?>
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

// ---------------------------------------------------------------------
// Kits: pacote de produtos. Na locação/reserva o kit vira os itens dele.
// ---------------------------------------------------------------------
function produtos_kits(): void
{
    $kits = all("SELECT k.id, k.codigo, k.nome, k.ativo,
                        (SELECT string_agg(ki.quantidade || ' × ' || p.descricao, ', ' ORDER BY p.descricao)
                           FROM kit_itens ki JOIN produtos p ON p.id = ki.produto_id WHERE ki.kit_id = k.id) AS composicao
                   FROM kits k ORDER BY k.nome");
    layout_start('Kits', 'produtos');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Kits</h2>
            <div class="acoes">
                <a class="btn btn-sec" href="<?= e(url('produtos')) ?>">Voltar aos produtos</a>
                <a class="btn" href="<?= e(url('produtos', ['a' => 'kit_novo'])) ?>">+ Novo kit</a>
            </div>
        </div>
        <p class="ajuda">Um kit junta produtos que costumam sair juntos (ex.: andaime completo). Na locação e na reserva,
            <strong>Adicionar kit</strong> inclui todos os itens de uma vez; estoque e preço continuam sendo os de cada produto.</p>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Código</th><th>Nome</th><th>Composição</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($kits as $k): ?>
                    <tr>
                        <td class="nowrap"><code><?= e($k['codigo']) ?></code></td>
                        <td><?= e($k['nome']) ?> <?= $k['ativo'] ? '' : '<span class="selo">inativo</span>' ?></td>
                        <td><?= e($k['composicao'] ?? '') ?></td>
                        <td><a class="btn btn-sec btn-peq" href="<?= e(url('produtos', ['a' => 'kit_editar', 'id' => $k['id']])) ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$kits): ?>
                    <tr><td colspan="4" class="vazio">Nenhum kit cadastrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}

function produtos_kit_novo(): void
{
    kit_form([], [], 'Novo kit');
}

function produtos_kit_editar(): void
{
    $id = get('id');
    $k = is_uuid($id) ? one('SELECT * FROM kits WHERE id = :id', ['id' => $id]) : null;
    if ($k === null) {
        pagina_erro(404, 'Kit não encontrado.');
    }
    $itens = all('SELECT produto_id, quantidade FROM kit_itens WHERE kit_id = :id ORDER BY quantidade DESC', ['id' => $id]);
    kit_form($k, $itens, 'Editar kit');
}

/** Formulário do kit: linhas fixas (as atuais + 5 vazias), sem depender de JavaScript. */
function kit_form(array $k, array $itens, string $titulo): void
{
    $id = $k['id'] ?? '';
    $produtos = array_column(all("SELECT id, codigo || ' — ' || descricao || CASE WHEN ativo THEN '' ELSE ' (inativo)' END AS nome
                                    FROM produtos ORDER BY descricao"), 'nome', 'id');
    if (has_old()) {
        $itens = [];
        foreach ((array) old('produto_id', []) as $i => $pid) {
            $itens[] = ['produto_id' => (string) $pid, 'quantidade' => (string) (((array) old('quantidade', []))[$i] ?? '1')];
        }
    }
    $itens = array_values(array_filter($itens, fn($it) => $it['produto_id'] !== ''));
    for ($i = 0; $i < 5; $i++) {
        $itens[] = ['produto_id' => '', 'quantidade' => '1'];
    }

    layout_start($titulo, 'produtos');
    ?>
    <form method="post" action="<?= e(url('produtos', ['a' => 'kit_salvar'])) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <div class="card">
            <h2>Kit</h2>
            <div class="linha">
                <label class="obrig">Código
                    <input type="text" name="codigo" value="<?= e(valor_form($k, 'codigo')) ?>" required maxlength="30" style="text-transform:uppercase">
                </label>
                <label class="obrig">Nome
                    <input type="text" name="nome" value="<?= e(valor_form($k, 'nome')) ?>" required maxlength="150">
                </label>
            </div>
            <label class="check"><input type="checkbox" name="ativo" value="1" <?= marcado_form($k, 'ativo', true) ? 'checked' : '' ?>> Ativo (aparece na locação e na reserva)</label>
            <label>Observações
                <textarea name="obs" maxlength="2000"><?= e(valor_form($k, 'obs')) ?></textarea>
            </label>
        </div>
        <div class="card">
            <h2>Composição</h2>
            <p class="ajuda">Quantidade de cada produto em <strong>um</strong> kit. Linhas sem produto são ignoradas; salve para ganhar mais linhas.</p>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th style="min-width:260px">Produto</th><th class="num">Qtd. por kit</th></tr></thead>
                    <tbody>
                    <?php foreach ($itens as $it): ?>
                        <tr>
                            <td><select name="produto_id[]" aria-label="Produto"><?= options($produtos, $it['produto_id'], '— escolha —') ?></select></td>
                            <td class="num"><input type="number" name="quantidade[]" value="<?= e($it['quantidade']) ?>" min="1" step="1" style="width:90px" aria-label="Quantidade"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Salvar</button>
            <a class="btn btn-sec" href="<?= e(url('produtos', ['a' => 'kits'])) ?>">Cancelar</a>
        </div>
    </form>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url('produtos', ['a' => 'kit_excluir'])) ?>" data-confirmar="Excluir este kit? Os produtos e as locações não são afetados." style="margin-top:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <button class="btn btn-perigo btn-peq">Excluir kit</button>
        </form>
    <?php endif; ?>
    <?php
    layout_end();
}

function produtos_kit_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Kit inválido.');
    }
    kit_salvar($_POST, $id === '' ? null : $id);
    flash($id === '' ? 'Kit cadastrado.' : 'Kit atualizado.');
    redirect(url('produtos', ['a' => 'kits']));
}

function produtos_kit_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM kits WHERE id = :id', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Kit não encontrado.');
    }
    flash('Kit excluído.');
    redirect(url('produtos', ['a' => 'kits']));
}

// ---------------------------------------------------------------------
// Patrimônio: unidades do produto (número de patrimônio e série)
// ---------------------------------------------------------------------
const SELOS_UNIDADE = ['disponivel' => ['disponível', 'verde'], 'na_rua' => ['na rua', 'azul'],
                       'manutencao' => ['em manutenção', 'amarelo'], 'baixada' => ['baixada', '']];

/** Rótulo da situação da unidade, com link para a locação ou a manutenção. */
function unidade_selo(array $u): string
{
    [$rotulo, $cor] = SELOS_UNIDADE[$u['situacao']];
    $ref = match ($u['situacao']) {
        'na_rua'     => ' <a href="' . e(url('locacoes', ['a' => 'ver', 'n' => $u['locacao']])) . '">nº ' . num($u['locacao']) . '</a>',
        'manutencao' => ' <a href="' . e(url('manutencoes', ['a' => 'ver', 'id' => $u['manutencao_id']])) . '">nº ' . num($u['manutencao']) . '</a>',
        default      => '',
    };
    return '<span class="selo ' . $cor . '">' . e($rotulo) . '</span>' . $ref;
}

/** Unidades com a situação calculada (uma consulta). */
function unidades_com_situacao(string $where, array $params): array
{
    $lista = all("SELECT u.*, m.id AS manutencao_id, m.numero AS manutencao, l.numero AS locacao
                    FROM unidades u
                    LEFT JOIN manutencoes m ON m.unidade_id = u.id AND m.data_fim IS NULL
                    LEFT JOIN locacao_unidades lu ON lu.unidade_id = u.id AND lu.devolucao_id IS NULL
                    LEFT JOIN locacao_itens li ON li.id = lu.locacao_item_id
                    LEFT JOIN locacoes l ON l.id = li.locacao_id
                   WHERE $where ORDER BY u.ativa DESC, u.patrimonio", $params);
    foreach ($lista as &$u) {
        $u['situacao'] = !$u['ativa'] ? 'baixada' : ($u['manutencao'] !== null ? 'manutencao' : ($u['locacao'] !== null ? 'na_rua' : 'disponivel'));
    }
    return $lista;
}

function produto_unidades_card(array $p): void
{
    $unidades = unidades_com_situacao('u.produto_id = :id', ['id' => $p['id']]);
    $ativas = count(array_filter($unidades, fn($u) => $u['ativa']));
    $manut = qtd_manutencao($p['id']);
    ?>
    <div class="card" style="margin-top:14px">
        <div class="card-titulo">
            <h2>Patrimônio (unidades)</h2>
            <div class="acoes">
                <a class="btn btn-sec btn-peq" href="<?= e(url('manutencoes', ['a' => 'nova', 'produto' => $p['id']])) ?>">Enviar para manutenção</a>
                <?php if ($ativas < (int) $p['qtd_total']): ?>
                    <a class="btn btn-peq" href="<?= e(url('produtos', ['a' => 'unidade_nova', 'produto' => $p['id']])) ?>">+ Nova unidade</a>
                <?php endif; ?>
            </div>
        </div>
        <p class="ajuda">Opcional: número de patrimônio e série de cada peça, para saber qual unidade está com qual cliente
            e o histórico de locações e manutenções dela. <?= num($ativas) ?> de <?= num($p['qtd_total']) ?> peça(s) cadastrada(s)<?=
            $manut > 0 ? ' · <strong>' . num($manut) . ' em manutenção</strong>' : '' ?>.</p>
        <?php if ($unidades): ?>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Patrimônio</th><th>Série</th><th>Situação</th><th>Aquisição</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($unidades as $u): ?>
                        <tr><td><code><?= e($u['patrimonio']) ?></code></td><td><?= e($u['serie']) ?></td><td><?= unidade_selo($u) ?></td>
                            <td><?= $u['aquisicao_data'] ? e(dt($u['aquisicao_data'])) : '' ?></td>
                            <td><a class="btn btn-sec btn-peq" href="<?= e(url('produtos', ['a' => 'unidade', 'id' => $u['id']])) ?>">Abrir</a></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function produtos_unidade_nova(): void
{
    $pid = get('produto');
    $p = is_uuid($pid) ? one('SELECT id, codigo, descricao FROM produtos WHERE id = :id', ['id' => $pid]) : null;
    if ($p === null) {
        pagina_erro(404, 'Produto não encontrado.');
    }
    unidade_form(['produto_id' => $p['id']], $p, 'Nova unidade');
}

function unidade_da_url(): array
{
    $id = get('id');
    $u = is_uuid($id) ? (unidades_com_situacao('u.id = :id', ['id' => $id])[0] ?? null) : null;
    if ($u === null) {
        pagina_erro(404, 'Unidade não encontrada.');
    }
    return $u;
}

function produtos_unidade(): void
{
    $u = unidade_da_url();
    $p = one('SELECT id, codigo, descricao FROM produtos WHERE id = :id', ['id' => $u['produto_id']]);
    unidade_form($u, $p, 'Unidade ' . $u['patrimonio']);
}

function unidade_form(array $u, array $p, string $titulo): void
{
    $id = $u['id'] ?? '';
    $historico = $id ? all("SELECT 'locacao' AS tipo, l.numero, l.data_saida AS inicio, d.data AS fim, c.nome AS detalhe, NULL::uuid AS ref
                              FROM locacao_unidades lu JOIN locacao_itens li ON li.id = lu.locacao_item_id
                              JOIN locacoes l ON l.id = li.locacao_id JOIN clientes c ON c.id = l.cliente_id
                              LEFT JOIN devolucoes d ON d.id = lu.devolucao_id
                             WHERE lu.unidade_id = :a
                            UNION ALL
                            SELECT 'manutencao', m.numero, m.data_inicio, m.data_fim, m.descricao, m.id
                              FROM manutencoes m WHERE m.unidade_id = :b
                             ORDER BY inicio DESC", ['a' => $id, 'b' => $id]) : [];
    layout_start($titulo, 'produtos');
    ?>
    <p class="muted"><a href="<?= e(url('produtos', ['a' => 'editar', 'id' => $p['id']])) ?>">← <?= e($p['codigo'] . ' — ' . $p['descricao']) ?></a></p>
    <div class="grid-2">
        <form method="post" action="<?= e(url('produtos', ['a' => 'unidade_salvar'])) ?>" class="form card">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="produto_id" value="<?= e($p['id']) ?>">
            <div class="card-titulo"><h2><?= e($titulo) ?></h2><?= $id ? unidade_selo($u) : '' ?></div>
            <div class="linha">
                <label class="obrig">Nº de patrimônio
                    <input type="text" name="patrimonio" value="<?= e(valor_form($u, 'patrimonio')) ?>" required maxlength="30" style="text-transform:uppercase"></label>
                <label>Nº de série<input type="text" name="serie" value="<?= e(valor_form($u, 'serie')) ?>" maxlength="60"></label>
            </div>
            <div class="linha">
                <label>Data de aquisição<input type="date" name="aquisicao_data" value="<?= e(valor_form($u, 'aquisicao_data')) ?>" max="<?= e(today()) ?>"></label>
                <label>Valor de aquisição R$<input type="text" name="aquisicao_valor" inputmode="decimal" placeholder="0,00"
                       value="<?= e(has_old() ? old('aquisicao_valor') : ((float) ($u['aquisicao_valor'] ?? 0) > 0 ? num($u['aquisicao_valor'], 2) : '')) ?>"></label>
            </div>
            <label>Observações<textarea name="obs" maxlength="2000"><?= e(valor_form($u, 'obs')) ?></textarea></label>
            <?php if ($id && !$u['ativa']): ?>
                <p class="alerta">Baixada em <?= e(dt($u['baixa_data'])) ?>: <?= e($u['baixa_motivo']) ?></p>
            <?php endif; ?>
            <button class="btn">Salvar</button>
        </form>
        <?php if ($id && $u['situacao'] === 'disponivel'): ?>
            <div>
                <div class="card">
                    <h2>Manutenção</h2>
                    <a class="btn btn-sec" href="<?= e(url('manutencoes', ['a' => 'nova', 'unidade' => $id])) ?>">Enviar esta unidade para manutenção</a>
                </div>
                <form method="post" action="<?= e(url('produtos', ['a' => 'unidade_baixar'])) ?>" class="form card"
                      data-confirmar="Dar baixa nesta unidade? O estoque do produto diminui 1.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <h2>Baixa</h2>
                    <div class="linha">
                        <label class="obrig">Data<input type="date" name="baixa_data" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required></label>
                        <label class="obrig">Motivo<input type="text" name="baixa_motivo" maxlength="200" required placeholder="vendida, perdida, sucata..."></label>
                    </div>
                    <p class="ajuda">A unidade sai do patrimônio e o estoque do produto diminui 1. O histórico continua.</p>
                    <button class="btn btn-perigo">Dar baixa</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($id): ?>
        <div class="card">
            <h2>Histórico</h2>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>O quê</th><th>De</th><th>Até</th><th>Detalhe</th></tr></thead>
                    <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr><td><?= $h['tipo'] === 'locacao'
                                ? '<a href="' . e(url('locacoes', ['a' => 'ver', 'n' => $h['numero']])) . '">Locação nº ' . num($h['numero']) . '</a>'
                                : '<a href="' . e(url('manutencoes', ['a' => 'ver', 'id' => $h['ref']])) . '">Manutenção nº ' . num($h['numero']) . '</a>' ?></td>
                            <td><?= e(dt($h['inicio'])) ?></td><td><?= $h['fim'] ? e(dt($h['fim'])) : '<em>em andamento</em>' ?></td>
                            <td><?= e(mb_strimwidth((string) $h['detalhe'], 0, 80, '…')) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$historico): ?><tr><td colspan="4" class="vazio">Ainda sem locações ou manutenções.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$historico): ?>
                <form method="post" action="<?= e(url('produtos', ['a' => 'unidade_excluir'])) ?>" data-confirmar="Excluir esta unidade (cadastrada por engano)?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <button class="btn btn-perigo btn-peq">Excluir (cadastrada por engano)</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
    layout_end();
}

function produtos_unidade_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Unidade inválida.');
    }
    $uid = unidade_salvar($_POST, $id === '' ? null : $id);
    flash($id === '' ? 'Unidade cadastrada.' : 'Unidade atualizada.');
    redirect(url('produtos', ['a' => 'unidade', 'id' => $uid]));
}

function produtos_unidade_baixar(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Unidade não encontrada.');
    }
    unidade_baixar($id, post('baixa_data'), post('baixa_motivo'));
    flash('Baixa registrada. O estoque do produto diminuiu 1.');
    redirect(url('produtos', ['a' => 'unidade', 'id' => $id]));
}

function produtos_unidade_excluir(): void
{
    $id = post('id');
    $pid = is_uuid($id) ? val('SELECT produto_id FROM unidades WHERE id = :id', ['id' => $id]) : null;
    if ($pid === null) {
        throw new UserError('Unidade não encontrada.');
    }
    q('DELETE FROM unidades WHERE id = :id', ['id' => $id]);   // com histórico, a FK impede ("em uso")
    flash('Unidade excluída.');
    redirect(url('produtos', ['a' => 'editar', 'id' => $pid]));
}

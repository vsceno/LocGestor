<?php
/*
 * Clientes: lista com busca, cadastro, ficha com resumo e histórico.
 *
 * LGPD: a busca pode ser por CPF/CNPJ, então ela é enviada por POST e guardada na
 * sessão; a URL da lista nunca carrega documento nem telefone.
 */

declare(strict_types=1);

const SITUACOES_CLIENTE = ['ativos' => 'Ativos', 'todos' => 'Todos', 'bloqueados' => 'Bloqueados', 'inativos' => 'Inativos'];

function clientes_index(): void
{
    $f = $_SESSION['filtro_clientes'] ?? ['q' => '', 'situacao' => 'ativos'];

    $where = [];
    $params = [];
    $where[] = match ($f['situacao']) {
        'todos'      => 'TRUE',
        'bloqueados' => 'c.bloqueado',
        'inativos'   => 'NOT c.ativo',
        default      => 'c.ativo',
    };
    if ($f['q'] !== '') {
        $cond = 'lower(c.nome) LIKE lower(:nome)';
        $params['nome'] = like($f['q']);
        $dig = so_digitos($f['q']);
        if (strlen($dig) >= 3) {
            $cond .= " OR c.cpf_cnpj LIKE :doc
                       OR regexp_replace(c.telefone, '\\D', '', 'g') LIKE :tel
                       OR regexp_replace(c.celular, '\\D', '', 'g') LIKE :cel";
            $params += ['doc' => like($dig), 'tel' => like($dig), 'cel' => like($dig)];
        }
        $where[] = "($cond)";
    }
    $sqlWhere = implode(' AND ', $where);

    $pg = paginate("SELECT count(*) FROM clientes c WHERE $sqlWhere", $params);
    $clientes = all("SELECT c.id, c.tipo, c.nome, c.cpf_cnpj, c.telefone, c.celular, c.cidade, c.uf, c.bloqueado, c.ativo,
                            (SELECT count(*) FROM locacoes l WHERE l.cliente_id = c.id AND l.status = 'aberta') AS abertas
                       FROM clientes c
                      WHERE $sqlWhere
                      ORDER BY lower(c.nome)
                      LIMIT {$pg['por']} OFFSET {$pg['offset']}", $params);

    layout_start('Clientes', 'clientes');
    ?>
    <div class="card">
        <div class="card-titulo">
            <h2>Clientes</h2>
            <a class="btn" href="<?= e(url('clientes', ['a' => 'novo'])) ?>">+ Novo cliente</a>
        </div>
        <form method="post" action="<?= e(url('clientes', ['a' => 'filtrar'])) ?>" class="filtros">
            <?= csrf_field() ?>
            <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nome, CPF/CNPJ ou telefone" aria-label="Buscar">
            <select name="situacao" aria-label="Situação"><?= options(SITUACOES_CLIENTE, $f['situacao']) ?></select>
            <button class="btn btn-sec">Buscar</button>
            <?php if ($f['q'] !== '' || $f['situacao'] !== 'ativos'): ?>
                <button class="btn btn-sec" name="limpar" value="1">Limpar</button>
            <?php endif; ?>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead>
                <tr><th>Nome</th><th>CPF/CNPJ</th><th>Telefone</th><th>Cidade</th><th>Situação</th><th class="num">Abertas</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr>
                        <td><a href="<?= e(url('clientes', ['a' => 'ver', 'id' => $c['id']])) ?>"><strong><?= e($c['nome']) ?></strong></a>
                            <span class="muted"><?= e($c['tipo']) ?></span></td>
                        <td class="nowrap"><?= e(doc_formatar($c['cpf_cnpj'])) ?></td>
                        <td class="nowrap"><?= e($c['celular'] ?: $c['telefone']) ?></td>
                        <td><?= e($c['cidade'] . ($c['uf'] !== '' ? '/' . $c['uf'] : '')) ?></td>
                        <td><?= selos_cliente($c) ?></td>
                        <td class="num"><?= num($c['abertas']) ?></td>
                        <td class="nowrap"><a class="btn btn-sec btn-peq" href="<?= e(url('clientes', ['a' => 'editar', 'id' => $c['id']])) ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clientes): ?>
                    <tr><td colspan="7" class="vazio">Nenhum cliente encontrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'clientes') ?>
    </div>
    <?php
    layout_end();
}

function selos_cliente(array $c): string
{
    $s = '';
    if ($c['bloqueado']) {
        $s .= '<span class="selo vermelho">bloqueado</span> ';
    }
    $s .= $c['ativo'] ? '<span class="selo verde">ativo</span>' : '<span class="selo">inativo</span>';
    return $s;
}

function clientes_filtrar(): void
{
    $situacao = post('situacao', 'ativos');
    $_SESSION['filtro_clientes'] = post('limpar') !== ''
        ? ['q' => '', 'situacao' => 'ativos']
        : ['q' => mb_substr(post('q'), 0, 100), 'situacao' => isset(SITUACOES_CLIENTE[$situacao]) ? $situacao : 'ativos'];
    redirect(url('clientes'));
}

/** Busca o cliente pelo id da URL (validado) ou mostra "não encontrado". */
function cliente_da_url(): array
{
    $id = get('id');
    $c = is_uuid($id) ? one('SELECT * FROM clientes WHERE id = :id', ['id' => $id]) : null;
    if ($c === null) {
        pagina_erro(404, 'Cliente não encontrado.');
    }
    return $c;
}

function clientes_novo(): void
{
    cliente_form([], 'Novo cliente');
}

function clientes_editar(): void
{
    $c = cliente_da_url();
    cliente_form($c, 'Editar cliente');
}

function cliente_form(array $c, string $titulo): void
{
    $id = $c['id'] ?? '';
    $tipo = valor_form($c, 'tipo', 'PF');
    layout_start($titulo, 'clientes');
    ?>
    <form method="post" action="<?= e(url('clientes', ['a' => 'salvar'])) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <div class="card">
            <h2>Identificação</h2>
            <div class="linha">
                <label class="obrig">Tipo
                    <select name="tipo" id="tipo"><?= options(['PF' => 'Pessoa física', 'PJ' => 'Pessoa jurídica'], $tipo) ?></select>
                </label>
                <label id="rotulo-doc"><?= $tipo === 'PJ' ? 'CNPJ' : 'CPF' ?>
                    <input type="text" name="cpf_cnpj" value="<?= e(doc_formatar(valor_form($c, 'cpf_cnpj'))) ?>"
                           data-mascara="doc" inputmode="numeric" maxlength="18">
                </label>
                <label><?= $tipo === 'PJ' ? 'Inscrição estadual' : 'RG' ?>
                    <input type="text" name="rg_ie" value="<?= e(valor_form($c, 'rg_ie')) ?>" maxlength="20">
                </label>
            </div>
            <label class="obrig">Nome / razão social
                <input type="text" name="nome" value="<?= e(valor_form($c, 'nome')) ?>" required maxlength="150">
            </label>
        </div>
        <div class="card">
            <h2>Contato</h2>
            <div class="linha">
                <label>Telefone
                    <input type="tel" name="telefone" value="<?= e(valor_form($c, 'telefone')) ?>" data-mascara="telefone" maxlength="20">
                </label>
                <label>Celular / WhatsApp
                    <input type="tel" name="celular" value="<?= e(valor_form($c, 'celular')) ?>" data-mascara="telefone" maxlength="20">
                </label>
                <label>E-mail
                    <input type="email" name="email" value="<?= e(valor_form($c, 'email')) ?>" maxlength="120">
                </label>
            </div>
        </div>
        <div class="card">
            <h2>Endereço</h2>
            <div class="linha">
                <label>CEP
                    <input type="text" name="cep" value="<?= e(valor_form($c, 'cep')) ?>" data-mascara="cep" data-viacep
                           inputmode="numeric" maxlength="9">
                    <span class="ajuda" id="cep-status">O endereço é preenchido ao sair do campo.</span>
                </label>
                <label>Cidade
                    <input type="text" name="cidade" value="<?= e(valor_form($c, 'cidade')) ?>" maxlength="80">
                </label>
                <label>UF
                    <select name="uf"><?= options(UFS, valor_form($c, 'uf', 'CE'), '') ?></select>
                </label>
            </div>
            <div class="linha">
                <label>Endereço
                    <input type="text" name="endereco" value="<?= e(valor_form($c, 'endereco')) ?>" maxlength="150">
                </label>
                <label>Número
                    <input type="text" name="numero" value="<?= e(valor_form($c, 'numero')) ?>" maxlength="10">
                </label>
                <label>Bairro
                    <input type="text" name="bairro" value="<?= e(valor_form($c, 'bairro')) ?>" maxlength="80">
                </label>
            </div>
        </div>
        <div class="card">
            <h2>Situação</h2>
            <label class="check"><input type="checkbox" name="ativo" value="1" <?= marcado_form($c, 'ativo', true) ? 'checked' : '' ?>> Ativo</label>
            <label class="check"><input type="checkbox" name="bloqueado" value="1" <?= marcado_form($c, 'bloqueado') ? 'checked' : '' ?>>
                Bloqueado (não pode fazer novas locações)</label>
            <label>Observações
                <textarea name="obs" maxlength="2000"><?= e(valor_form($c, 'obs')) ?></textarea>
            </label>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Salvar</button>
            <a class="btn btn-sec" href="<?= e($id ? url('clientes', ['a' => 'ver', 'id' => $id]) : url('clientes')) ?>">Cancelar</a>
        </div>
    </form>
    <?php
    layout_end();
}

function clientes_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Cliente inválido.');
    }
    $novo = $id === '';
    $id = cliente_salvar($_POST, $novo ? null : $id);
    flash($novo ? 'Cliente cadastrado.' : 'Cliente atualizado.');
    redirect(url('clientes', ['a' => 'ver', 'id' => $id]));
}

function clientes_ver(): void
{
    $c = cliente_da_url();
    $r = cliente_resumo($c['id']);
    $locacoes = all("SELECT l.id, l.numero, l.data_saida, l.data_prev_devolucao, l.status,
                            v.valor_atual, v.valor_pago
                       FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                      WHERE l.cliente_id = :id
                      ORDER BY l.data_saida DESC, l.numero DESC
                      LIMIT 100", ['id' => $c['id']]);
    $endereco = trim(implode(', ', array_filter([$c['endereco'], $c['numero'], $c['bairro']])));
    $cidade = trim($c['cidade'] . ($c['uf'] !== '' ? '/' . $c['uf'] : ''));

    layout_start($c['nome'], 'clientes');
    ?>
    <div class="kpis">
        <div class="kpi"><span>Locações</span><strong><?= num($r['locacoes']) ?></strong><small><?= num($r['abertas']) ?> em aberto</small></div>
        <div class="kpi azul"><span>Total movimentado</span><strong><?= money($r['movimentado']) ?></strong><small>pago <?= money($r['pago']) ?></small></div>
        <div class="kpi <?= $r['saldo'] > 0 ? 'vermelho' : 'verde' ?>"><span>Saldo devedor</span><strong><?= money($r['saldo']) ?></strong></div>
    </div>
    <div class="grid-2">
        <div class="card">
            <div class="card-titulo">
                <h2>Dados do cliente</h2>
                <div><?= selos_cliente($c) ?></div>
            </div>
            <dl class="dados">
                <dt>Tipo</dt><dd><?= $c['tipo'] === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física' ?></dd>
                <dt><?= $c['tipo'] === 'PJ' ? 'CNPJ' : 'CPF' ?></dt><dd><?= e(doc_formatar($c['cpf_cnpj'])) ?: '—' ?></dd>
                <dt><?= $c['tipo'] === 'PJ' ? 'Insc. estadual' : 'RG' ?></dt><dd><?= e($c['rg_ie']) ?: '—' ?></dd>
                <dt>Telefone</dt><dd><?= e(trim($c['telefone'] . '  ' . $c['celular'])) ?: '—' ?></dd>
                <dt>E-mail</dt><dd><?= e($c['email']) ?: '—' ?></dd>
                <dt>Endereço</dt><dd><?= e($endereco) ?: '—' ?><?= $cidade !== '' ? '<br>' . e($cidade) : '' ?><?= $c['cep'] !== '' ? ' · CEP ' . e($c['cep']) : '' ?></dd>
                <?php if ($c['obs'] !== ''): ?><dt>Observações</dt><dd><?= nl2br(e($c['obs'])) ?></dd><?php endif; ?>
                <dt>Cadastrado em</dt><dd><?= e(dt($c['criado_em'])) ?></dd>
            </dl>
        </div>
        <div class="card">
            <h2>Ações</h2>
            <div class="acoes">
                <a class="btn" href="<?= e(url('locacoes', ['a' => 'nova', 'cliente' => $c['id']])) ?>">+ Nova locação</a>
                <a class="btn btn-sec" href="<?= e(url('clientes', ['a' => 'editar', 'id' => $c['id']])) ?>">Editar</a>
                <form method="post" action="<?= e(url('clientes', ['a' => 'bloquear'])) ?>"
                      data-confirmar="<?= $c['bloqueado'] ? 'Desbloquear este cliente?' : 'Bloquear este cliente? Ele não poderá fazer novas locações.' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                    <button class="btn btn-sec"><?= $c['bloqueado'] ? 'Desbloquear' : 'Bloquear' ?></button>
                </form>
                <?php if ((int) $r['locacoes'] === 0): ?>
                    <form method="post" action="<?= e(url('clientes', ['a' => 'excluir'])) ?>" data-confirmar="Excluir este cliente definitivamente?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                        <button class="btn btn-perigo">Excluir</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card">
        <h2>Histórico de locações</h2>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Nº</th><th>Saída</th><th>Devolução prevista</th><th>Situação</th><th class="num">Valor</th><th class="num">Pago</th><th class="num">Saldo</th></tr></thead>
                <tbody>
                <?php foreach ($locacoes as $l): $saldo = $l['status'] === 'cancelada' ? 0 : $l['valor_atual'] - $l['valor_pago']; ?>
                    <tr>
                        <td><a href="<?= e(url('locacoes', ['a' => 'ver', 'n' => $l['numero']])) ?>"><?= e($l['numero']) ?></a></td>
                        <td><?= e(dt($l['data_saida'])) ?></td>
                        <td><?= e(dt($l['data_prev_devolucao'])) ?></td>
                        <td><?= selo_status_locacao($l) ?></td>
                        <td class="num"><?= money($l['valor_atual']) ?></td>
                        <td class="num"><?= money($l['valor_pago']) ?></td>
                        <td class="num<?= $saldo > 0.004 ? ' alerta' : '' ?>"><?= money($saldo) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$locacoes): ?>
                    <tr><td colspan="7" class="vazio">Nenhuma locação ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}

function clientes_bloquear(): void
{
    $id = post('id');
    $bloq = is_uuid($id) ? val('UPDATE clientes SET bloqueado = NOT bloqueado WHERE id = :id RETURNING bloqueado', ['id' => $id]) : null;
    if ($bloq === null) {
        throw new UserError('Cliente não encontrado.');
    }
    flash($bloq ? 'Cliente bloqueado: não poderá fazer novas locações.' : 'Cliente desbloqueado.');
    redirect(url('clientes', ['a' => 'ver', 'id' => $id]));
}

function clientes_excluir(): void
{
    $id = post('id');
    if (!is_uuid($id) || q('DELETE FROM clientes WHERE id = :id', ['id' => $id])->rowCount() === 0) {
        throw new UserError('Cliente não encontrado.');
    }
    flash('Cliente excluído.');
    redirect(url('clientes'));
}

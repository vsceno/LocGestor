<?php
/*
 * Configurações: dados da empresa e textos do contrato/fatura (admin), usuários (admin)
 * e troca da própria senha (todos).
 */

declare(strict_types=1);

function config_abas(string $ativa): void
{
    $abas = is_admin()
        ? ['index' => 'Empresa e documentos', 'usuarios' => 'Usuários', 'senha' => 'Minha senha']
        : ['senha' => 'Minha senha'];
    echo '<nav class="abas">';
    foreach ($abas as $a => $rot) {
        echo '<a href="' . e(url('config', $a === 'index' ? [] : ['a' => $a])) . '"' . ($a === $ativa ? ' class="ativa"' : '') . '>' . e($rot) . '</a>';
    }
    echo '</nav>';
}

function config_index(): void
{
    if (!is_admin()) {
        redirect(url('config', ['a' => 'senha']));
    }
    $emp = empresa();
    $v = fn($c) => valor_form($emp, $c);
    layout_start('Configurações', 'config');
    config_abas('index');
    ?>
    <form method="post" action="<?= e(url('config', ['a' => 'empresa_salvar'])) ?>" class="form">
        <?= csrf_field() ?>
        <div class="card">
            <h2>Dados da locadora</h2>
            <p class="muted">Aparecem no topo do menu, no contrato e na fatura.</p>
            <div class="linha">
                <label class="obrig">Razão social<input type="text" name="razao_social" value="<?= e($v('razao_social')) ?>" required maxlength="150"></label>
                <label>Nome fantasia<input type="text" name="nome_fantasia" value="<?= e($v('nome_fantasia')) ?>" maxlength="150"></label>
            </div>
            <div class="linha">
                <label>CNPJ<input type="text" name="cnpj" value="<?= e(doc_formatar($v('cnpj'))) ?>" data-mascara="doc" inputmode="numeric" maxlength="18"></label>
                <label>Inscrição estadual<input type="text" name="ie" value="<?= e($v('ie')) ?>" maxlength="20"></label>
                <label>Telefone<input type="tel" name="telefone" value="<?= e($v('telefone')) ?>" data-mascara="telefone" maxlength="20"></label>
                <label>E-mail<input type="email" name="email" value="<?= e($v('email')) ?>" maxlength="120"></label>
            </div>
            <div class="linha">
                <label>Endereço<input type="text" name="endereco" value="<?= e($v('endereco')) ?>" maxlength="200"></label>
                <label>Cidade<input type="text" name="cidade" value="<?= e($v('cidade')) ?>" maxlength="80"></label>
                <label>UF<select name="uf"><?= options(UFS, $v('uf'), '') ?></select></label>
                <label>CEP<input type="text" name="cep" value="<?= e($v('cep')) ?>" data-mascara="cep" inputmode="numeric" maxlength="9"></label>
            </div>
        </div>
        <div class="card">
            <h2>Contrato de locação</h2>
            <label class="obrig">Cláusulas<textarea name="termos_contrato" rows="14" required><?= e($v('termos_contrato')) ?></textarea></label>
        </div>
        <div class="card">
            <h2>Fatura de locação</h2>
            <label>Observação fiscal impressa na fatura<textarea name="obs_fatura" rows="3"><?= e($v('obs_fatura')) ?></textarea>
                <span class="ajuda">Confirme o texto com o seu contador. Esta versão não emite NF-e.</span></label>
        </div>
        <button class="btn">Salvar</button>
    </form>
    <?php
    layout_end();
}

function config_empresa_salvar(): void
{
    empresa_salvar($_POST);
    flash('Dados da locadora salvos.');
    redirect(url('config'));
}

function config_usuarios(): void
{
    require_admin();
    $usuarios = all('SELECT id, nome, login, perfil, ativo, ultimo_acesso FROM usuarios ORDER BY ativo DESC, lower(nome)');
    $ativos = count(array_filter($usuarios, fn($u) => $u['ativo']));
    $limite = (int) tenant()['limite_usuarios'];
    $editar = null;
    if (is_uuid(get('id'))) {
        $editar = one('SELECT id, nome, login, perfil, ativo FROM usuarios WHERE id = :id', ['id' => get('id')]);
    }
    $u = $editar ?? [];

    layout_start('Configurações', 'config');
    config_abas('usuarios');
    ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-titulo"><h2>Usuários</h2><span class="selo <?= $ativos >= $limite ? 'amarelo' : 'verde' ?>"><?= num($ativos) ?> de <?= num($limite) ?> ativos (plano <?= e(tenant()['plano']) ?>)</span></div>
            <div class="tabela-wrap">
                <table class="tabela">
                    <thead><tr><th>Nome</th><th>Login</th><th>Perfil</th><th>Situação</th><th>Último acesso</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($usuarios as $x): ?>
                        <tr><td><?= e($x['nome']) ?><?= $x['id'] === uid() ? ' <span class="muted">(você)</span>' : '' ?></td>
                            <td><code><?= e($x['login']) ?></code></td>
                            <td><?= $x['perfil'] === 'admin' ? 'Administrador' : 'Operador' ?></td>
                            <td><?= $x['ativo'] ? '<span class="selo verde">ativo</span>' : '<span class="selo">inativo</span>' ?></td>
                            <td><?= $x['ultimo_acesso'] ? e(dt($x['ultimo_acesso'], true)) : '<span class="muted">nunca</span>' ?></td>
                            <td><a class="btn btn-sec btn-peq" href="<?= e(url('config', ['a' => 'usuarios', 'id' => $x['id']])) ?>">Editar</a></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <h2><?= $editar ? 'Editar ' . e($editar['nome']) : 'Novo usuário' ?></h2>
            <form method="post" action="<?= e(url('config', ['a' => 'usuario_salvar'])) ?>" class="form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($u['id'] ?? '') ?>">
                <label class="obrig">Nome<input type="text" name="nome" value="<?= e(valor_form($u, 'nome')) ?>" required maxlength="100"></label>
                <label class="obrig">Login<input type="text" name="login" value="<?= e(valor_form($u, 'login')) ?>" required maxlength="40"
                       pattern="[a-z0-9._\-]{3,40}" autocapitalize="none"></label>
                <label class="obrig">Perfil<select name="perfil"><?= options(['operador' => 'Operador', 'admin' => 'Administrador'], valor_form($u, 'perfil', 'operador')) ?></select>
                    <span class="ajuda">O administrador também altera a empresa, os usuários e exclui lançamentos do caixa.</span></label>
                <label<?= $editar ? '' : ' class="obrig"' ?>><?= $editar ? 'Nova senha (deixe em branco para manter)' : 'Senha' ?>
                    <input type="password" name="senha" minlength="8" autocomplete="new-password" <?= $editar ? '' : 'required' ?>></label>
                <label class="check"><input type="checkbox" name="ativo" value="1" <?= marcado_form($u, 'ativo', true) ? 'checked' : '' ?>> Ativo</label>
                <div class="acoes">
                    <button class="btn">Salvar</button>
                    <?php if ($editar): ?><a class="btn btn-sec" href="<?= e(url('config', ['a' => 'usuarios'])) ?>">Novo usuário</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php
    layout_end();
}

function config_usuario_salvar(): void
{
    $id = post('id');
    if ($id !== '' && !is_uuid($id)) {
        throw new UserError('Usuário inválido.');
    }
    usuario_salvar($_POST, $id === '' ? null : $id);
    flash($id === '' ? 'Usuário criado.' : 'Usuário atualizado.');
    redirect(url('config', ['a' => 'usuarios']));
}

function config_senha(): void
{
    layout_start('Configurações', 'config');
    config_abas('senha');
    ?>
    <div class="card" style="max-width:480px">
        <h2>Trocar minha senha</h2>
        <form method="post" action="<?= e(url('config', ['a' => 'senha_salvar'])) ?>" class="form">
            <?= csrf_field() ?>
            <label class="obrig">Senha atual<input type="password" name="senha_atual" required autocomplete="current-password"></label>
            <label class="obrig">Nova senha<input type="password" name="senha_nova" required minlength="8" autocomplete="new-password">
                <span class="ajuda">Mínimo de 8 caracteres.</span></label>
            <label class="obrig">Confirme a nova senha<input type="password" name="senha_confirma" required minlength="8" autocomplete="new-password"></label>
            <button class="btn">Trocar senha</button>
        </form>
    </div>
    <?php
    layout_end();
}

function config_senha_salvar(): void
{
    senha_trocar($_POST['senha_atual'] ?? '', $_POST['senha_nova'] ?? '', $_POST['senha_confirma'] ?? '');
    flash('Senha alterada.');
    redirect(url('config', ['a' => 'senha']));
}

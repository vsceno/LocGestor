<?php
/*
 * Configurações: dados da empresa e textos do contrato/fatura (admin), usuários (admin)
 * e troca da própria senha (todos).
 */

declare(strict_types=1);

function config_abas(string $ativa): void
{
    $abas = is_admin()
        ? ['index' => 'Empresa e documentos', 'usuarios' => 'Usuários', 'auditoria' => 'Auditoria', 'senha' => 'Minha senha']
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
        <div class="card">
            <h2>PIX para cobrança</h2>
            <p class="muted">Com a chave preenchida, a locação e a fatura mostram um QR Code e o "PIX copia e cola" com o
                valor do saldo, e a cobrança pelo WhatsApp já leva o código. O pagamento cai direto na conta da locadora;
                registre o recebimento na locação quando o dinheiro entrar.</p>
            <div class="linha">
                <label>Chave PIX<input type="text" name="pix_chave" value="<?= e($v('pix_chave')) ?>" maxlength="77" autocomplete="off"
                       placeholder="CPF, CNPJ, e-mail, +55 celular ou chave aleatória">
                    <span class="ajuda">Deixe vazia para não oferecer PIX.</span></label>
                <label>Nome do recebedor<input type="text" name="pix_nome" value="<?= e($v('pix_nome')) ?>" maxlength="25">
                    <span class="ajuda">Até 25 letras, sem acentos. Vazio: usa o nome da empresa.</span></label>
                <label>Cidade do recebedor<input type="text" name="pix_cidade" value="<?= e($v('pix_cidade')) ?>" maxlength="15">
                    <span class="ajuda">Até 15 letras. Vazio: usa a cidade da empresa.</span></label>
            </div>
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
    if (!empty(user()['trocar_senha'])) {
        echo '<div class="flash flash-aviso">Você entrou com uma senha temporária. Por segurança, defina uma senha nova para continuar.</div>';
    }
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

// ---------------------------------------------------------------------
// Auditoria: quem incluiu, alterou ou excluiu o quê (gravada por gatilho no banco)
// ---------------------------------------------------------------------
const AUDITORIA_TABELAS = [
    'locacoes' => 'Locação', 'devolucoes' => 'Devolução', 'reservas' => 'Reserva', 'clientes' => 'Cliente',
    'produtos' => 'Produto', 'kits' => 'Kit', 'categorias' => 'Categoria', 'unidades' => 'Unidade (patrimônio)',
    'manutencoes' => 'Manutenção', 'caixa' => 'Caixa', 'usuarios' => 'Usuário', 'empresa' => 'Empresa',
];
const AUDITORIA_ACOES = ['I' => 'incluiu', 'U' => 'alterou', 'D' => 'excluiu'];

/** Valor de um campo para a tela (texto curto). */
function auditoria_valor($v): string
{
    return match (true) {
        $v === null  => '—',
        $v === true  => 'sim',
        $v === false => 'não',
        $v === '***' => '(oculto)',
        is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
        default      => mb_strimwidth((string) $v, 0, 60, '…'),
    };
}

/** "Locação nº 12", "Cliente Maria..." com link para o registro, quando ele tem tela e ainda existe. */
function auditoria_link(array $a): string
{
    $numerado = in_array($a['tabela'], ['locacoes', 'reservas', 'manutencoes'], true);
    $texto = (AUDITORIA_TABELAS[$a['tabela']] ?? $a['tabela']) . ($a['rotulo'] !== '' ? ($numerado ? ' nº ' : ' ') . $a['rotulo'] : '');
    if ($a['acao'] === 'D' || $a['registro_id'] === null) {
        return e($texto);
    }
    $url = match ($a['tabela']) {
        'locacoes'    => url('locacoes', ['a' => 'ver', 'n' => $a['rotulo']]),
        'clientes'    => url('clientes', ['a' => 'ver', 'id' => $a['registro_id']]),
        'produtos'    => url('produtos', ['a' => 'editar', 'id' => $a['registro_id']]),
        'unidades'    => url('produtos', ['a' => 'unidade', 'id' => $a['registro_id']]),
        'manutencoes' => url('manutencoes', ['a' => 'ver', 'id' => $a['registro_id']]),
        'kits'        => url('produtos', ['a' => 'kit_editar', 'id' => $a['registro_id']]),
        default       => null,
    };
    return $url === null ? e($texto) : '<a href="' . e($url) . '">' . e($texto) . '</a>';
}

function config_auditoria(): void
{
    require_admin();
    $de = valid_date(get('de')) ? get('de') : date('Y-m-d', strtotime('-30 days'));
    $ate = valid_date(get('ate')) ? get('ate') : today();
    $tabela = isset(AUDITORIA_TABELAS[get('tabela')]) ? get('tabela') : '';
    $acao = isset(AUDITORIA_ACOES[get('acao')]) ? get('acao') : '';
    $usuario = mb_substr(get('usuario'), 0, 80);
    $registro = is_uuid(get('registro')) ? get('registro') : '';

    $where = ['a.quando >= CAST(:de AS date)', "a.quando < CAST(:ate AS date) + INTERVAL '1 day'"];
    $params = ['de' => $de, 'ate' => $ate];
    foreach (['tabela' => $tabela, 'acao' => $acao, 'usuario' => $usuario, 'registro_id' => $registro] as $col => $v) {
        if ($v !== '') {
            $where[] = "a.$col = :$col";
            $params[$col] = $v;
        }
    }
    $sqlWhere = implode(' AND ', $where);
    $pg = paginate("SELECT count(*) FROM auditoria a WHERE $sqlWhere", $params, 50);
    $linhas = all("SELECT a.* FROM auditoria a WHERE $sqlWhere ORDER BY a.quando DESC, a.id DESC
                   LIMIT {$pg['por']} OFFSET {$pg['offset']}", $params);
    $usuarios = array_column(all('SELECT DISTINCT usuario FROM auditoria ORDER BY usuario'), 'usuario');
    $cores = ['I' => 'verde', 'U' => 'azul', 'D' => 'vermelho'];

    layout_start('Configurações', 'config');
    config_abas('auditoria');
    ?>
    <div class="card">
        <div class="card-titulo"><h2>Auditoria</h2><span class="muted"><?= num($pg['total']) ?> registro(s)</span></div>
        <p class="ajuda">Toda inclusão, alteração e exclusão em locações, clientes, produtos, caixa, usuários e demais cadastros,
            com quem fez e quando. O histórico não pode ser alterado nem apagado pelo sistema. Dados pessoais e senhas
            aparecem como <em>(oculto)</em>: o registro mostra que mudaram, não os valores.</p>
        <form method="get" class="filtros">
            <input type="hidden" name="p" value="config"><input type="hidden" name="a" value="auditoria">
            <?php if ($registro !== ''): ?><input type="hidden" name="registro" value="<?= e($registro) ?>"><?php endif; ?>
            <input type="date" name="de" value="<?= e($de) ?>" aria-label="De">
            <input type="date" name="ate" value="<?= e($ate) ?>" aria-label="Até">
            <select name="tabela" aria-label="O quê"><?= options(AUDITORIA_TABELAS, $tabela, 'Tudo') ?></select>
            <select name="acao" aria-label="Ação"><?= options(AUDITORIA_ACOES, $acao, 'Todas as ações') ?></select>
            <select name="usuario" aria-label="Usuário"><?= options($usuarios, $usuario, 'Todos os usuários') ?></select>
            <button class="btn btn-sec">Filtrar</button>
            <?php if ($registro !== ''): ?><a href="<?= e(url('config', ['a' => 'auditoria'])) ?>">ver tudo</a><?php endif; ?>
        </form>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Quando</th><th>Usuário</th><th>Ação</th><th>O quê</th><th>Detalhes</th></tr></thead>
                <tbody>
                <?php foreach ($linhas as $a): $dados = json_decode($a['dados'], true) ?: []; ?>
                    <tr>
                        <td class="nowrap"><?= e(dt($a['quando'], true)) ?></td>
                        <td><?= e($a['usuario']) ?></td>
                        <td><span class="selo <?= $cores[$a['acao']] ?>"><?= e(AUDITORIA_ACOES[$a['acao']]) ?></span></td>
                        <td><?= auditoria_link($a) ?>
                            <?php if ($a['registro_id']): ?>
                                <a class="muted" title="Todo o histórico deste registro"
                                   href="<?= e(url('config', ['a' => 'auditoria', 'registro' => $a['registro_id'], 'de' => '2000-01-01'])) ?>">histórico</a>
                            <?php endif; ?></td>
                        <td class="auditoria-dados">
                            <?php if ($a['acao'] === 'U'): ?>
                                <?php foreach ($dados as $campo => [$antes, $depois]): ?>
                                    <div><strong><?= e(str_replace('_', ' ', $campo)) ?>:</strong>
                                        <?= $antes === '***' ? '(oculto, alterado)' : e(auditoria_valor($antes)) . ' → ' . e(auditoria_valor($depois)) ?></div>
                                <?php endforeach; ?>
                            <?php else: // inclusão/exclusão: só os campos preenchidos
                                $preenchidos = array_filter($dados, fn($v, $k) => $k !== 'id' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH); ?>
                                <?= e(implode(' · ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ': ' . auditoria_valor($v),
                                    array_keys($preenchidos), array_values($preenchidos)))) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$linhas): ?><tr><td colspan="5" class="vazio">Nada registrado no período.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($pg, 'config', array_filter(['a' => 'auditoria', 'de' => $de, 'ate' => $ate, 'tabela' => $tabela, 'acao' => $acao,
                                                'usuario' => $usuario, 'registro' => $registro])) ?>
    </div>
    <?php
    layout_end();
}

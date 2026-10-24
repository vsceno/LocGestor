<?php
/*
 * Painel da plataforma: locadoras (tenants).
 *
 * O papel locgestor_plataforma acessa a tabela tenants livremente, mas nas tabelas
 * das locadoras vale o mesmo RLS do app: para contar clientes ou criar o admin de
 * uma locadora é preciso definir o contexto dela, uma de cada vez.
 */

declare(strict_types=1);

const PLANOS = ['Básico', 'Profissional', 'Empresarial'];

function tenants_index(): void
{
    $tenants = all('SELECT id, slug, nome, cnpj, email_contato, telefone, status, plano, limite_usuarios, criado_em
                      FROM tenants ORDER BY nome');

    // Números de cada locadora: o RLS só mostra os dados da locadora do contexto,
    // então o contexto é trocado a cada uma e limpo no final, aconteça o que acontecer.
    try {
        foreach ($tenants as &$t) {
            tenant_definir($t['id']);
            $t += one("SELECT (SELECT count(*) FROM usuarios WHERE ativo)               AS usuarios,
                              (SELECT count(*) FROM clientes)                           AS clientes,
                              (SELECT count(*) FROM locacoes WHERE status = 'aberta')   AS abertas,
                              (SELECT max(ultimo_acesso) FROM usuarios)                 AS ultimo_acesso");
        }
        unset($t);
    } finally {
        tenant_limpar();
    }

    $ativas = count(array_filter($tenants, fn($t) => $t['status'] === 'ativa'));

    layout_start('Locadoras', 'tenants');
    ?>
    <div class="kpis">
        <div class="kpi"><span>Locadoras</span><strong><?= num(count($tenants)) ?></strong></div>
        <div class="kpi verde"><span>Ativas</span><strong><?= num($ativas) ?></strong></div>
        <div class="kpi vermelho"><span>Suspensas</span><strong><?= num(count($tenants) - $ativas) ?></strong></div>
    </div>
    <div class="card">
        <div class="card-titulo">
            <h2>Locadoras cadastradas</h2>
            <a class="btn" href="<?= e(url('tenants', ['a' => 'nova'])) ?>">+ Nova locadora</a>
        </div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead>
                <tr>
                    <th>Locadora</th>
                    <th>Status</th>
                    <th>Plano</th>
                    <th class="num">Usuários</th>
                    <th class="num">Clientes</th>
                    <th class="num">Loc. abertas</th>
                    <th>Último acesso</th>
                    <th>Criada em</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tenants as $t): ?>
                    <tr>
                        <td>
                            <strong><?= e($t['nome']) ?></strong><br>
                            <code><?= e($t['slug']) ?></code>
                            <?php if ($t['email_contato'] !== ''): ?><span class="muted"> · <?= e($t['email_contato']) ?></span><?php endif; ?>
                        </td>
                        <td><span class="selo <?= $t['status'] === 'ativa' ? 'verde' : 'vermelho' ?>"><?= e($t['status']) ?></span></td>
                        <td><?= e($t['plano']) ?></td>
                        <td class="num"><?= num($t['usuarios']) ?> / <?= num($t['limite_usuarios']) ?></td>
                        <td class="num"><?= num($t['clientes']) ?></td>
                        <td class="num"><?= num($t['abertas']) ?></td>
                        <td class="nowrap"><?= $t['ultimo_acesso'] ? e(dt($t['ultimo_acesso'], true)) : '<span class="muted">nunca</span>' ?></td>
                        <td class="nowrap"><?= e(dt($t['criado_em'])) ?></td>
                        <td>
                            <form method="post" action="<?= e(url('tenants', ['a' => 'status'])) ?>"
                                  data-confirmar="<?= e($t['status'] === 'ativa'
                                      ? "Suspender {$t['nome']}? Os usuários dela perdem o acesso imediatamente."
                                      : "Reativar {$t['nome']}?") ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                                <?php if ($t['status'] === 'ativa'): ?>
                                    <input type="hidden" name="status" value="suspensa">
                                    <button class="btn btn-peq btn-perigo">Suspender</button>
                                <?php else: ?>
                                    <input type="hidden" name="status" value="ativa">
                                    <button class="btn btn-peq">Reativar</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tenants): ?>
                    <tr><td colspan="9" class="vazio">Nenhuma locadora cadastrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}

function tenants_nova(): void
{
    layout_start('Nova locadora', 'tenants');
    ?>
    <form method="post" action="<?= e(url('tenants', ['a' => 'criar'])) ?>" class="form">
        <?= csrf_field() ?>
        <div class="card">
            <h2>Dados da locadora</h2>
            <div class="linha">
                <label class="obrig">Nome
                    <input type="text" name="nome" id="nome" value="<?= e(old('nome')) ?>" required maxlength="150">
                </label>
                <label class="obrig">Identificador de acesso (empresa)
                    <input type="text" name="slug" id="slug" value="<?= e(old('slug')) ?>" required
                           pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]" maxlength="40" autocapitalize="none">
                    <span class="ajuda">Só letras minúsculas, números e hífen (3 a 40). É o que a locadora digita no campo "Empresa" do login.</span>
                </label>
            </div>
            <div class="linha">
                <label>CNPJ
                    <input type="text" name="cnpj" value="<?= e(old('cnpj')) ?>" maxlength="18" inputmode="numeric">
                </label>
                <label>E-mail de contato
                    <input type="email" name="email_contato" value="<?= e(old('email_contato')) ?>" maxlength="120">
                </label>
                <label>Telefone
                    <input type="text" name="telefone" value="<?= e(old('telefone')) ?>" maxlength="20">
                </label>
            </div>
            <div class="linha">
                <label class="obrig">Plano
                    <input type="text" name="plano" value="<?= e(old('plano', 'Básico')) ?>" list="planos" required maxlength="40">
                    <datalist id="planos"><?= options(PLANOS) ?></datalist>
                </label>
                <label class="obrig">Limite de usuários
                    <input type="number" name="limite_usuarios" value="<?= e(old('limite_usuarios', '3')) ?>" min="1" max="1000" required>
                </label>
            </div>
        </div>
        <div class="card">
            <h2>Administrador da locadora</h2>
            <p class="muted">Será criado com uma senha temporária, mostrada uma única vez depois de salvar.</p>
            <div class="linha">
                <label class="obrig">Nome
                    <input type="text" name="admin_nome" value="<?= e(old('admin_nome', 'Administrador')) ?>" required maxlength="100">
                </label>
                <label class="obrig">Usuário (login)
                    <input type="text" name="admin_login" value="<?= e(old('admin_login', 'admin')) ?>" required
                           pattern="[a-z0-9._\-]{3,40}" maxlength="40" autocapitalize="none">
                </label>
            </div>
        </div>
        <div class="acoes">
            <button type="submit" class="btn">Criar locadora</button>
            <a class="btn btn-sec" href="<?= e(url('tenants')) ?>">Cancelar</a>
        </div>
    </form>
    <script>
    // Sugere o identificador a partir do nome enquanto o usuário não editar o campo
    (function () {
        var nome = document.getElementById('nome'), slug = document.getElementById('slug');
        var editado = slug.value !== '';
        slug.addEventListener('input', function () { editado = true; });
        nome.addEventListener('input', function () {
            if (editado) return;
            slug.value = nome.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '');
        });
    })();
    </script>
    <?php
    layout_end();
}

/** Provisionamento: cria a locadora, os dados padrão e o admin, tudo numa transação. */
function tenants_criar(): void
{
    $nome = post('nome');
    $slug = strtolower(post('slug'));
    $cnpj = so_digitos(post('cnpj'));
    $email = post('email_contato');
    $telefone = post('telefone');
    $plano = post('plano', 'Básico');
    $limite = filter_var(post('limite_usuarios'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    $adminNome = post('admin_nome', 'Administrador');
    $adminLogin = strtolower(post('admin_login', 'admin'));

    $erros = [];
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
        $erros[] = 'Informe o nome da locadora (3 a 150 caracteres).';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug)) {
        $erros[] = 'Identificador inválido: use de 3 a 40 letras minúsculas, números e hífen (sem hífen no início ou no fim).';
    }
    if ($cnpj !== '' && (strlen($cnpj) !== 14 || !doc_valido($cnpj))) {
        $erros[] = 'CNPJ inválido.';
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $erros[] = 'E-mail de contato inválido.';
    }
    if (mb_strlen($telefone) > 20 || $plano === '' || mb_strlen($plano) > 40) {
        $erros[] = 'Confira o telefone e o plano.';
    }
    if ($limite === false) {
        $erros[] = 'O limite de usuários deve ser um número entre 1 e 1000.';
    }
    if ($adminNome === '' || mb_strlen($adminNome) > 100) {
        $erros[] = 'Informe o nome do administrador.';
    }
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $adminLogin)) {
        $erros[] = 'Login do administrador inválido (3 a 40 letras minúsculas, números, ponto, hífen ou sublinhado).';
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }

    $senhaTemp = senha_temporaria();

    tx(function () use ($nome, $slug, $cnpj, $email, $telefone, $plano, $limite, $adminNome, $adminLogin, $senhaTemp) {
        $id = val('INSERT INTO tenants (slug, nome, cnpj, email_contato, telefone, plano, limite_usuarios)
                   VALUES (:slug, :nome, :cnpj, :email, :tel, :plano, :limite) RETURNING id',
                  ['slug' => $slug, 'nome' => $nome, 'cnpj' => $cnpj, 'email' => $email, 'tel' => $telefone,
                   'plano' => $plano, 'limite' => $limite]);

        // Contexto da nova locadora só até o fim desta transação (true = SET LOCAL)
        q("SELECT set_config('app.tenant_id', :t, true)", ['t' => $id]);

        // Linha da empresa (com os textos padrão de contrato e fatura) e as 5 categorias padrão
        q('SELECT provisionar_dados_padrao(:nome)', ['nome' => $nome]);

        q("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES (:nome, :login, :hash, 'admin')",
          ['nome' => $adminNome, 'login' => $adminLogin, 'hash' => password_hash($senhaTemp, PASSWORD_DEFAULT)]);
    });

    log_app('INFO', "Locadora criada: $slug.");
    // A senha temporária fica na sessão só até ser mostrada (uma única vez)
    $_SESSION['provisionada'] = ['nome' => $nome, 'slug' => $slug, 'login' => $adminLogin, 'senha' => $senhaTemp];
    redirect(url('tenants', ['a' => 'criada']));
}

/** Mostra os dados de acesso da locadora recém-criada e os apaga da sessão. */
function tenants_criada(): void
{
    $p = $_SESSION['provisionada'] ?? null;
    unset($_SESSION['provisionada']);
    if ($p === null) {
        redirect(url('tenants'));
    }
    header('Cache-Control: no-store');

    layout_start('Locadora criada', 'tenants');
    ?>
    <div class="flash flash-ok">Locadora <strong><?= e($p['nome']) ?></strong> criada com sucesso.</div>
    <div class="card">
        <h2>Dados de acesso do administrador</h2>
        <p class="alerta">Anote ou envie agora: a senha temporária não será mostrada de novo.</p>
        <dl class="dados">
            <dt>Endereço</dt><dd><code>index.php</code> (login das locadoras)</dd>
            <dt>Empresa</dt><dd><code><?= e($p['slug']) ?></code></dd>
            <dt>Usuário</dt><dd><code><?= e($p['login']) ?></code></dd>
            <dt>Senha temporária</dt><dd><code id="senha-temp"><?= e($p['senha']) ?></code></dd>
        </dl>
        <p class="muted">Oriente o administrador a trocar a senha em Configurações no primeiro acesso.</p>
        <p><a class="btn" href="<?= e(url('tenants')) ?>">Voltar para a lista</a></p>
    </div>
    <?php
    layout_end();
}

function tenants_status(): void
{
    $id = post('id');
    $status = post('status');
    if (!is_uuid($id) || !in_array($status, ['ativa', 'suspensa'], true)) {
        throw new UserError('Requisição inválida.');
    }
    $nome = val('UPDATE tenants SET status = :s WHERE id = :id RETURNING nome', ['s' => $status, 'id' => $id]);
    if ($nome === null) {
        throw new UserError('Locadora não encontrada.');
    }
    log_app('INFO', "Locadora $id agora está $status.");
    flash($status === 'ativa' ? "Locadora $nome reativada." : "Locadora $nome suspensa. Os usuários dela perderam o acesso.");
    redirect(url('tenants'));
}

/** Senha temporária de 12 caracteres, sem os que se confundem (0/O, 1/l/I). */
function senha_temporaria(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $s;
}

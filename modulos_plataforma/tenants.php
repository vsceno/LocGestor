<?php
/*
 * Painel da plataforma: locadoras (tenants), planos e mensalidades.
 *
 * O papel locgestor_plataforma acessa a tabela tenants livremente, mas nas tabelas
 * das locadoras vale o mesmo RLS do app: para contar clientes ou criar o admin de
 * uma locadora é preciso definir o contexto dela, uma de cada vez.
 *
 * Mensalidade: tenants.vencimento é a próxima mensalidade (NULL = isenta). Passados
 * carencia_dias sem pagamento, o sistema da locadora bloqueia o acesso na hora
 * (tenant.php) e este painel marca a locadora como suspensa por inadimplência.
 * Registrar o pagamento avança o vencimento e reativa quem estava suspensa por isso.
 */

declare(strict_types=1);

/** Situação da mensalidade: [rótulo, cor do selo]. */
function assinatura_situacao(array $t): array
{
    if (empty($t['vencimento'])) {
        return ['sem cobrança', ''];
    }
    $dias = days_between(today(), $t['vencimento']);
    $limite = date('Y-m-d', strtotime($t['vencimento'] . ' +' . (int) $t['carencia_dias'] . ' days'));
    if (today() > $limite) {
        return ['inadimplente', 'vermelho'];
    }
    if ($dias < 0) {
        return ['vencida há ' . (-$dias) . ' dia(s)', 'amarelo'];
    }
    if ($dias <= 5) {
        return $dias === 0 ? ['vence hoje', 'amarelo'] : ["vence em $dias dia(s)", 'amarelo'];
    }
    return ['em dia', 'verde'];
}

/**
 * Suspende (status) quem passou da carência sem pagar. O bloqueio de acesso já acontece
 * sozinho no sistema da locadora; aqui o painel passa a mostrar a situação real.
 */
function suspender_inadimplentes(): void
{
    foreach (all("UPDATE tenants SET status = 'suspensa', motivo_suspensao = 'inadimplencia'
                   WHERE status = 'ativa' AND vencimento IS NOT NULL AND vencimento + carencia_dias < CURRENT_DATE
                RETURNING slug") as $t) {
        log_app('AVISO', "Locadora {$t['slug']} suspensa por inadimplência.");
    }
}

function planos_ativos(): array
{
    return all('SELECT id, nome, preco_mensal, limite_usuarios FROM planos WHERE ativo ORDER BY preco_mensal, nome');
}

/** Locadora pelo id da URL/formulário (validado). */
function locadora_por_id(string $id): ?array
{
    return is_uuid($id)
        ? one('SELECT t.*, p.nome AS plano_nome, p.preco_mensal FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = :id',
              ['id' => $id])
        : null;
}

function tenants_index(): void
{
    suspender_inadimplentes();
    $tenants = all('SELECT t.id, t.slug, t.nome, t.email_contato, t.status, t.motivo_suspensao, t.limite_usuarios, t.criado_em,
                           t.vencimento, t.carencia_dias, p.nome AS plano_nome, p.preco_mensal
                      FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id ORDER BY t.nome');

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

    $ativas = array_filter($tenants, fn($t) => $t['status'] === 'ativa');
    // Receita mensal recorrente: locadoras ativas com cobrança
    $mrr = array_sum(array_map(fn($t) => $t['vencimento'] ? (float) $t['preco_mensal'] : 0, $ativas));
    $inadimplentes = count(array_filter($tenants, fn($t) => $t['motivo_suspensao'] === 'inadimplencia' && $t['status'] === 'suspensa'));

    layout_start('Locadoras', 'tenants');
    ?>
    <div class="kpis">
        <div class="kpi"><span>Locadoras</span><strong><?= num(count($tenants)) ?></strong><small><?= num(count($ativas)) ?> ativa(s)</small></div>
        <div class="kpi verde"><span>Receita mensal recorrente</span><strong><?= money($mrr) ?></strong><small>locadoras ativas com cobrança</small></div>
        <div class="kpi vermelho"><span>Suspensas por inadimplência</span><strong><?= num($inadimplentes) ?></strong></div>
    </div>
    <div class="card">
        <div class="card-titulo">
            <h2>Locadoras cadastradas</h2>
            <div class="acoes">
                <a class="btn btn-sec" href="<?= e(url('planos')) ?>">Planos</a>
                <a class="btn" href="<?= e(url('tenants', ['a' => 'nova'])) ?>">+ Nova locadora</a>
            </div>
        </div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead>
                <tr><th>Locadora</th><th>Status</th><th>Plano</th><th>Mensalidade</th><th class="num">Usuários</th>
                    <th class="num">Clientes</th><th class="num">Loc. abertas</th><th>Último acesso</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tenants as $t): [$sit, $cor] = assinatura_situacao($t); ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('tenants', ['a' => 'editar', 'id' => $t['id']])) ?>"><strong><?= e($t['nome']) ?></strong></a><br>
                            <code><?= e($t['slug']) ?></code>
                            <?php if ($t['email_contato'] !== ''): ?><span class="muted"> · <?= e($t['email_contato']) ?></span><?php endif; ?>
                        </td>
                        <td><span class="selo <?= $t['status'] === 'ativa' ? 'verde' : 'vermelho' ?>"><?= e($t['status']) ?></span>
                            <?php if ($t['motivo_suspensao'] === 'inadimplencia'): ?><br><small class="muted">por inadimplência</small><?php endif; ?></td>
                        <td><?= e($t['plano_nome'] ?? '—') ?><?= $t['preco_mensal'] !== null ? '<br><small class="muted">' . money($t['preco_mensal']) . '/mês</small>' : '' ?></td>
                        <td><span class="selo <?= $cor ?>"><?= e($sit) ?></span>
                            <?php if ($t['vencimento']): ?><br><small class="muted">vence <?= e(dt($t['vencimento'])) ?></small><?php endif; ?></td>
                        <td class="num"><?= num($t['usuarios']) ?> / <?= num($t['limite_usuarios']) ?></td>
                        <td class="num"><?= num($t['clientes']) ?></td>
                        <td class="num"><?= num($t['abertas']) ?></td>
                        <td class="nowrap"><?= $t['ultimo_acesso'] ? e(dt($t['ultimo_acesso'], true)) : '<span class="muted">nunca</span>' ?></td>
                        <td class="nowrap"><a class="btn btn-sec btn-peq" href="<?= e(url('tenants', ['a' => 'editar', 'id' => $t['id']])) ?>">Gerenciar</a></td>
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
    $planos = planos_ativos();
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
                <label>CNPJ<input type="text" name="cnpj" value="<?= e(old('cnpj')) ?>" maxlength="18" inputmode="numeric"></label>
                <label>E-mail de contato<input type="email" name="email_contato" value="<?= e(old('email_contato')) ?>" maxlength="120"></label>
                <label>Telefone<input type="text" name="telefone" value="<?= e(old('telefone')) ?>" maxlength="20"></label>
            </div>
        </div>
        <div class="card">
            <h2>Plano e mensalidade</h2>
            <div class="linha">
                <label class="obrig">Plano
                    <select name="plano_id" required><?= options(array_column(array_map(fn($p) => ['id' => $p['id'],
                        'r' => $p['nome'] . ' — ' . money($p['preco_mensal']) . '/mês, até ' . $p['limite_usuarios'] . ' usuário(s)'], $planos), 'r', 'id'),
                        old('plano_id')) ?></select>
                </label>
                <label>Primeiro vencimento
                    <input type="date" name="vencimento" value="<?= e(old('vencimento', date('Y-m-d', strtotime('+30 days')))) ?>">
                    <span class="ajuda">Padrão: 30 dias (período de teste). Vazio = sem cobrança (conta interna/cortesia).</span>
                </label>
                <label class="obrig">Dias de carência
                    <input type="number" name="carencia_dias" value="<?= e(old('carencia_dias', '5')) ?>" min="0" max="60" required>
                    <span class="ajuda">Dias após o vencimento antes de suspender o acesso.</span>
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

/** Valida plano, vencimento e carência vindos do formulário. */
function assinatura_do_form(array &$erros): array
{
    $plano = is_uuid(post('plano_id')) ? one('SELECT id, nome, limite_usuarios FROM planos WHERE id = :id', ['id' => post('plano_id')]) : null;
    if ($plano === null) {
        $erros[] = 'Escolha o plano.';
    }
    $venc = post('vencimento');
    if ($venc !== '' && !valid_date($venc)) {
        $erros[] = 'Data de vencimento inválida.';
    }
    $carencia = filter_var(post('carencia_dias'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
    if ($carencia === false) {
        $erros[] = 'A carência deve ser de 0 a 60 dias.';
    }
    return ['plano' => $plano, 'vencimento' => $venc === '' ? null : $venc, 'carencia' => $carencia];
}

/** Provisionamento: cria a locadora, os dados padrão e o admin, tudo numa transação. */
function tenants_criar(): void
{
    $nome = post('nome');
    $slug = strtolower(post('slug'));
    $cnpj = so_digitos(post('cnpj'));
    $email = post('email_contato');
    $telefone = post('telefone');
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
    if (mb_strlen($telefone) > 20) {
        $erros[] = 'Telefone muito longo.';
    }
    $a = assinatura_do_form($erros);
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

    tx(function () use ($nome, $slug, $cnpj, $email, $telefone, $a, $adminNome, $adminLogin, $senhaTemp) {
        $id = val('INSERT INTO tenants (slug, nome, cnpj, email_contato, telefone, plano, limite_usuarios, plano_id, vencimento, carencia_dias)
                   VALUES (:slug, :nome, :cnpj, :email, :tel, :plano, :limite, :pid, :venc, :car) RETURNING id',
                  ['slug' => $slug, 'nome' => $nome, 'cnpj' => $cnpj, 'email' => $email, 'tel' => $telefone,
                   'plano' => $a['plano']['nome'], 'limite' => $a['plano']['limite_usuarios'], 'pid' => $a['plano']['id'],
                   'venc' => $a['vencimento'], 'car' => $a['carencia']]);

        // Contexto da nova locadora só até o fim desta transação (true = SET LOCAL)
        q("SELECT set_config('app.tenant_id', :t, true)", ['t' => $id]);

        // Linha da empresa (com os textos padrão de contrato e fatura) e as 5 categorias padrão
        q('SELECT provisionar_dados_padrao(:nome)', ['nome' => $nome]);

        // Senha temporária: o admin da locadora é obrigado a trocá-la no primeiro acesso
        q("INSERT INTO usuarios (nome, login, senha_hash, perfil, trocar_senha) VALUES (:nome, :login, :hash, 'admin', true)",
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
        <p class="muted">No primeiro acesso, o sistema obriga o administrador a definir uma senha nova.</p>
        <p><a class="btn" href="<?= e(url('tenants')) ?>">Voltar para a lista</a></p>
    </div>
    <?php
    layout_end();
}

/** Gerenciar uma locadora: dados, plano, vencimento, pagamentos e suspensão. */
function tenants_editar(): void
{
    $t = locadora_por_id(get('id'));
    if ($t === null) {
        pagina_erro(404, 'Locadora não encontrada.');
    }
    $v = fn($c, $p = '') => has_old() ? old($c, $p) : ($t[$c] ?? $p);
    $pagamentos = all('SELECT p.*, u.nome AS usuario FROM assinatura_pagamentos p LEFT JOIN plataforma_usuarios u ON u.id = p.usuario_id
                        WHERE p.locadora_id = :id ORDER BY p.criado_em DESC LIMIT 36', ['id' => $t['id']]);
    $planos = planos_ativos();
    [$sit, $cor] = assinatura_situacao($t);

    layout_start($t['nome'], 'tenants');
    ?>
    <div class="kpis">
        <div class="kpi"><span>Status</span><strong><?= e($t['status']) ?></strong>
            <small><?= $t['motivo_suspensao'] === 'inadimplencia' ? 'por inadimplência' : ($t['motivo_suspensao'] === 'manual' ? 'suspensa manualmente' : 'acesso liberado') ?></small></div>
        <div class="kpi azul"><span>Plano</span><strong><?= e($t['plano_nome'] ?? '—') ?></strong>
            <small><?= $t['preco_mensal'] !== null ? money($t['preco_mensal']) . '/mês · ' : '' ?>até <?= num($t['limite_usuarios']) ?> usuário(s)</small></div>
        <div class="kpi <?= $cor === 'vermelho' ? 'vermelho' : 'verde' ?>"><span>Mensalidade</span>
            <strong><?= $t['vencimento'] ? e(dt($t['vencimento'])) : 'sem cobrança' ?></strong><small><span class="selo <?= $cor ?>"><?= e($sit) ?></span></small></div>
    </div>

    <div class="grid-2">
        <form method="post" action="<?= e(url('tenants', ['a' => 'salvar'])) ?>" class="form card">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($t['id']) ?>">
            <h2>Dados e assinatura</h2>
            <p class="muted">Identificador de acesso: <code><?= e($t['slug']) ?></code></p>
            <label class="obrig">Nome<input type="text" name="nome" value="<?= e($v('nome')) ?>" required maxlength="150"></label>
            <div class="linha">
                <label>CNPJ<input type="text" name="cnpj" value="<?= e(doc_formatar($v('cnpj'))) ?>" maxlength="18" inputmode="numeric"></label>
                <label>E-mail<input type="email" name="email_contato" value="<?= e($v('email_contato')) ?>" maxlength="120"></label>
                <label>Telefone<input type="text" name="telefone" value="<?= e($v('telefone')) ?>" maxlength="20"></label>
            </div>
            <div class="linha">
                <label class="obrig">Plano<select name="plano_id" required><?= options(array_column(array_map(fn($p) => ['id' => $p['id'],
                    'r' => $p['nome'] . ' — ' . money($p['preco_mensal']) . '/mês'], $planos), 'r', 'id'), $v('plano_id'), '— escolha —') ?></select></label>
                <label>Limite de usuários<input type="number" name="limite_usuarios" value="<?= e(has_old() ? old('limite_usuarios') : '') ?>"
                       min="1" max="1000" placeholder="<?= e($t['limite_usuarios']) ?> (atual)">
                    <span class="ajuda">Vazio: o do plano escolhido. Preencha só para um limite especial.</span></label>
            </div>
            <div class="linha">
                <label>Próximo vencimento<input type="date" name="vencimento" value="<?= e($v('vencimento')) ?>">
                    <span class="ajuda">Vazio = sem cobrança.</span></label>
                <label class="obrig">Dias de carência<input type="number" name="carencia_dias" value="<?= e($v('carencia_dias', '5')) ?>" min="0" max="60" required></label>
            </div>
            <button class="btn">Salvar</button>
        </form>

        <div>
            <div class="card">
                <h2>Registrar pagamento da mensalidade</h2>
                <form method="post" action="<?= e(url('tenants', ['a' => 'pagamento'])) ?>" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                    <div class="linha">
                        <label class="obrig">Meses pagos<input type="number" name="meses" value="1" min="1" max="24" required></label>
                        <label>Valor R$<input type="text" name="valor" inputmode="decimal"
                               placeholder="<?= $t['preco_mensal'] !== null ? e(num($t['preco_mensal'], 2)) . ' por mês' : '' ?>">
                            <span class="ajuda">Vazio: preço do plano × meses.</span></label>
                    </div>
                    <div class="linha">
                        <label class="obrig">Data<input type="date" name="data" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required></label>
                        <label class="obrig">Forma<select name="forma"><?= options(FORMAS_PGTO, 'PIX') ?></select></label>
                    </div>
                    <label>Observação<input type="text" name="obs" maxlength="200"></label>
                    <p class="muted" style="margin:0 0 10px">Avança o vencimento<?= $t['vencimento'] ? ' (hoje ' . e(dt($t['vencimento'])) . ')' : '' ?>
                        e reativa a locadora se estiver suspensa por inadimplência.</p>
                    <button class="btn">Registrar pagamento</button>
                </form>
            </div>
            <div class="card">
                <h2>Acesso</h2>
                <form method="post" action="<?= e(url('tenants', ['a' => 'status'])) ?>"
                      data-confirmar="<?= e($t['status'] === 'ativa' ? "Suspender {$t['nome']}? Os usuários dela perdem o acesso imediatamente." : "Reativar {$t['nome']}?") ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                    <?php if ($t['status'] === 'ativa'): ?>
                        <input type="hidden" name="status" value="suspensa">
                        <button class="btn btn-perigo">Suspender acesso</button>
                    <?php else: ?>
                        <input type="hidden" name="status" value="ativa">
                        <button class="btn">Reativar acesso</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-titulo">
            <h2>Pagamentos</h2>
            <?php if ($pagamentos): ?>
                <form method="post" action="<?= e(url('tenants', ['a' => 'estornar'])) ?>"
                      data-confirmar="Estornar o último pagamento? O vencimento volta para <?= e(dt($pagamentos[0]['vencimento_anterior'])) ?>.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                    <button class="btn btn-sec btn-peq">Estornar o último</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="tabela-wrap">
            <table class="tabela">
                <thead><tr><th>Data</th><th class="num">Meses</th><th class="num">Valor</th><th>Forma</th><th>Vencimento</th><th>Registrado por</th><th>Obs.</th></tr></thead>
                <tbody>
                <?php foreach ($pagamentos as $p): ?>
                    <tr><td><?= e(dt($p['data'])) ?></td><td class="num"><?= num($p['meses']) ?></td><td class="num"><?= money($p['valor']) ?></td>
                        <td><?= e($p['forma']) ?></td><td class="nowrap"><?= e(dt($p['vencimento_anterior'])) ?> → <?= e(dt($p['vencimento_novo'])) ?></td>
                        <td><?= e($p['usuario'] ?? '') ?></td><td><?= e($p['obs']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$pagamentos): ?><tr><td colspan="7" class="vazio">Nenhum pagamento registrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_end();
}

function tenants_salvar(): void
{
    $t = locadora_por_id(post('id'));
    if ($t === null) {
        throw new UserError('Locadora não encontrada.');
    }
    $nome = post('nome');
    $cnpj = so_digitos(post('cnpj'));
    $email = post('email_contato');
    $erros = [];
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
        $erros[] = 'Informe o nome da locadora (3 a 150 caracteres).';
    }
    if ($cnpj !== '' && (strlen($cnpj) !== 14 || !doc_valido($cnpj))) {
        $erros[] = 'CNPJ inválido.';
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $erros[] = 'E-mail de contato inválido.';
    }
    $a = assinatura_do_form($erros);
    $limite = post('limite_usuarios') === '' ? ($a['plano']['limite_usuarios'] ?? null)
        : filter_var(post('limite_usuarios'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limite === false) {
        $erros[] = 'O limite de usuários deve ser de 1 a 1000.';
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }
    q('UPDATE tenants SET nome = :n, cnpj = :c, email_contato = :e, telefone = :tel, plano_id = :p, plano = :pn,
                          limite_usuarios = :l, vencimento = :v, carencia_dias = :car WHERE id = :id',
      ['n' => $nome, 'c' => $cnpj, 'e' => $email, 'tel' => mb_substr(post('telefone'), 0, 20), 'p' => $a['plano']['id'],
       'pn' => $a['plano']['nome'], 'l' => $limite, 'v' => $a['vencimento'], 'car' => $a['carencia'], 'id' => $t['id']]);
    log_app('INFO', "Locadora {$t['slug']} atualizada (plano {$a['plano']['nome']}, vencimento " . ($a['vencimento'] ?? 'isenta') . ').');
    flash('Locadora atualizada.');
    redirect(url('tenants', ['a' => 'editar', 'id' => $t['id']]));
}

/**
 * Pagamento de mensalidade: avança o vencimento em N meses (a partir do vencimento atual,
 * para manter o ciclo; se não havia cobrança, a partir de hoje) e reativa quem estava
 * suspensa por inadimplência.
 */
function tenants_pagamento(): void
{
    $id = post('id');
    $meses = filter_var(post('meses'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24]]);
    $data = post('data');
    $obs = mb_substr(post('obs'), 0, 200);
    if (!is_uuid($id) || $meses === false || !valid_date($data) || $data > today()) {
        throw new UserError('Confira os meses (1 a 24) e a data do pagamento.');
    }
    $forma = in_array(post('forma'), FORMAS_PGTO, true) ? post('forma') : throw new UserError('Escolha a forma de pagamento.');

    $r = tx(function () use ($id, $meses, $data, $obs, $forma) {
        $t = one('SELECT t.id, t.slug, t.nome, t.vencimento, t.status, t.motivo_suspensao, p.preco_mensal
                    FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id WHERE t.id = :id FOR UPDATE OF t', ['id' => $id]);
        if ($t === null) {
            throw new UserError('Locadora não encontrada.');
        }
        $valor = post('valor') === '' ? round((float) $t['preco_mensal'] * $meses, 2) : parse_money(post('valor'));
        if ($valor < 0) {
            throw new UserError('O valor não pode ser negativo.');
        }
        $base = $t['vencimento'] ?? today();
        // Soma de meses pelo banco: 31/01 + 1 mês = 28/02 (e não 03/03)
        $novo = (string) val('SELECT (CAST(:b AS date) + make_interval(months => :m))::date', ['b' => $base, 'm' => $meses]);
        q('INSERT INTO assinatura_pagamentos (locadora_id, data, meses, valor, forma, vencimento_anterior, vencimento_novo, obs, usuario_id)
           VALUES (:l, :d, :m, :v, :f, :va, :vn, :o, :u)',
          ['l' => $id, 'd' => $data, 'm' => $meses, 'v' => $valor, 'f' => $forma, 'va' => $base, 'vn' => $novo, 'o' => $obs, 'u' => uid()]);
        $reativou = $t['status'] === 'suspensa' && $t['motivo_suspensao'] === 'inadimplencia';
        q("UPDATE tenants SET vencimento = :v,
                 status = CASE WHEN status = 'suspensa' AND motivo_suspensao = 'inadimplencia' THEN 'ativa' ELSE status END,
                 motivo_suspensao = CASE WHEN motivo_suspensao = 'inadimplencia' THEN '' ELSE motivo_suspensao END
           WHERE id = :id", ['v' => $novo, 'id' => $id]);
        return ['t' => $t, 'novo' => $novo, 'valor' => $valor, 'reativou' => $reativou];
    });
    log_app('INFO', "Pagamento da locadora {$r['t']['slug']}: {$meses} mês(es), vencimento agora {$r['novo']}.");
    flash('Pagamento de ' . money($r['valor']) . ' registrado. Próximo vencimento: ' . dt($r['novo']) . '.'
          . ($r['reativou'] ? ' Locadora reativada.' : ''));
    redirect(url('tenants', ['a' => 'editar', 'id' => $id]));
}

/** Estorna o último pagamento: o vencimento volta ao que era antes dele. */
function tenants_estornar(): void
{
    $id = post('id');
    if (!is_uuid($id)) {
        throw new UserError('Requisição inválida.');
    }
    tx(function () use ($id) {
        $venc = one('SELECT vencimento FROM tenants WHERE id = :id FOR UPDATE', ['id' => $id]);
        $p = one('SELECT id, vencimento_anterior, vencimento_novo FROM assinatura_pagamentos
                   WHERE locadora_id = :id ORDER BY criado_em DESC LIMIT 1', ['id' => $id]);
        if ($venc === null || $p === null) {
            throw new UserError('Nenhum pagamento para estornar.');
        }
        if ($venc['vencimento'] !== $p['vencimento_novo']) {
            throw new UserError('O vencimento foi alterado depois deste pagamento. Ajuste o vencimento manualmente e exclua o pagamento pelo banco, se preciso.');
        }
        q('DELETE FROM assinatura_pagamentos WHERE id = :id', ['id' => $p['id']]);
        q('UPDATE tenants SET vencimento = :v WHERE id = :id', ['v' => $p['vencimento_anterior'], 'id' => $id]);
    });
    flash('Último pagamento estornado.');
    redirect(url('tenants', ['a' => 'editar', 'id' => $id]));
}

function tenants_status(): void
{
    $id = post('id');
    $status = post('status');
    if (!is_uuid($id) || !in_array($status, ['ativa', 'suspensa'], true)) {
        throw new UserError('Requisição inválida.');
    }
    $t = val('UPDATE tenants SET status = :s, motivo_suspensao = :m WHERE id = :id RETURNING nome',
             ['s' => $status, 'm' => $status === 'suspensa' ? 'manual' : '', 'id' => $id]);
    if ($t === null) {
        throw new UserError('Locadora não encontrada.');
    }
    log_app('INFO', "Locadora $id agora está $status.");
    $loc = locadora_por_id($id);
    if ($status === 'ativa' && tenant_inadimplente($loc)) {
        flash("Locadora $t reativada, mas a mensalidade continua vencida além da carência: o acesso seguirá bloqueado "
              . 'até registrar o pagamento ou ajustar o vencimento.', 'aviso');
    } else {
        flash($status === 'ativa' ? "Locadora $t reativada." : "Locadora $t suspensa. Os usuários dela perderam o acesso.");
    }
    redirect(url('tenants', ['a' => 'editar', 'id' => $id]));
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

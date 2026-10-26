<?php
/*
 * Login da locadora: Empresa (slug) + Usuário + Senha.
 */

declare(strict_types=1);

const COOKIE_EMPRESA = 'locgestor_empresa';

function login_index(): void
{
    if (user() !== null) {
        redirect(url('dashboard'));
    }
    $empresa = old('empresa', $_COOKIE[COOKIE_EMPRESA] ?? '');
    $empresa = preg_match('/^[a-z0-9-]{1,40}$/', (string) $empresa) ? $empresa : '';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= e(config('app_name', 'LocGestor')) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<?= pwa_head() ?>
</head>
<body class="pagina-login">
<main class="login-caixa">
    <div class="login-marca"><span class="logo">LG</span><strong><?= e(config('app_name', 'LocGestor')) ?></strong></div>
    <p class="login-sub">Locação de equipamentos</p>
    <?= flashes_html() ?>
    <form method="post" action="<?= e(url('login', ['a' => 'entrar'])) ?>" class="form">
        <?= csrf_field() ?>
        <label>Empresa
            <input type="text" name="empresa" value="<?= e($empresa) ?>" required maxlength="40"
                   autocapitalize="none" autocomplete="organization" placeholder="ex.: alfa-locacoes"
                   <?= $empresa === '' ? 'autofocus' : '' ?>>
        </label>
        <label>Usuário
            <input type="text" name="login" value="<?= e(old('login')) ?>" required maxlength="40"
                   autocapitalize="none" autocomplete="username" <?= $empresa !== '' ? 'autofocus' : '' ?>>
        </label>
        <label>Senha
            <input type="password" name="senha" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn btn-bloco">Entrar</button>
    </form>
</main>
</body>
</html>
<?php
}

function login_entrar(): void
{
    $slug = strtolower(post('empresa'));
    $login = strtolower(post('login'));
    $senha = $_POST['senha'] ?? '';

    // Excesso de erros recentes nesta conta ou neste IP: recusa sem nem conferir a senha
    $chaves = login_chaves("$slug|$login");
    if (login_bloqueado($chaves)) {
        login_recusar_por_excesso();
    }

    $falhar = function () use ($chaves): never {
        tenant_limpar();
        login_falhou($chaves);
        usleep(random_int(600000, 1000000)); // atraso contra tentativa e erro
        log_app('AVISO', 'Login recusado.');
        throw new UserError('Dados de acesso inválidos.');
    };

    if (!preg_match('/^[a-z0-9-]{3,40}$/', $slug) || $login === '' || !is_string($senha) || $senha === '') {
        $falhar();
    }

    // tenants não tem RLS; o app só lê algumas colunas. Mensagem genérica não revela se a empresa existe.
    $t = one('SELECT id, slug, status, vencimento, carencia_dias FROM tenants WHERE slug = :s', ['s' => $slug]);
    // Suspensa (manualmente ou por falta de pagamento): mesma mensagem genérica de dados inválidos
    if ($t === null || $t['status'] !== 'ativa' || tenant_inadimplente($t)) {
        // Hash de uma senha aleatória descartada: gasta o mesmo tempo de uma verificação real
        password_verify($senha, '$2y$12$b3ZTYBwcXEHVCZ/K.j4y1u/YQ8SFgLjsCvjtO9JLNoPl0AHrbY4vO');
        $falhar();
    }

    // A partir daqui a busca do usuário já é filtrada pelo RLS da locadora informada
    tenant_definir($t['id']);
    $u = one('SELECT id, nome, login, senha_hash, perfil, ativo FROM usuarios WHERE login = :l', ['l' => $login]);
    if ($u === null || !$u['ativo'] || !password_verify($senha, $u['senha_hash'])) {
        $falhar();
    }

    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET senha_hash = :h WHERE id = :id', ['h' => password_hash($senha, PASSWORD_DEFAULT), 'id' => $u['id']]);
    }
    q('UPDATE usuarios SET ultimo_acesso = now() WHERE id = :id', ['id' => $u['id']]);
    login_sucesso($chaves);

    session_regenerate_id(true);
    $_SESSION = [
        'tenant_id'   => $t['id'],
        'tenant_slug' => $t['slug'],
        'usuario'     => ['id' => $u['id'], 'nome' => $u['nome'], 'login' => $u['login'], 'perfil' => $u['perfil']],
    ];
    setcookie(COOKIE_EMPRESA, $t['slug'], [
        'expires'  => time() + 365 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    log_app('INFO', 'Login.');
    redirect(url('dashboard'));
}

function login_sair(): void
{
    if (!is_post()) {
        redirect(url('dashboard'));
    }
    log_app('INFO', 'Logout.');
    sessao_encerrar();
    flash('Você saiu do sistema.');
    redirect(url('login'));
}

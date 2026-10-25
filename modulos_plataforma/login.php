<?php
/*
 * Login do painel da plataforma (tabela plataforma_usuarios).
 */

declare(strict_types=1);

function login_index(): void
{
    if (user() !== null) {
        redirect(url('tenants'));
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plataforma · <?= e(config('app_name', 'LocGestor')) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="pagina-login">
<main class="login-caixa">
    <div class="login-marca"><span class="logo">LG</span><strong><?= e(config('app_name', 'LocGestor')) ?></strong></div>
    <p class="login-sub">Painel da plataforma</p>
    <?= flashes_html() ?>
    <form method="post" action="<?= e(url('login', ['a' => 'entrar'])) ?>" class="form">
        <?= csrf_field() ?>
        <label>Usuário
            <input type="text" name="login" value="<?= e(old('login')) ?>" required maxlength="40"
                   autocapitalize="none" autocomplete="username" autofocus>
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
    $login = strtolower(post('login'));
    $senha = $_POST['senha'] ?? '';

    $chaves = login_chaves($login);
    if (login_bloqueado($chaves)) {
        login_recusar_por_excesso();
    }

    $u = $login !== '' && is_string($senha) && $senha !== ''
        ? one('SELECT id, nome, login, senha_hash, ativo FROM plataforma_usuarios WHERE login = :l', ['l' => $login])
        : null;
    if ($u === null || !$u['ativo'] || !password_verify($senha, $u['senha_hash'])) {
        login_falhou($chaves);
        usleep(random_int(600000, 1000000));
        log_app('AVISO', 'Login da plataforma recusado.');
        throw new UserError('Dados de acesso inválidos.');
    }

    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE plataforma_usuarios SET senha_hash = :h WHERE id = :id',
          ['h' => password_hash($senha, PASSWORD_DEFAULT), 'id' => $u['id']]);
    }
    q('UPDATE plataforma_usuarios SET ultimo_acesso = now() WHERE id = :id', ['id' => $u['id']]);
    login_sucesso($chaves);

    session_regenerate_id(true);
    $_SESSION = ['usuario' => ['id' => $u['id'], 'nome' => $u['nome'], 'login' => $u['login'], 'perfil' => 'suporte']];
    log_app('INFO', 'Login da plataforma.');
    redirect(url('tenants'));
}

function login_sair(): void
{
    if (!is_post()) {
        redirect(url('tenants'));
    }
    log_app('INFO', 'Logout da plataforma.');
    sessao_encerrar();
    flash('Você saiu do painel da plataforma.');
    redirect(url('login'));
}

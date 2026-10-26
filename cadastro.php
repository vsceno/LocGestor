<?php
/*
 * LocGestor - cadastro público: uma locadora nova se cadastra e começa o período de teste.
 *
 * Desligado por padrão: só existe com 'cadastro_publico' => true no config.php (senão, 404).
 * Contra robôs (sem serviço externo): campo-isca invisível, tempo mínimo de preenchimento e
 * no máximo CADASTRO_MAX_POR_IP cadastros por IP por hora (o IP é guardado só como hash).
 * A criação é a mesma do painel da plataforma (locadora_provisionar), com o papel locgestor_plataforma.
 */

declare(strict_types=1);

const AREA = 'cadastro';

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/assinatura.php';

const CADASTRO_MAX_POR_IP = 3;
const CADASTRO_TEMPO_MIN = 3;   // segundos entre abrir e enviar o formulário

function cadastro_chave_ip(): string
{
    return hash('sha256', 'cadastro|ip|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Plano do período de teste: o do config ('cadastro_plano') ou o mais barato ativo. */
function cadastro_plano(): ?array
{
    $nome = (string) config('cadastro_plano', '');
    return $nome !== ''
        ? one('SELECT id, nome, limite_usuarios FROM planos WHERE ativo AND nome = :n', ['n' => $nome])
        : one('SELECT id, nome, limite_usuarios FROM planos WHERE ativo ORDER BY preco_mensal, nome LIMIT 1');
}

/** Valida e cria. Devolve os dados de acesso para a tela de sucesso. */
function cadastro_criar(array $d): array
{
    if (trim((string) ($d['site'] ?? '')) !== '') {
        log_app('AVISO', 'Cadastro público recusado: campo-isca preenchido (robô).');
        throw new UserError('Não foi possível concluir o cadastro. Tente de novo.');
    }
    $inicio = (int) ($_SESSION['cadastro_inicio'] ?? 0);
    if ($inicio === 0 || time() - $inicio < CADASTRO_TEMPO_MIN) {
        throw new UserError('Confira os dados e envie o formulário de novo.');
    }
    $recentes = (int) val("SELECT count(*) FROM login_tentativas WHERE chave = :c AND criado_em > now() - INTERVAL '1 hour'",
                          ['c' => cadastro_chave_ip()]);
    if ($recentes >= CADASTRO_MAX_POR_IP) {
        log_app('ALERTA DE SEGURANÇA', 'Cadastro público: limite de cadastros por IP atingido.');
        throw new UserError('Muitos cadastros feitos daqui na última hora. Tente mais tarde ou fale com o suporte.');
    }

    $t = [
        'nome'        => trim((string) ($d['nome'] ?? '')),
        'slug'        => strtolower(trim((string) ($d['slug'] ?? ''))),
        'cnpj'        => so_digitos((string) ($d['cnpj'] ?? '')),
        'email'       => trim((string) ($d['email'] ?? '')),
        'telefone'    => trim((string) ($d['telefone'] ?? '')),
        'admin_nome'  => trim((string) ($d['admin_nome'] ?? '')),
        'admin_login' => strtolower(trim((string) ($d['admin_login'] ?? 'admin'))),
        'admin_senha' => (string) ($d['senha'] ?? ''),
        'trocar_senha' => false,
        'carencia'    => 5,
    ];
    $erros = [];
    if (mb_strlen($t['nome']) < 3 || mb_strlen($t['nome']) > 150) {
        $erros[] = 'Informe o nome da locadora (3 a 150 caracteres).';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $t['slug'])) {
        $erros[] = 'Identificador inválido: de 3 a 40 letras minúsculas, números e hífen (sem hífen no início ou no fim).';
    }
    if ($t['cnpj'] !== '' && (strlen($t['cnpj']) !== 14 || !doc_valido($t['cnpj']))) {
        $erros[] = 'CNPJ inválido.';
    }
    if (filter_var($t['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($t['email']) > 120) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if (!in_array(strlen(so_digitos($t['telefone'])), [10, 11], true)) {
        $erros[] = 'Informe o telefone com DDD.';
    }
    if (mb_strlen($t['admin_nome']) < 3 || mb_strlen($t['admin_nome']) > 100) {
        $erros[] = 'Informe o seu nome (3 a 100 caracteres).';
    }
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $t['admin_login'])) {
        $erros[] = 'Usuário inválido (3 a 40 letras minúsculas, números, ponto, hífen ou sublinhado).';
    }
    if (mb_strlen($t['admin_senha']) < 8) {
        $erros[] = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif ($t['admin_senha'] !== (string) ($d['senha_confirma'] ?? '')) {
        $erros[] = 'A confirmação da senha não confere.';
    }
    if (empty($d['aceite'])) {
        $erros[] = 'Confirme que leu e aceita os termos de uso.';
    }
    if ($erros) {
        throw new UserError(implode(' ', $erros));
    }
    $t['plano'] = cadastro_plano() ?? throw new UserError('Cadastro indisponível no momento. Fale com o suporte.');
    $dias = max(1, min(90, (int) config('cadastro_dias_teste', 14)));
    $t['vencimento'] = date('Y-m-d', strtotime("+$dias days"));

    locadora_provisionar($t);
    q('INSERT INTO login_tentativas (chave) VALUES (:c)', ['c' => cadastro_chave_ip()]);
    log_app('INFO', "Locadora criada pelo cadastro público: {$t['slug']} (teste até {$t['vencimento']}).");
    return ['nome' => $t['nome'], 'slug' => $t['slug'], 'login' => $t['admin_login'], 'vencimento' => $t['vencimento']];
}

function cadastro_pagina(string $titulo, callable $conteudo): never
{
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e(config('app_name', 'LocGestor')) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="pagina-login">
<main class="login-caixa cadastro-caixa">
    <div class="login-marca"><span class="logo">LG</span><strong><?= e(config('app_name', 'LocGestor')) ?></strong></div>
    <?php $conteudo(); ?>
</main>
</body>
</html>
<?php
    exit;
}

// ---------------------------------------------------------------------
if (!cadastro_publico_ativo()) {
    http_response_code(404);
    cadastro_pagina('Página não encontrada', function () {
        echo '<p class="login-sub">Página não encontrada.</p><p><a href="index.php?p=login">Ir para o login</a></p>';
    });
}

if (is_post()) {
    try {
        csrf_check();
        $_SESSION['cadastro_ok'] = cadastro_criar($_POST);
        unset($_SESSION['cadastro_inicio']);
        setcookie('locgestor_empresa', $_SESSION['cadastro_ok']['slug'], [
            'expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        redirect('cadastro.php?pronto=1');
    } catch (UserError $e) {
        tratar_erro_usuario($e->getMessage());
    } catch (PDOException $e) {
        tratar_erro_usuario(traduzir_erro_banco($e));
    }
}

if (get('pronto') === '1' && !empty($_SESSION['cadastro_ok'])) {
    $ok = $_SESSION['cadastro_ok'];
    unset($_SESSION['cadastro_ok']);
    cadastro_pagina('Cadastro concluído', function () use ($ok) {
        ?>
        <p class="login-sub">Pronto! A <strong><?= e($ok['nome']) ?></strong> já pode usar o sistema.</p>
        <dl class="dados">
            <dt>Empresa</dt><dd><code><?= e($ok['slug']) ?></code></dd>
            <dt>Usuário</dt><dd><code><?= e($ok['login']) ?></code></dd>
            <dt>Teste grátis até</dt><dd><?= e(dt($ok['vencimento'])) ?></dd>
        </dl>
        <p class="ajuda">Guarde o identificador da empresa: ele é pedido no login, junto com o usuário e a senha que você escolheu.</p>
        <a class="btn btn-bloco" href="index.php?p=login">Entrar no sistema</a>
        <?php
    });
}

$_SESSION['cadastro_inicio'] = time();
$dias = max(1, min(90, (int) config('cadastro_dias_teste', 14)));
cadastro_pagina('Teste grátis', function () use ($dias) {
    ?>
    <p class="login-sub">Teste grátis por <?= num($dias) ?> dias, sem cartão.</p>
    <?= flashes_html() ?>
    <form method="post" action="cadastro.php" class="form">
        <?= csrf_field() ?>
        <label class="obrig">Nome da locadora<input type="text" name="nome" value="<?= e(old('nome')) ?>" required maxlength="150"></label>
        <label class="obrig">Identificador da empresa (usado no login)
            <input type="text" name="slug" value="<?= e(old('slug')) ?>" required maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]"
                   autocapitalize="none" placeholder="ex.: locadora-silva">
            <span class="ajuda">Letras minúsculas, números e hífen.</span></label>
        <div class="linha">
            <label class="obrig">E-mail<input type="email" name="email" value="<?= e(old('email')) ?>" required maxlength="120"></label>
            <label class="obrig">Telefone (com DDD)<input type="tel" name="telefone" value="<?= e(old('telefone')) ?>" required maxlength="20" data-mascara="telefone"></label>
        </div>
        <label>CNPJ (opcional)<input type="text" name="cnpj" value="<?= e(old('cnpj')) ?>" maxlength="18" inputmode="numeric"></label>
        <div class="linha">
            <label class="obrig">Seu nome<input type="text" name="admin_nome" value="<?= e(old('admin_nome')) ?>" required maxlength="100"></label>
            <label class="obrig">Usuário<input type="text" name="admin_login" value="<?= e(old('admin_login', 'admin')) ?>" required maxlength="40" autocapitalize="none"></label>
        </div>
        <div class="linha">
            <label class="obrig">Senha<input type="password" name="senha" required minlength="8" autocomplete="new-password"></label>
            <label class="obrig">Confirme a senha<input type="password" name="senha_confirma" required minlength="8" autocomplete="new-password"></label>
        </div>
        <div class="isca" aria-hidden="true"><label>Site<input type="text" name="site" tabindex="-1" autocomplete="off"></label></div>
        <label class="check"><input type="checkbox" name="aceite" value="1" required> Li e aceito os termos de uso e o tratamento dos dados conforme a LGPD.</label>
        <button type="submit" class="btn btn-bloco">Criar minha conta</button>
    </form>
    <p class="ajuda" style="text-align:center;margin-top:12px">Já tem conta? <a href="index.php?p=login">Entrar</a></p>
    <?php
});

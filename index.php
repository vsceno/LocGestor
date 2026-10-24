<?php
/*
 * LocGestor - sistema das locadoras.
 * Rotas: index.php?p=<modulo>&a=<acao>  →  função <modulo>_<acao>() em modulos/<modulo>.php
 */

declare(strict_types=1);

const AREA = 'locadora';
const MODULOS = ['login', 'dashboard', 'clientes', 'produtos', 'locacoes', 'reservas',
                 'caixa', 'relatorios', 'config', 'api'];

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

$pagina = get('p', 'dashboard');
$acao = get('a', 'index');

try {
    if (!in_array($pagina, MODULOS, true) || !preg_match('/^[a-z_]+$/', $acao)) {
        pagina_erro(404, 'Página não encontrada.');
    }
    // Tudo exige login, menos a própria tela de login
    if ($pagina !== 'login' && user() === null) {
        redirect(url('login'));
    }
    if (is_post()) {
        csrf_check();
    }

    $arquivo = __DIR__ . "/modulos/$pagina.php";
    $funcao = "{$pagina}_{$acao}";
    if (!is_file($arquivo)) {
        pagina_erro(404, 'Página não encontrada.');
    }
    require $arquivo;
    if (!function_exists($funcao)) {
        pagina_erro(404, 'Página não encontrada.');
    }
    $funcao();
} catch (UserError $e) {
    // Erro de regra: guarda o que foi digitado, mostra a mensagem e volta ao formulário
    tratar_erro_usuario($e->getMessage());
} catch (PDOException $e) {
    tratar_erro_usuario(traduzir_erro_banco($e));
} catch (Throwable $e) {
    log_app('ERRO', get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    pagina_erro(500, 'Ocorreu um erro inesperado. Tente novamente; se persistir, avise o suporte.');
}

function tratar_erro_usuario(string $msg): never
{
    if (db_conectado() && db()->inTransaction()) {
        db()->rollBack();
    }
    if (is_post()) {
        // Senhas nunca vão para a sessão
        $dados = array_filter($_POST, fn($k) => $k !== '_csrf' && !str_contains((string) $k, 'senha'), ARRAY_FILTER_USE_KEY);
        $_SESSION['old'] = $dados;
        flash($msg, 'erro');
        voltar(url('dashboard'));
    }
    // Em GET, voltar para a mesma página causaria um laço: mostra a mensagem na tela
    pagina_erro(200, $msg);
}

/** Converte erros do PostgreSQL em mensagens amigáveis (pelo SQLSTATE e pelo nome da restrição). */
function traduzir_erro_banco(PDOException $e): string
{
    $estado = (string) $e->getCode();
    $msg = $e->getMessage();

    if ($estado === '42501') {
        // Violação de política RLS ou de permissão: não deveria acontecer pelo uso normal das telas
        log_app('ALERTA DE SEGURANÇA', "SQLSTATE $estado: $msg | " . ($_SERVER['REQUEST_URI'] ?? ''));
        return 'Operação não permitida.';
    }
    log_app('ERRO BANCO', "SQLSTATE $estado: $msg");

    $duplicados = [
        'clientes_cpf_cnpj_uk' => 'Já existe um cliente com este CPF/CNPJ.',
        'produtos_codigo_uk'   => 'Já existe um produto com este código.',
        'usuarios_login_uk'    => 'Já existe um usuário com este login.',
        'categorias_nome_uk'   => 'Já existe uma categoria com este nome.',
        'tenants_slug_uk'      => 'Já existe uma locadora com este identificador.',
    ];
    if ($estado === '23505') {
        foreach ($duplicados as $restricao => $texto) {
            if (str_contains($msg, $restricao)) {
                return $texto;
            }
        }
        return 'Registro duplicado.';
    }
    if ($estado === '23503') {
        // Inglês ou português, conforme o idioma do servidor
        return preg_match('/still referenced|ainda é referenciad/i', $msg)
            ? 'Este registro está em uso e não pode ser excluído.'
            : 'Registro relacionado não encontrado.';
    }
    if ($estado === '23514' || $estado === '22P02' || $estado === '22007' || $estado === '22008') {
        return 'Valor inválido em algum campo. Confira os dados informados.';
    }
    if (str_starts_with($estado, '08') || preg_match('/could not connect|connection|conex/i', $msg)) {
        return 'Não foi possível conectar ao banco de dados. Tente novamente em instantes.';
    }
    return 'Erro ao gravar ou consultar os dados. Tente novamente.';
}

function db_conectado(): bool
{
    try {
        db();
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Página simples de erro/aviso, com o layout quando há usuário logado. */
function pagina_erro(int $codigo, string $msg): never
{
    http_response_code($codigo);
    if (user() !== null && db_conectado()) {
        try {
            layout_start($codigo === 404 ? 'Não encontrado' : 'Aviso');
            echo '<div class="card"><p>' . e($msg) . '</p><p><a class="btn" href="javascript:history.back()">Voltar</a> '
               . '<a class="btn btn-sec" href="' . e(url('dashboard')) . '">Ir para o painel</a></p></div>';
            layout_end();
            exit;
        } catch (Throwable) {
            // cai para a versão sem layout
        }
    }
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>LocGestor</title>'
       . '<link rel="stylesheet" href="assets/css/app.css"></head><body class="pagina-login"><div class="login-caixa">'
       . '<p>' . e($msg) . '</p><p><a class="btn" href="index.php">Voltar ao início</a></p></div></body></html>';
    exit;
}

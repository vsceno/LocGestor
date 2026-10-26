<?php
/*
 * LocGestor - inicialização comum: configuração, sessão, banco, helpers,
 * CSRF, autenticação, formatação e validações.
 *
 * O front controller define antes de incluir este arquivo:
 *   const AREA = 'locadora'   (index.php, conecta como locgestor_app)
 *   const AREA = 'plataforma' (plataforma.php, conecta como locgestor_plataforma)
 *   const AREA = 'cadastro'   (cadastro.php, cadastro público de locadoras; também locgestor_plataforma)
 */

declare(strict_types=1);

if (!defined('AREA')) {
    define('AREA', 'locadora');
}
define('RAIZ', dirname(__DIR__));

// ---------------------------------------------------------------------
// Erros: nada aparece na tela, tudo vai para logs/php-erros.log.
// Warnings e notices viram exceção para não passarem despercebidos.
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', RAIZ . '/logs/php-erros.log');
// Pilha de erro sem os argumentos: a senha do banco (new PDO) nunca vai para o log
ini_set('zend.exception_ignore_args', '1');
set_error_handler(function (int $nivel, string $msg, string $arq, int $linha): bool {
    if (!(error_reporting() & $nivel)) {
        return false; // suprimido com @
    }
    throw new ErrorException($msg, 0, $nivel, $arq, $linha);
});

/** Erro de regra de negócio ou de validação: a mensagem é mostrada ao usuário. */
class UserError extends Exception
{
}

// ---------------------------------------------------------------------
// Configuração
// ---------------------------------------------------------------------
if (!is_file(RAIZ . '/config.php')) {
    http_response_code(500);
    exit('<p style="font-family:sans-serif">Copie <b>config.example.php</b> para <b>config.php</b> '
       . 'e preencha as senhas do banco.</p>');
}
$GLOBALS['CONFIG'] = require RAIZ . '/config.php';

function config(string $chave, $padrao = null)
{
    return $GLOBALS['CONFIG'][$chave] ?? $padrao;
}

/** Cadastro público de locadoras ligado? ('cadastro_publico' no config; LOCGESTOR_CADASTRO_PUBLICO=1 nos testes) */
function cadastro_publico_ativo(): bool
{
    return (bool) config('cadastro_publico', false) || getenv('LOCGESTOR_CADASTRO_PUBLICO') === '1';
}

date_default_timezone_set(config('timezone', 'America/Fortaleza'));
mb_internal_encoding('UTF-8');

const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB',
             'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
const FORMAS_PGTO = ['Dinheiro', 'PIX', 'Cartão de débito', 'Cartão de crédito', 'Boleto', 'Transferência', 'Cheque'];

// ---------------------------------------------------------------------
// Log da aplicação (nunca registrar CPF/CNPJ: ver log_limpo)
// ---------------------------------------------------------------------
function log_app(string $nivel, string $msg): void
{
    $linha = sprintf("[%s] %s %s tenant=%s usuario=%s %s\n",
        date('Y-m-d H:i:s'), $nivel, AREA,
        $_SESSION['tenant_slug'] ?? '-', $_SESSION['usuario']['login'] ?? '-', log_limpo($msg));
    // Programas de backup/sincronização podem travar o arquivo por instantes: tenta algumas vezes
    for ($i = 0; $i < 6; $i++) {
        if (@file_put_contents(RAIZ . '/logs/app.log', $linha, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
        usleep(40000 * ($i + 1));
    }
    error_log('LocGestor (app.log indisponível): ' . trim($linha));
}

/** LGPD: remove o DETAIL das mensagens do banco e mascara sequências de 11 ou 14 dígitos (CPF/CNPJ). */
function log_limpo(string $msg): string
{
    $msg = preg_replace('/(DETAIL|DETALHE):.*$/s', '', $msg);
    $msg = preg_replace('/\d{3}\.?\d{3}\.?\d{3}-?\d{2}|\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/', '***', $msg);
    return trim(preg_replace('/\s+/', ' ', $msg));
}

// ---------------------------------------------------------------------
// HTTPS: com 'forcar_https' => true no config.php, todo acesso por http é
// redirecionado para https, e o navegador passa a exigir https (HSTS).
// ---------------------------------------------------------------------
$emHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (config('forcar_https', false) && !$emHttps && PHP_SAPI !== 'cli') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}
if ($emHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

// ---------------------------------------------------------------------
// Cabeçalhos de segurança e sessão
// ---------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// Webhook e tarefas agendadas definem SEM_SESSAO: não há usuário nem cookie
if (!defined('SEM_SESSAO')) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(match (AREA) { 'plataforma' => 'locgestor_plataforma', 'cadastro' => 'locgestor_cadastro', default => 'locgestor' });
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Dados do último POST com erro, disponíveis só nesta requisição (old())
$GLOBALS['_OLD'] = $_SESSION['old'] ?? [];
unset($_SESSION['old']);

// ---------------------------------------------------------------------
// Banco de dados
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // O cadastro público cria locadoras: usa o papel da plataforma (o único que pode inserir em tenants)
        $c = config(AREA === 'plataforma' || AREA === 'cadastro' ? 'db_plataforma' : 'db_app');
        $pdo = new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']}",
            $c['user'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Nunca persistente: o app.tenant_id de uma requisição não pode sobrar para outra
                PDO::ATTR_PERSISTENT         => false,
            ]
        );
        $pdo->prepare('SELECT set_config(\'TimeZone\', :tz, false)')->execute(['tz' => config('timezone', 'America/Fortaleza')]);
        $pdo->exec("SET client_encoding TO 'UTF8'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** Primeira coluna da primeira linha, ou null se não houver linha (um booleano false continua false). */
function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

/** Executa $fn dentro de uma transação; desfaz tudo se der qualquer erro. */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------
// Saída e formatação (padrão brasileiro)
// ---------------------------------------------------------------------
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 1234.5 → "R$ 1.234,50"; negativo → "-R$ 692,65" */
function money($v): string
{
    $v = round((float) $v, 2);
    return ($v < 0 ? '-' : '') . 'R$ ' . number_format(abs($v), 2, ',', '.');
}

function num($v, int $casas = 0): string
{
    return number_format((float) $v, $casas, ',', '.');
}

/** "1.234,56" → 1234.56. Também aceita "1234.56". Vazio vale 0. */
function parse_money($s): float
{
    $s = trim(str_replace(['R$', ' '], '', (string) $s));
    if ($s === '') {
        return 0.0;
    }
    if (str_contains($s, ',')) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    }
    if (!is_numeric($s)) {
        throw new UserError('Valor inválido: ' . $s);
    }
    return round((float) $s, 2);
}

/** "2026-10-24" → "24/10/2026"; com $hora, inclui hh:mm. */
function dt($d, bool $hora = false): string
{
    if ($d === null || $d === '') {
        return '';
    }
    $ts = strtotime((string) $d);
    return $ts === false ? '' : date($hora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

function today(): string
{
    return date('Y-m-d');
}

/** Data no formato do banco (aaaa-mm-dd) e existente no calendário. */
function valid_date($s): bool
{
    return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Dias de $de até $ate (negativo se $ate for antes). */
function days_between(string $de, string $ate): int
{
    return (int) (new DateTime($de))->diff(new DateTime($ate))->format('%r%a');
}

// ---------------------------------------------------------------------
// Entrada
// ---------------------------------------------------------------------
function get(string $k, string $padrao = ''): string
{
    $v = $_GET[$k] ?? $padrao;
    return is_string($v) ? trim($v) : $padrao;
}

function post(string $k, string $padrao = ''): string
{
    $v = $_POST[$k] ?? $padrao;
    return is_string($v) ? trim($v) : $padrao;
}

function post_arr(string $k): array
{
    $v = $_POST[$k] ?? [];
    return is_array($v) ? $v : [];
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_uuid($s): bool
{
    return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
}

// ---------------------------------------------------------------------
// Navegação e mensagens
// ---------------------------------------------------------------------
function url(string $pagina, array $params = []): string
{
    $script = AREA === 'plataforma' ? 'plataforma.php' : 'index.php';
    return $script . '?' . http_build_query(['p' => $pagina] + $params);
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Volta para a página anterior (só se for deste mesmo site). */
function voltar(string $padrao): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $p = parse_url($ref);
    // Compara host E porta (HTTP_HOST traz a porta quando não é a padrão, ex.: localhost:8000)
    $origem = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    redirect($ref !== '' && $origem === ($_SERVER['HTTP_HOST'] ?? '') ? $ref : $padrao);
}

/** $tipo: ok | erro | aviso */
function flash(string $msg, string $tipo = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'tipo' => $tipo];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $k, $padrao = '')
{
    return $GLOBALS['_OLD'][$k] ?? $padrao;
}

function has_old(): bool
{
    return !empty($GLOBALS['_OLD']);
}

/** Padrão "contém" para LIKE, com % e _ do usuário escapados. */
function like(string $s): string
{
    return '%' . addcslashes($s, '%_\\') . '%';
}

/** Valor de um campo de formulário: o que foi digitado (após erro) ou o que está gravado. */
function valor_form(array $registro, string $campo, $padrao = '')
{
    return has_old() ? old($campo, '') : ($registro[$campo] ?? $padrao);
}

/** Checkbox: após erro, marcado se veio no POST; senão, o valor gravado. */
function marcado_form(array $registro, string $campo, bool $padrao = false): bool
{
    return has_old() ? old($campo, '') !== '' : (bool) ($registro[$campo] ?? $padrao);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $enviado = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($enviado) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $enviado)) {
        http_response_code(400);
        exit('<p style="font-family:sans-serif">Formulário expirado. <a href="javascript:history.back()">Volte</a>, '
           . 'recarregue a página e tente de novo.</p>');
    }
}

// ---------------------------------------------------------------------
// Usuário logado
// ---------------------------------------------------------------------
function user(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function uid(): ?string
{
    return $_SESSION['usuario']['id'] ?? null;
}

function is_admin(): bool
{
    return (user()['perfil'] ?? '') === 'admin';
}

function require_admin(): void
{
    if (!is_admin()) {
        throw new UserError('Acesso restrito ao administrador.');
    }
}

// ---------------------------------------------------------------------
// Proteção do login contra tentativa e erro: no máximo LOGIN_MAX_CONTA erros
// por conta e LOGIN_MAX_IP por IP a cada LOGIN_JANELA_MIN minutos.
// O bloqueio vale mesmo para conta inexistente, para não revelar quais existem.
// ---------------------------------------------------------------------
const LOGIN_JANELA_MIN = 15;
const LOGIN_MAX_CONTA = 5;
const LOGIN_MAX_IP = 20;

/** Chaves (hash) do IP e da conta. Nada pessoal é guardado em texto. */
function login_chaves(string $conta): array
{
    return [
        'ip'    => hash('sha256', 'ip|' . ($_SERVER['REMOTE_ADDR'] ?? '')),
        'conta' => hash('sha256', 'conta|' . AREA . '|' . mb_strtolower($conta)),
    ];
}

function login_bloqueado(array $chaves): bool
{
    $r = one('SELECT count(*) FILTER (WHERE chave = :c1) AS conta, count(*) FILTER (WHERE chave = :i1) AS ip
                FROM login_tentativas
               WHERE chave IN (:c2, :i2) AND criado_em > now() - make_interval(mins => :min)',
             ['c1' => $chaves['conta'], 'i1' => $chaves['ip'], 'c2' => $chaves['conta'], 'i2' => $chaves['ip'],
              'min' => LOGIN_JANELA_MIN]);
    return $r['conta'] >= LOGIN_MAX_CONTA || $r['ip'] >= LOGIN_MAX_IP;
}

function login_falhou(array $chaves): void
{
    q('INSERT INTO login_tentativas (chave) VALUES (:c), (:i)', ['c' => $chaves['conta'], 'i' => $chaves['ip']]);
    q("DELETE FROM login_tentativas WHERE criado_em < now() - INTERVAL '1 day'");
}

/** Login certo zera os erros daquela conta (os do IP continuam contando). */
function login_sucesso(array $chaves): void
{
    q('DELETE FROM login_tentativas WHERE chave = :c', ['c' => $chaves['conta']]);
}

function login_recusar_por_excesso(): never
{
    usleep(random_int(600000, 1000000));
    log_app('ALERTA DE SEGURANÇA', 'Login bloqueado por excesso de tentativas.');
    throw new UserError('Muitas tentativas de acesso. Aguarde ' . LOGIN_JANELA_MIN . ' minutos e tente de novo.');
}

/** Encerra a sessão atual e abre uma nova, vazia (para levar a mensagem flash). */
function sessao_encerrar(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Dados da locadora logada (linha da tabela empresa), com cache por requisição. */
function empresa(): array
{
    static $emp = null;
    if ($emp === null) {
        $emp = one('SELECT * FROM empresa') ?? [];
    }
    return $emp;
}

// ---------------------------------------------------------------------
// Formulários e listas
// ---------------------------------------------------------------------
/**
 * Gera <option>. $lista pode ser ['valor' => 'rótulo'] ou lista simples ['A', 'B'].
 * $vazio != null acrescenta uma primeira opção vazia com esse texto.
 */
function options(array $lista, $selecionado = '', ?string $vazio = null): string
{
    $simples = array_is_list($lista);
    $html = $vazio !== null ? '<option value="">' . e($vazio) . '</option>' : '';
    foreach ($lista as $valor => $rotulo) {
        $valor = $simples ? $rotulo : $valor;
        $sel = (string) $valor === (string) $selecionado ? ' selected' : '';
        $html .= '<option value="' . e($valor) . '"' . $sel . '>' . e($rotulo) . '</option>';
    }
    return $html;
}

/** Paginação: conta os registros e devolve página, limite e deslocamento. */
function paginate(string $sqlCount, array $params = [], ?int $porPagina = null): array
{
    $por = $porPagina ?? (int) config('per_page', 20);
    $total = (int) val($sqlCount, $params);
    $paginas = max(1, (int) ceil($total / $por));
    $pag = min(max(1, (int) get('pag', '1')), $paginas);
    return ['pagina' => $pag, 'por' => $por, 'offset' => ($pag - 1) * $por, 'total' => $total, 'paginas' => $paginas];
}

/** Links de paginação, mantendo os filtros atuais da URL. */
function pager(array $pg, string $pagina, array $params = []): string
{
    if ($pg['paginas'] <= 1) {
        return '';
    }
    $html = '<nav class="pager">';
    $ini = max(1, $pg['pagina'] - 3);
    $fim = min($pg['paginas'], $pg['pagina'] + 3);
    if ($pg['pagina'] > 1) {
        $html .= '<a href="' . e(url($pagina, $params + ['pag' => $pg['pagina'] - 1])) . '">&laquo;</a>';
    }
    for ($i = $ini; $i <= $fim; $i++) {
        $html .= $i === $pg['pagina']
            ? '<span class="atual">' . $i . '</span>'
            : '<a href="' . e(url($pagina, $params + ['pag' => $i])) . '">' . $i . '</a>';
    }
    if ($pg['pagina'] < $pg['paginas']) {
        $html .= '<a href="' . e(url($pagina, $params + ['pag' => $pg['pagina'] + 1])) . '">&raquo;</a>';
    }
    return $html . '<span class="pager-total">' . num($pg['total']) . ' registro(s)</span></nav>';
}

// ---------------------------------------------------------------------
// Documentos
// ---------------------------------------------------------------------
function so_digitos($s): string
{
    return preg_replace('/\D/', '', (string) $s);
}

/** Valida os dígitos verificadores de CPF (11 dígitos) ou CNPJ (14 dígitos). */
function doc_valido($doc): bool
{
    $d = so_digitos($doc);
    if (preg_match('/^(\d)\1*$/', $d)) {
        return false; // 000.000.000-00, 111..., etc.
    }
    if (strlen($d) === 11) {
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $d[$i] * (($t + 1) - $i);
            }
            if ((int) $d[$t] !== ((10 * $s) % 11) % 10) {
                return false;
            }
        }
        return true;
    }
    if (strlen($d) === 14) {
        $pesos = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];
        foreach ($pesos as $k => $p) {
            $s = 0;
            foreach ($p as $i => $peso) {
                $s += (int) $d[$i] * $peso;
            }
            $r = $s % 11;
            if ((int) $d[12 + $k] !== ($r < 2 ? 0 : 11 - $r)) {
                return false;
            }
        }
        return true;
    }
    return false;
}

/** 85988776655 → (85) 98877-6655; 8532221000 → (85) 3222-1000. Outros formatos ficam como digitados. */
function telefone_formatar($tel): string
{
    $d = so_digitos($tel);
    if (strlen($d) === 11) {
        return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $d);
    }
    if (strlen($d) === 10) {
        return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $d);
    }
    return trim((string) $tel);
}

/** 52998224725 → 529.982.247-25; 11222333000181 → 11.222.333/0001-81 */
function doc_formatar($doc): string
{
    $d = so_digitos($doc);
    if (strlen($d) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
    }
    if (strlen($d) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    }
    return (string) $doc;
}

// ---------------------------------------------------------------------
// Rotas e tratamento de erros (usado por index.php e plataforma.php)
// ---------------------------------------------------------------------
function url_inicio(): string
{
    return url(AREA === 'plataforma' ? 'tenants' : 'dashboard');
}

/**
 * Executa a rota ?p=<modulo>&a=<acao>: chama a função <modulo>_<acao>() de <pasta>/<modulo>.php.
 * $modulos é a lista fixa de módulos permitidos; tudo exige login, menos o módulo "login".
 */
function executar_rota(array $modulos, string $pasta, string $padrao): void
{
    $pagina = get('p', $padrao);
    $acao = get('a', 'index');
    try {
        if (!in_array($pagina, $modulos, true) || !preg_match('/^[a-z_]+$/', $acao)) {
            pagina_erro(404, 'Página não encontrada.');
        }
        if ($pagina !== 'login' && user() === null) {
            redirect(url('login'));
        }
        if (is_post()) {
            csrf_check();
        }
        if (!entrada_utf8_valida($_GET) || !entrada_utf8_valida($_POST)) {
            throw new UserError('Os dados enviados têm caracteres inválidos. Confira o texto digitado ou colado e tente de novo.');
        }
        $arquivo = "$pasta/$pagina.php";
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
}

/** Todo texto recebido precisa ser UTF-8 válido (o banco recusaria com erro 22021). */
function entrada_utf8_valida(array $dados): bool
{
    foreach ($dados as $k => $v) {
        if (!mb_check_encoding((string) $k, 'UTF-8')) {
            return false;
        }
        if (is_array($v) ? !entrada_utf8_valida($v) : !mb_check_encoding((string) $v, 'UTF-8')) {
            return false;
        }
    }
    return true;
}

function tratar_erro_usuario(string $msg): never
{
    if (!mb_check_encoding($msg, 'UTF-8')) {
        $msg = 'Os dados enviados têm caracteres inválidos.';
    }
    if (db_conectado() && db()->inTransaction()) {
        db()->rollBack();
    }
    if (is_post()) {
        // Senhas nunca vão para a sessão; texto com codificação inválida também não volta ao formulário
        $dados = entrada_utf8_valida($_POST)
            ? array_filter($_POST, fn($k) => $k !== '_csrf' && $k !== 'assinatura' && !str_contains((string) $k, 'senha'), ARRAY_FILTER_USE_KEY)
            : [];
        $_SESSION['old'] = $dados;
        flash($msg, 'erro');
        voltar(url_inicio());
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
        'kits_codigo_uk'       => 'Já existe um kit com este código.',
        'unidades_patrimonio_uk' => 'Já existe uma unidade com este número de patrimônio.',
        'locacao_unidades_na_rua_uk' => 'Esta unidade já está na rua em outra locação.',
        'manutencoes_unidade_aberta_uk' => 'Esta unidade já está em manutenção.',
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
    if (in_array($estado, ['23514', '22P02', '22007', '22008', '22003', '22021', '22001'], true)) {
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
    if (user() !== null && db_conectado() && function_exists('layout_start')) {
        try {
            layout_start($codigo === 404 ? 'Não encontrado' : 'Aviso');
            echo '<div class="card"><p>' . e($msg) . '</p><p><a class="btn" href="javascript:history.back()">Voltar</a> '
               . '<a class="btn btn-sec" href="' . e(url_inicio()) . '">Ir para o início</a></p></div>';
            layout_end();
            exit;
        } catch (Throwable) {
            // cai para a versão sem layout
        }
    }
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>LocGestor</title>'
       . '<link rel="stylesheet" href="assets/css/app.css"></head><body class="pagina-login"><div class="login-caixa">'
       . '<p>' . e($msg) . '</p><p><a class="btn" href="' . e(url_inicio()) . '">Voltar ao início</a></p></div></body></html>';
    exit;
}

// ---------------------------------------------------------------------
// Contexto da locadora (só no sistema das locadoras)
// ---------------------------------------------------------------------
require __DIR__ . '/tenant.php';

if (AREA === 'locadora' && !empty($_SESSION['tenant_id'])) {
    // Logo após conectar e ANTES de qualquer consulta de dados
    try {
        tenant_iniciar_sessao();
    } catch (PDOException $e) {
        log_app('ERRO BANCO', 'Falha ao iniciar o contexto da locadora: ' . $e->getMessage());
        http_response_code(503);
        exit('<p style="font-family:sans-serif">Não foi possível conectar ao banco de dados. '
           . 'Tente novamente em instantes.</p>');
    }
}

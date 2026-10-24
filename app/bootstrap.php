<?php
/*
 * LocGestor - inicialização comum: configuração, sessão, banco, helpers,
 * CSRF, autenticação, formatação e validações.
 *
 * O front controller define antes de incluir este arquivo:
 *   const AREA = 'locadora'   (index.php, conecta como locgestor_app)
 *   const AREA = 'plataforma' (plataforma.php, conecta como locgestor_plataforma)
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
// Cabeçalhos de segurança e sessão
// ---------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name(AREA === 'plataforma' ? 'locgestor_plataforma' : 'locgestor');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

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
        $c = config(AREA === 'plataforma' ? 'db_plataforma' : 'db_app');
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

function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
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

function money($v): string
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
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
    $host = parse_url($ref, PHP_URL_HOST);
    redirect($ref !== '' && $host === ($_SERVER['HTTP_HOST'] ?? '') ? $ref : $padrao);
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

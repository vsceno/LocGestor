<?php
/*
 * LocGestor - ponte de sessão entre este sistema e o Laravel (pasta laravel/), durante a migração.
 *
 * Os dois ficam em endereços diferentes e o navegador não deixa um ler o cookie do outro. Quem já está
 * logado num deles recebe uma "passagem" para entrar no outro sem digitar a senha de novo:
 *   - assinada com HMAC-SHA256 com o segredo 'ponte_segredo' (o mesmo no config.php e no .env do Laravel);
 *   - vale PONTE_VALIDADE segundos e só pode ser usada uma vez (o código fica registrado em storage/ponte);
 *   - leva o destinatário ('laravel' ou 'legado'): a passagem feita para um não é aceita pelo outro;
 *   - quem recebe confere de novo no banco se a locadora está ativa e em dia e se o usuário está ativo.
 * Sem 'ponte_segredo' ou 'laravel_url' no config.php, a ponte fica desligada e o menu usa só este sistema.
 */

declare(strict_types=1);

const PONTE_VALIDADE = 60;

function ponte_ligada(): bool
{
    return strlen((string) config('ponte_segredo', '')) >= 32 && (string) config('laravel_url', '') !== '';
}

function ponte_b64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Passagem para o Laravel com os dados da sessão atual. */
function ponte_gerar(string $destino): string
{
    $dados = [
        'para' => 'laravel',
        't'    => (string) $_SESSION['tenant_id'],
        'u'    => (string) $_SESSION['usuario']['id'],
        'e'    => (int) ($_SESSION['login_em'] ?? time()),
        'd'    => $destino,
        'x'    => time() + PONTE_VALIDADE,
        'n'    => bin2hex(random_bytes(16)),
    ];
    $corpo = ponte_b64(json_encode($dados));
    return $corpo . '.' . ponte_b64(hash_hmac('sha256', $corpo, (string) config('ponte_segredo'), true));
}

/** Confere a passagem vinda do Laravel. Devolve os dados ou null (assinatura, prazo, destinatário ou já usada). */
function ponte_validar(string $passagem): ?array
{
    if (!ponte_ligada() || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $passagem, $m)) {
        return null;
    }
    $esperada = ponte_b64(hash_hmac('sha256', $m[1], (string) config('ponte_segredo'), true));
    if (!hash_equals($esperada, $m[2])) {
        return null;
    }
    $d = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
    if (!is_array($d) || ($d['para'] ?? '') !== 'legado' || (int) ($d['x'] ?? 0) < time()
        || !is_uuid((string) ($d['t'] ?? '')) || !is_uuid((string) ($d['u'] ?? '')) || !preg_match('/^[0-9a-f]{32}$/', (string) ($d['n'] ?? ''))) {
        return null;
    }
    // Uso único: o código vira um arquivo; se já existe, a passagem já foi usada
    $pasta = arquivos_base() . '/ponte';
    if (!is_dir($pasta) && !@mkdir($pasta, 0770, true) && !is_dir($pasta)) {
        return null;
    }
    $f = @fopen("$pasta/{$d['n']}", 'x');
    if ($f === false) {
        return null;
    }
    fclose($f);
    foreach (glob("$pasta/*") ?: [] as $velho) {   // limpa códigos de passagens que já venceram
        if (filemtime($velho) < time() - 3600) {
            @unlink($velho);
        }
    }
    return $d;
}

/** Telas que já estão no Laravel: o menu deste sistema leva para lá (pela ponte) quando ela está ligada. */
const PONTE_TELAS_LARAVEL = ['clientes' => '/clientes', 'produtos' => '/produtos', 'locacoes' => '/locacoes', 'reservas' => '/reservas', 'orcamentos' => '/orcamentos', 'caixa' => '/caixa'];

function ponte_url_menu(string $modulo): ?string
{
    if (!isset(PONTE_TELAS_LARAVEL[$modulo]) || !ponte_ligada()) {
        return null;
    }
    return url('login', ['a' => 'ir', 'd' => PONTE_TELAS_LARAVEL[$modulo]]);
}

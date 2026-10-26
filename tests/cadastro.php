<?php
/**
 * LocGestor - testes do cadastro público de locadoras (cadastro.php).
 *
 *   php tests/cadastro.php
 *
 * Sobe um servidor embutido do PHP (127.0.0.1:8093) com o cadastro ligado só para o teste
 * (LOCGESTOR_CADASTRO_PUBLICO=1); o config.php não é alterado. As locadoras criadas usam o prefixo
 * teste-cad e são apagadas no fim. PGUSER/PGPASSWORD: superusuário para conferir e limpar.
 * Termina com código 1 se qualquer verificação falhar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal: php tests/cadastro.php\n");
}

const RAIZ_TESTE = __DIR__ . '/..';
const APP = 'http://127.0.0.1:8093';

$config = require RAIZ_TESTE . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'America/Fortaleza');
$c = $config['db_app'];
$admin = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']}", getenv('PGUSER') ?: 'postgres', getenv('PGPASSWORD') ?: '',
                 [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$admin->exec("SET TIME ZONE 'America/Fortaleza'");

$falhas = 0;
$total = 0;
function secao(string $t): void { echo "\n== $t\n"; }
function ok(bool $cond, string $desc, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    echo $cond ? "  [OK]     $desc\n" : "  [FALHOU] $desc" . ($detalhe !== '' ? "  ($detalhe)" : '') . "\n";
    $falhas += $cond ? 0 : 1;
}
function db(string $sql, array $p = []): PDOStatement
{
    global $admin;
    $st = $admin->prepare($sql);
    $st->execute($p);
    return $st;
}
function dbv(string $sql, array $p = [])
{
    $v = db($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}

/** Requisição com cookies. Devolve [codigo, corpo, local]. */
function http(string $url, ?array $post, string $cookies): array
{
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
                           CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies]);
    if ($post !== null) {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $r = (string) curl_exec($h);
    $tam = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(\S+)/mi', substr($r, 0, $tam), $m);
    return [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), substr($r, $tam), $m[1] ?? ''];
}
function texto(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES, 'UTF-8')));
}

/** Abre o formulário, espera o tempo mínimo e envia. Devolve [resposta do POST, cookies]. */
function cadastrar(array $campos, bool $esperar = true, ?string $ck = null): array
{
    $ck ??= tempnam(sys_get_temp_dir(), 'lgcad');
    preg_match('/name="_csrf" value="([^"]+)"/', http(APP . '/cadastro.php', null, $ck)[1], $m);
    if ($esperar) {
        sleep(3);
    }
    return [http(APP . '/cadastro.php', ['_csrf' => $m[1] ?? ''] + $campos, $ck), $ck];
}
function mensagem(string $ck): string
{
    preg_match_all('/class="flash flash-[a-z]+" role="alert">([^<]*)</', http(APP . '/cadastro.php', null, $ck)[1], $m);
    return html_entity_decode(implode(' | ', $m[1]), ENT_QUOTES, 'UTF-8');
}

// --- servidor com o cadastro ligado ---
$extras = [];
foreach (['pdo_pgsql', 'pgsql', 'curl', 'mbstring', 'openssl'] as $x) {
    if (extension_loaded($x)) {
        $p = proc_open([PHP_BINARY, '-r', "echo extension_loaded('$x') ? 1 : 0;"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        $tem = trim((string) stream_get_contents($pp[1]));
        proc_close($p);
        if ($tem !== '1') {
            array_push($extras, '-d', "extension=$x");
        }
    }
}
$nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$srv = proc_open(array_merge([PHP_BINARY], $extras, ['-S', '127.0.0.1:8093', '-t', RAIZ_TESTE]),
                 [['file', $nulo, 'r'], ['file', $nulo, 'w'], ['file', $nulo, 'w']], $pipes, RAIZ_TESTE,
                 array_merge(getenv(), ['LOCGESTOR_CADASTRO_PUBLICO' => '1']));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8093, $en, $es, 0.2); $i++) {
    usleep(100000);
}

$chaveIp = hash('sha256', 'cadastro|ip|127.0.0.1');
function limpar(): void
{
    global $chaveIp;
    $em = "(SELECT id FROM tenants WHERE slug LIKE 'teste-cad%')";
    db("SELECT set_config('app.auditoria', 'off', false)");
    foreach (['auditoria', 'usuarios', 'categorias', 'empresa', 'tenant_sequencias'] as $t) {
        db("DELETE FROM $t WHERE tenant_id IN $em");
    }
    db("DELETE FROM tenants WHERE slug LIKE 'teste-cad%'");
    db('DELETE FROM login_tentativas WHERE chave = :c', ['c' => $chaveIp]);
}
limpar();
register_shutdown_function(function () use (&$srv) {
    try {
        limpar();
        echo "\n(Limpeza concluída: locadoras de teste removidas.)\n";
    } catch (Throwable $e) {
        echo "\n*** Falha na limpeza: {$e->getMessage()}\n";
    }
    if (is_resource($srv)) {
        proc_terminate($srv);
    }
});

echo "LocGestor - testes do cadastro público\n" . str_repeat('=', 60) . "\n";
$valido = fn(string $slug) => ['nome' => 'TESTE Locadora ' . $slug, 'slug' => $slug, 'email' => 'contato@teste.com.br', 'telefone' => '(85) 99999-1234',
                               'cnpj' => '', 'admin_nome' => 'Fulano de Tal', 'admin_login' => 'dono', 'senha' => 'SenhaForte9',
                               'senha_confirma' => 'SenhaForte9', 'aceite' => '1', 'site' => ''];
$existe = fn(string $slug) => dbv('SELECT count(*) FROM tenants WHERE slug = :s', ['s' => $slug]) == 1;

// =====================================================================
secao('Proteções contra robôs e validação');
$ck = tempnam(sys_get_temp_dir(), 'lgcad');
$form = http(APP . '/cadastro.php', null, $ck);
ok($form[0] === 200 && str_contains($form[1], 'Teste grátis por 14 dias') && str_contains($form[1], 'name="site"'), 'formulário aberto (com o campo-isca)');
[$r, $ck] = cadastrar($valido('teste-cad-rapido'), false);
ok(str_contains(mensagem($ck), 'envie o formulário de novo') && !$existe('teste-cad-rapido'), 'envio rápido demais (robô) é recusado');
[$r, $ck] = cadastrar(['site' => 'http://spam.example'] + $valido('teste-cad-isca'));
ok(str_contains(mensagem($ck), 'Não foi possível concluir') && !$existe('teste-cad-isca'), 'campo-isca preenchido (robô) é recusado');
[$r, $ck] = cadastrar(['slug' => '-ruim-', 'senha_confirma' => 'outra', 'email' => 'x', 'aceite' => ''] + $valido('x'));
$m = mensagem($ck);
ok(str_contains($m, 'Identificador inválido') && str_contains($m, 'e-mail válido') && str_contains($m, 'não confere') && str_contains($m, 'termos de uso'),
   'dados inválidos: todas as mensagens de uma vez', $m);
$volta = http(APP . '/cadastro.php', null, $ck)[1];
ok(!str_contains($volta, 'SenhaForte9'), 'a senha digitada não volta para o formulário');

// =====================================================================
secao('Cadastro válido');
[$r, $ck] = cadastrar($valido('teste-cad-um'));
$pronto = http(APP . '/' . ltrim($r[2], '/'), null, $ck);
$t = db("SELECT t.*, p.nome AS plano_nome FROM tenants t JOIN planos p ON p.id = t.plano_id WHERE slug = 'teste-cad-um'")->fetch();
$barato = dbv('SELECT nome FROM planos WHERE ativo ORDER BY preco_mensal, nome LIMIT 1');
ok($r[2] === 'cadastro.php?pronto=1' && str_contains(texto($pronto[1]), 'Empresa teste-cad-um Usuário dono Teste grátis até ' . date('d/m/Y', strtotime('+14 days'))),
   'redireciona para a tela de sucesso com os dados de acesso', $r[2] . ' / ' . mb_substr(texto($pronto[1]), 0, 300));
ok($t && $t['status'] === 'ativa' && $t['vencimento'] === date('Y-m-d', strtotime('+14 days')) && $t['plano_nome'] === $barato
   && $t['email_contato'] === 'contato@teste.com.br' && (int) $t['carencia_dias'] === 5, "locadora ativa, teste de 14 dias no plano $barato");
$u = db("SELECT login, perfil, trocar_senha FROM usuarios WHERE tenant_id = :t", ['t' => $t['id'] ?? ''])->fetchAll();
ok(count($u) === 1 && $u[0]['login'] === 'dono' && $u[0]['perfil'] === 'admin' && $u[0]['trocar_senha'] === false
   && dbv('SELECT count(*) FROM categorias WHERE tenant_id = :t', ['t' => $t['id']]) == 5 && dbv('SELECT count(*) FROM empresa WHERE tenant_id = :t', ['t' => $t['id']]) == 1,
   'admin com a senha escolhida (sem troca obrigatória), empresa e categorias padrão');
ok(http(APP . '/cadastro.php?pronto=1', null, $ck)[1] !== $pronto[1] && !str_contains(http(APP . '/cadastro.php?pronto=1', null, $ck)[1], 'Teste grátis até'),
   'a tela de sucesso aparece uma única vez');
$ckLogin = tempnam(sys_get_temp_dir(), 'lgcad');
preg_match('/name="_csrf" value="([^"]+)"/', http(APP . '/index.php?p=login', null, $ckLogin)[1], $m);
$l = http(APP . '/index.php?p=login&a=entrar', ['_csrf' => $m[1], 'empresa' => 'teste-cad-um', 'login' => 'dono', 'senha' => 'SenhaForte9'], $ckLogin);
ok(str_contains($l[2], 'p=dashboard'), 'o dono entra no sistema com a senha que escolheu');
ok(str_contains(http(APP . '/index.php?p=login', null, tempnam(sys_get_temp_dir(), 'lgcad'))[1], 'href="cadastro.php"'), 'login mostra o link "Teste grátis"');
[$r, $ck] = cadastrar($valido('teste-cad-um'));
ok(mensagem($ck) === 'Já existe uma locadora com este identificador.', 'identificador repetido é recusado');

// =====================================================================
secao('Limite por IP');
cadastrar($valido('teste-cad-dois'));
cadastrar($valido('teste-cad-tres'));
[$r, $ck] = cadastrar($valido('teste-cad-quatro'));
ok($existe('teste-cad-dois') && $existe('teste-cad-tres') && !$existe('teste-cad-quatro') && str_contains(mensagem($ck), 'Muitos cadastros'),
   '3 cadastros por IP por hora; o 4º é recusado');
ok(dbv('SELECT count(*) FROM login_tentativas WHERE chave = :c', ['c' => $chaveIp]) == 3
   && dbv("SELECT count(*) FROM login_tentativas WHERE chave LIKE '127.%'") == 0, 'o IP é guardado só como hash');

echo "\n" . str_repeat('=', 60) . "\n";
echo $falhas === 0 ? "RESULTADO: todos os $total testes do cadastro passaram.\n" : "RESULTADO: $falhas de $total verificação(ões) FALHARAM.\n";
exit($falhas === 0 ? 0 : 1);

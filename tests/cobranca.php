<?php
/**
 * LocGestor - testes da cobrança automática das mensalidades (Asaas), com o simulador da API.
 *
 *   php tests/cobranca.php
 *
 * Sobe dois servidores embutidos do PHP: o simulador do Asaas (127.0.0.1:8091) e o sistema
 * (127.0.0.1:8092) apontando para ele. Usa as locadoras de teste teste-cob-*, apagadas no fim.
 * Variáveis opcionais: PGUSER / PGPASSWORD (superusuário, para preparar e conferir) e
 * LOCGESTOR_SUPORTE_SENHA (sem ela, os testes das telas do painel da plataforma são pulados).
 * Termina com código 1 se qualquer verificação falhar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal: php tests/cobranca.php\n");
}

const RAIZ_TESTE = __DIR__ . '/..';
const SIM = 'http://127.0.0.1:8091';
const APP = 'http://127.0.0.1:8092';
const TOKEN = 'token-do-webhook-de-teste';

$config = require RAIZ_TESTE . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'America/Fortaleza');
$c = $config['db_app'];
$dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']}";
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsn, getenv('PGUSER') ?: 'postgres', getenv('PGPASSWORD') ?: '', $opts);
$admin->exec("SET TIME ZONE 'America/Fortaleza'");
$app = new PDO($dsn, $c['user'], $c['password'], $opts);   // como o sistema da locadora (RLS)

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
function dia(int $n): string { return date('Y-m-d', strtotime("$n days")); }

/** Requisição HTTP simples (cookies por arquivo). Devolve [codigo, corpo, local]. */
function http(string $url, ?array $post = null, array $cab = [], ?string $cookies = null, bool $json = false): array
{
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
                           CURLOPT_HTTPHEADER => $cab]);
    if ($cookies !== null) {
        curl_setopt_array($h, [CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies]);
    }
    if ($post !== null) {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, $json ? json_encode($post) : http_build_query($post));
    }
    $r = (string) curl_exec($h);
    $tam = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(\S+)/mi', substr($r, 0, $tam), $m);
    return [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), substr($r, $tam), $m[1] ?? ''];
}
function sim(string $rota): array { return json_decode(http(SIM . $rota, [])[1], true) ?? []; }
function estado(): array { return json_decode(http(SIM . '/_teste/estado')[1], true) ?? []; }
/** Envia um formulário: pega o token CSRF da página e posta. */
function enviar(string $cookies, string $pagina, string $acao, array $campos): array
{
    preg_match('/name="_csrf" value="([^"]+)"/', http(APP . "/$pagina", null, [], $cookies)[1], $m);
    return http(APP . "/$acao", ['_csrf' => $m[1] ?? ''] + $campos, [], $cookies);
}
function mensagem(string $cookies, string $pagina): string
{
    preg_match_all('/class="flash flash-[a-z]+" role="alert">([^<]*)</', http(APP . "/$pagina", null, [], $cookies)[1], $m);
    return html_entity_decode(implode(' | ', $m[1]), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// Servidores: simulador e sistema (com o Asaas apontando para o simulador)
// ---------------------------------------------------------------------
// Extensões carregadas aqui por -d (e não pelo php.ini) também precisam ir para os servidores filhos
$extras = [];
foreach (['pdo_pgsql', 'pgsql', 'curl', 'mbstring', 'gd', 'openssl'] as $x) {
    if (extension_loaded($x)) {
        $p = proc_open([PHP_BINARY, '-r', "echo extension_loaded('$x') ? 1 : 0;"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        $tem = trim((string) stream_get_contents($pp[1]));
        proc_close($p);
        if ($tem !== '1') {
            array_push($extras, '-d', "extension=$x");
        }
    }
}
$ambiente = array_merge(getenv(), ['LOCGESTOR_ASAAS_URL' => SIM . '/v3', 'LOCGESTOR_ASAAS_CHAVE' => 'chave-teste',
                                   'LOCGESTOR_ASAAS_WEBHOOK_TOKEN' => TOKEN]);
$nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$io = [['file', $nulo, 'r'], ['file', $nulo, 'w'], ['file', $nulo, 'w']];
$procs = [
    proc_open(array_merge([PHP_BINARY], $extras, ['-S', '127.0.0.1:8091', __DIR__ . '/asaas_simulador.php']), $io, $p1, RAIZ_TESTE, $ambiente),
    proc_open(array_merge([PHP_BINARY], $extras, ['-S', '127.0.0.1:8092', '-t', RAIZ_TESTE]), $io, $p2, RAIZ_TESTE, $ambiente),
];
foreach ([8091, 8092] as $porta) {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $porta, $en, $es, 0.2); $i++) {
        usleep(100000);
    }
}
http(SIM . '/_teste/zerar', []);

/** Roda a rotina diária com o Asaas do simulador. Devolve [código de saída, saída]. */
function rotina(): array
{
    global $extras, $ambiente;
    $p = proc_open(array_merge([PHP_BINARY], $extras, [RAIZ_TESTE . '/tarefas/diaria.php']), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                   $pipes, RAIZ_TESTE, $ambiente);
    $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $saida];
}

// ---------------------------------------------------------------------
// Locadoras de teste e limpeza
// ---------------------------------------------------------------------
$SLUGS = ['teste-cob-a', 'teste-cob-b', 'teste-cob-c', 'teste-cob-d', 'teste-cob-e'];
function limpar(): void
{
    global $SLUGS;
    $em = "(SELECT id FROM tenants WHERE slug IN ('" . implode("','", $SLUGS) . "'))";
    db("DELETE FROM assinatura_cobrancas WHERE locadora_id IN $em");
    db("DELETE FROM assinatura_pagamentos WHERE locadora_id IN $em");
    foreach (['usuarios', 'categorias', 'empresa', 'tenant_sequencias'] as $t) {
        db("DELETE FROM $t WHERE tenant_id IN $em");
    }
    db("DELETE FROM tenants WHERE slug IN ('" . implode("','", $SLUGS) . "')");
    db("DELETE FROM gateway_eventos WHERE id LIKE 'evt_teste_%'");
    db('DELETE FROM login_tentativas');
}
limpar();
register_shutdown_function(function () use (&$procs) {
    try {
        limpar();
        echo "\n(Limpeza concluída: locadoras de teste removidas.)\n";
    } catch (Throwable $e) {
        echo "\n*** Falha na limpeza: {$e->getMessage()}\n";
    }
    foreach ($procs as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
        }
    }
});

$basico = dbv("SELECT id FROM planos WHERE preco_mensal > 0 ORDER BY preco_mensal LIMIT 1");
$preco = dbv('SELECT preco_mensal FROM planos WHERE id = :id', ['id' => $basico]);
$nova = function (string $slug, ?string $venc, bool $auto, string $cnpj = '11222333000181') use ($basico) {
    return dbv("INSERT INTO tenants (slug, nome, cnpj, email_contato, telefone, plano_id, plano, vencimento, carencia_dias, cobranca_automatica)
                VALUES (:s, :n, :c, 'financeiro@teste.com.br', '85999990000', :p, 'Básico', :v, 5, :a) RETURNING id",
               ['s' => $slug, 'n' => 'TESTE ' . strtoupper($slug), 'c' => $cnpj, 'p' => $basico, 'v' => $venc, 'a' => $auto ? 'true' : 'false']);
};
$A = $nova('teste-cob-a', dia(5), true);       // vence em 5 dias: cobra
$B = $nova('teste-cob-b', dia(3), false);      // cobrança automática desligada
$C = $nova('teste-cob-c', dia(25), true);      // vence longe: ainda não
$D = $nova('teste-cob-d', dia(-10), true);     // vencida além da carência: suspende e cobra para hoje
$E = $nova('teste-cob-e', dia(2), true, '');   // sem CNPJ: erro só dela
// Admin da locadora A (para o botão "Pagar agora")
$admin->beginTransaction();
db("SELECT set_config('app.tenant_id', :t, true)", ['t' => $A]);
db("SELECT provisionar_dados_padrao('TESTE Cobrança A')");
db("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Admin A', 'admin', :h, 'admin')",
   ['h' => password_hash('SenhaTeste123', PASSWORD_DEFAULT)]);
$admin->commit();

echo "LocGestor - testes da cobrança automática (Asaas simulado)\n" . str_repeat('=', 60) . "\n";

// =====================================================================
secao('Rotina diária: gera as cobranças');
[$cod, $saida] = rotina();
$cobA = db('SELECT * FROM assinatura_cobrancas WHERE locadora_id = :l', ['l' => $A])->fetchAll();
$cobD = db('SELECT * FROM assinatura_cobrancas WHERE locadora_id = :l', ['l' => $D])->fetchAll();
$est = estado();
ok($cod === 1 && str_contains($saida, 'ERRO teste-cob-e') && str_contains($saida, 'sem CNPJ'),
   'locadora sem CNPJ dá erro (código 1) sem impedir as outras', trim($saida));
ok(count($cobA) === 1 && $cobA[0]['vencimento'] === dia(5) && $cobA[0]['status'] === 'pendente' && $cobA[0]['valor'] === $preco
   && str_starts_with((string) $cobA[0]['gateway_id'], 'pay_') && str_starts_with($cobA[0]['link'], 'https://'),
   'A: cobrança pendente com id e link do Asaas, valor do plano', json_encode($cobA));
$pA = $est['payments'][$cobA[0]['gateway_id'] ?? ''] ?? [];
$cliA = dbv('SELECT asaas_cliente_id FROM tenants WHERE id = :id', ['id' => $A]);
ok(($pA['externalReference'] ?? '') === ($cobA[0]['id'] ?? '-') && ($pA['dueDate'] ?? '') === dia(5) && ($pA['billingType'] ?? '') === 'UNDEFINED'
   && ($pA['customer'] ?? '') === $cliA && ($est['customers'][$cliA]['cpfCnpj'] ?? '') === '11222333000181'
   && ($est['customers'][$cliA]['externalReference'] ?? '') === $A, 'no Asaas: cliente com CNPJ e cobrança com a nossa referência e o vencimento');
ok(count($cobD) === 1 && $cobD[0]['vencimento'] === dia(-10) && ($est['payments'][$cobD[0]['gateway_id'] ?? '']['dueDate'] ?? '') === dia(0),
   'D (vencida): cobrança do mês vencido emitida para hoje (o Asaas não aceita data passada)');
ok(dbv("SELECT status || '/' || motivo_suspensao FROM tenants WHERE id = :id", ['id' => $D]) === 'suspensa/inadimplencia',
   'D: suspensa por inadimplência pela rotina');
ok(dbv('SELECT count(*) FROM assinatura_cobrancas WHERE locadora_id IN (:b, :c, :e)', ['b' => $B, 'c' => $C, 'e' => $E]) == 0,
   'B (desligada), C (vence longe) e E (sem CNPJ) sem cobrança');
rotina();
ok(dbv('SELECT count(*) FROM assinatura_cobrancas WHERE locadora_id IN (:a, :d)', ['a' => $A, 'd' => $D]) == 2
   && count(estado()['payments']) === 2 && count(estado()['customers']) === 2, 'rodar de novo não duplica cobrança nem cliente');

// =====================================================================
secao('Sistema da locadora: botão "Pagar agora" e isolamento');
$ckA = tempnam(sys_get_temp_dir(), 'lgc');
enviar($ckA, 'index.php?p=login', 'index.php?p=login&a=entrar', ['empresa' => 'teste-cob-a', 'login' => 'admin', 'senha' => 'SenhaTeste123']);
$painel = http(APP . '/index.php?p=dashboard', null, [], $ckA)[1];
ok(str_contains($painel, 'Pagar agora') && str_contains($painel, 'href="' . htmlspecialchars($cobA[0]['link']) . '"'),
   'admin da A vê "Pagar agora" com o link da fatura dela');
$ckAlfa = tempnam(sys_get_temp_dir(), 'lgc');
enviar($ckAlfa, 'index.php?p=login', 'index.php?p=login&a=entrar', ['empresa' => 'alfa-locacoes', 'login' => 'admin', 'senha' => 'admin123']);
$pAlfa = http(APP . '/index.php?p=dashboard', null, [], $ckAlfa)[1];
ok(str_contains($pAlfa, 'Alfa') && !str_contains($pAlfa, 'Pagar agora') && !str_contains($pAlfa, $cobA[0]['link']),
   'Alfa não vê a cobrança da A');
$ctx = fn(?string $t) => $app->prepare("SELECT set_config('app.tenant_id', :t, false)")->execute(['t' => $t ?? '']);
$conta = fn() => (int) $app->query('SELECT count(*) FROM assinatura_cobrancas')->fetchColumn();
$ctx($A);
$vA = $conta();
$ctx(dbv("SELECT id FROM tenants WHERE slug = 'alfa-locacoes'"));
$vAlfa = $conta();
$ctx(null);
$vNada = $conta();
ok($vA === 1 && $vAlfa === 0 && $vNada === 0, "RLS: locgestor_app vê só a cobrança da própria locadora (A: $vA, Alfa: $vAlfa, sem contexto: $vNada)");
$negado = function (string $sql) use ($app): bool {
    try {
        $app->exec($sql);
        return false;
    } catch (PDOException $e) {
        return $e->getCode() === '42501';
    }
};
$ctx($A);
ok($negado('SELECT gateway_id FROM assinatura_cobrancas') && $negado("UPDATE assinatura_cobrancas SET status = 'paga'")
   && $negado("INSERT INTO assinatura_cobrancas (locadora_id, vencimento, valor) VALUES ('$A', CURRENT_DATE, 1)")
   && $negado('SELECT * FROM gateway_eventos'), 'locgestor_app não lê o id do gateway nem altera cobranças (permissão negada)');
$ctx(null);

// =====================================================================
secao('Webhook do Asaas');
$hook = fn(array $aviso, ?string $token = TOKEN) => http(APP . '/webhook_asaas.php', $aviso,
    array_merge(['Content-Type: application/json'], $token === null ? [] : ["asaas-access-token: $token"]), null, true);
$payD = $cobD[0]['gateway_id'];
$aviso = ['id' => 'evt_teste_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => $payD, 'status' => 'RECEIVED', 'value' => 99999]];
ok($hook($aviso, null)[0] === 401 && $hook($aviso, 'errado')[0] === 401, 'sem token ou com token errado: 401');
ok(http(APP . '/webhook_asaas.php')[0] === 405, 'GET no webhook: 405');
$r = $hook($aviso);
ok($r[0] === 200 && str_contains($r[1], 'pendente') && dbv('SELECT status FROM assinatura_cobrancas WHERE gateway_id = :g', ['g' => $payD]) === 'pendente'
   && dbv('SELECT count(*) FROM assinatura_pagamentos WHERE locadora_id = :l', ['l' => $D]) == 0,
   'aviso "pago" de cobrança que o Asaas diz estar pendente não dá baixa (a API é consultada)', $r[1]);
db("DELETE FROM gateway_eventos WHERE id = 'evt_teste_1'");
http(SIM . "/_teste/pagar/$payD?forma=PIX", []);
$vencD = dbv('SELECT vencimento FROM tenants WHERE id = :id', ['id' => $D]);
$r = $hook($aviso);
$pg = db('SELECT * FROM assinatura_pagamentos WHERE locadora_id = :l', ['l' => $D])->fetchAll();
ok($r[0] === 200 && str_contains($r[1], 'paga') && count($pg) === 1 && $pg[0]['forma'] === 'PIX' && $pg[0]['valor'] === $preco
   && $pg[0]['meses'] === 1 && $pg[0]['usuario_id'] === null && $pg[0]['obs'] === "Asaas $payD",
   'pago no Asaas: baixa com valor da API (não o do aviso), forma PIX e 1 mês', $r[1] . ' ' . json_encode($pg));
$t = db('SELECT status, motivo_suspensao, vencimento FROM tenants WHERE id = :id', ['id' => $D])->fetch();
ok($t['status'] === 'ativa' && $t['motivo_suspensao'] === '' && $t['vencimento'] === date('Y-m-d', strtotime("$vencD +1 month"))
   && dbv('SELECT status FROM assinatura_cobrancas WHERE gateway_id = :g', ['g' => $payD]) === 'paga',
   'D reativada, vencimento +1 mês e cobrança paga', json_encode($t));
$r = $hook($aviso);
$r2 = $hook(['id' => 'evt_teste_2', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => $payD]]);
ok(str_contains($r[1], 'já processado') && $r2[0] === 200 && dbv('SELECT count(*) FROM assinatura_pagamentos WHERE locadora_id = :l', ['l' => $D]) == 1,
   'aviso repetido ou outro evento da mesma cobrança: sem baixa em dobro');
$r = $hook(['id' => 'evt_teste_3', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_naoexiste']]);
ok($r[0] === 200 && str_contains($r[1], 'desconhecida'), 'cobrança que não é do LocGestor: ignorada (200)');
ok($hook(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => '../../v3/customers']])[0] === 200
   && !in_array('GET /v3/customers', array_slice(estado()['chamadas'], -1), true), 'id malicioso no aviso não chega à API');

// =====================================================================
secao('Conferência pela rotina (sem webhook) e falhas do Asaas');
http(SIM . "/_teste/pagar/{$cobA[0]['gateway_id']}?forma=CREDIT_CARD", []);
[$cod, $saida] = rotina();
$pgA = db('SELECT forma, vencimento_novo FROM assinatura_pagamentos WHERE locadora_id = :l', ['l' => $A])->fetchAll();
ok(str_contains($saida, 'teste-cob-a: cobrança paga') && count($pgA) === 1 && $pgA[0]['forma'] === 'Cartão de crédito'
   && dbv('SELECT vencimento FROM tenants WHERE id = :id', ['id' => $A]) === date('Y-m-d', strtotime(dia(5) . ' +1 month')),
   'A pagou com cartão: a rotina dá baixa e o vencimento avança (a próxima cobrança fica para perto dele)', trim($saida));
ok(dbv("SELECT count(*) FROM assinatura_cobrancas WHERE locadora_id = :l", ['l' => $A]) == 1, 'A: nenhuma cobrança nova com o vencimento longe');

// Falha de rede: criada lá, resposta perdida → a reserva fica e é vinculada depois
db("UPDATE tenants SET cnpj = '11222333000181' WHERE id = :id", ['id' => $E]);
http(SIM . '/_teste/modo?payments=cair', []);
[$cod, $saida] = rotina();
$cobE = db('SELECT * FROM assinatura_cobrancas WHERE locadora_id = :l', ['l' => $E])->fetchAll();
ok($cod === 1 && count($cobE) === 1 && $cobE[0]['gateway_id'] === null, 'resposta perdida (502): a reserva do vencimento fica, sem id', trim($saida));
db("UPDATE assinatura_cobrancas SET criado_em = now() - INTERVAL '5 minutes' WHERE locadora_id = :l", ['l' => $E]);
[$cod, $saida] = rotina();
$cobE = db('SELECT * FROM assinatura_cobrancas WHERE locadora_id = :l', ['l' => $E])->fetchAll();
$pagE = array_filter(estado()['payments'], fn($p) => $p['externalReference'] === ($cobE[0]['id'] ?? '-'));
ok($cod === 0 && count($cobE) === 1 && str_starts_with((string) $cobE[0]['gateway_id'], 'pay_') && count($pagE) === 1,
   'rotina seguinte acha a cobrança pela referência e vincula (sem cobrar duas vezes)', trim($saida));

// Recusa (400): libera o vencimento para tentar de novo
db("UPDATE tenants SET cobranca_automatica = true, cnpj = '11222333000181' WHERE id = :id", ['id' => $B]);
http(SIM . '/_teste/modo?payments=recusar', []);
[$cod, $saida] = rotina();
ok($cod === 1 && str_contains($saida, 'O valor da cobrança é inválido') && dbv('SELECT count(*) FROM assinatura_cobrancas WHERE locadora_id = :l', ['l' => $B]) == 0,
   'recusa do Asaas (400): erro no relatório e vencimento liberado', trim($saida));
[$cod] = rotina();
ok($cod === 0 && dbv("SELECT count(*) FROM assinatura_cobrancas WHERE locadora_id = :l AND status = 'pendente'", ['l' => $B]) == 1,
   'na rotina seguinte a cobrança da B é gerada');
ok(!preg_match('/chave-teste/', (string) @file_get_contents(RAIZ_TESTE . '/logs/app.log')), 'a chave da API não aparece no log');

// =====================================================================
secao('Painel da plataforma');
$senha = getenv('LOCGESTOR_SUPORTE_SENHA');
if (!$senha) {
    echo "  (pulado: defina LOCGESTOR_SUPORTE_SENHA para testar as telas do painel)\n";
} else {
    $ck = tempnam(sys_get_temp_dir(), 'lgc');
    enviar($ck, 'plataforma.php?p=login', 'plataforma.php?p=login&a=entrar', ['login' => 'suporte', 'senha' => $senha]);
    $ed = "plataforma.php?p=tenants&a=editar&id=$B";
    $tela = http(APP . "/$ed", null, [], $ck)[1];
    ok(str_contains($tela, 'Cobrança automática (Asaas)') && str_contains($tela, 'sandbox (testes)')
       && str_contains($tela, '>pendente<'), 'tela da locadora mostra o cartão do Asaas com a cobrança pendente');
    $cobB = dbv("SELECT id FROM assinatura_cobrancas WHERE locadora_id = :l AND status = 'pendente'", ['l' => $B]);
    $payB = dbv('SELECT gateway_id FROM assinatura_cobrancas WHERE id = :id', ['id' => $cobB]);
    enviar($ck, $ed, 'plataforma.php?p=tenants&a=cobranca_cancelar', ['id' => $B, 'cobranca' => $cobB]);
    ok(mensagem($ck, $ed) === 'Cobrança cancelada.' && dbv('SELECT status FROM assinatura_cobrancas WHERE id = :id', ['id' => $cobB]) === 'cancelada'
       && (estado()['payments'][$payB]['deleted'] ?? false) === true, 'cancelar: cancelada aqui e removida no Asaas');
    enviar($ck, $ed, 'plataforma.php?p=tenants&a=cobranca_cancelar', ['id' => $A, 'cobranca' => $cobB]);
    ok(mensagem($ck, $ed) === 'Cobrança não encontrada.', 'cancelar cobrança informando outra locadora é recusado');
    enviar($ck, $ed, 'plataforma.php?p=tenants&a=cobranca_gerar', ['id' => $B]);
    ok(str_contains(mensagem($ck, $ed), 'disponível no Asaas')
       && dbv("SELECT count(*) FROM assinatura_cobrancas WHERE locadora_id = :l AND status = 'pendente'", ['l' => $B]) == 1,
       '"Gerar cobrança" cria outra para o mesmo vencimento depois do cancelamento');
    $payB2 = dbv("SELECT gateway_id FROM assinatura_cobrancas WHERE locadora_id = :l AND status = 'pendente'", ['l' => $B]);
    http(SIM . "/_teste/pagar/$payB2?forma=BOLETO", []);
    enviar($ck, $ed, 'plataforma.php?p=tenants&a=cobranca_sincronizar', ['id' => $B]);
    ok(mensagem($ck, $ed) === '1 pagamento(s) recebido(s) e registrado(s).'
       && dbv('SELECT forma FROM assinatura_pagamentos WHERE locadora_id = :l', ['l' => $B]) === 'Boleto', '"Conferir pagamentos" dá baixa no boleto pago');
    enviar($ck, $ed, 'plataforma.php?p=tenants&a=estornar', ['id' => $B]);
    ok(str_contains(mensagem($ck, $ed), 'recebido pelo Asaas e não é estornado aqui')
       && dbv('SELECT count(*) FROM assinatura_pagamentos WHERE locadora_id = :l', ['l' => $B]) == 1, 'pagamento do Asaas não é estornado pelo painel');
    db("UPDATE tenants SET cnpj = '' WHERE id = :id", ['id' => $C]);
    enviar($ck, "plataforma.php?p=tenants&a=editar&id=$C", 'plataforma.php?p=tenants&a=cobranca_config', ['id' => $C, 'cobranca_automatica' => '1']);
    ok(str_contains(mensagem($ck, "plataforma.php?p=tenants&a=editar&id=$C"), 'precisa de CNPJ'), 'ligar cobrança automática sem CNPJ é recusado');
    enviar($ck, $ed, 'plataforma.php?p=tenants&a=cobranca_config', ['id' => $B]);
    ok(dbv('SELECT cobranca_automatica::text FROM tenants WHERE id = :id', ['id' => $B]) === 'false', 'desligar a cobrança automática');
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $falhas === 0 ? "RESULTADO: todos os $total testes de cobrança passaram.\n" : "RESULTADO: $falhas de $total verificação(ões) FALHARAM.\n";
exit($falhas === 0 ? 0 : 1);

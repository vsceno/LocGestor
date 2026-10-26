<?php
/*
 * LocGestor - testes funcionais pelas telas (HTTP), de ponta a ponta.
 *
 * Uso:   php tests/funcional.php
 *
 * Precisa do sistema no ar e das locadoras de demonstração (03_demo.sql).
 * Variáveis de ambiente (opcionais):
 *   LOCGESTOR_URL            endereço do sistema      (padrão: http://localhost/locgestor)
 *   LOCGESTOR_SUPORTE_SENHA  senha do usuário "suporte" (sem ela, os testes do painel da plataforma são pulados)
 *   PGUSER / PGPASSWORD      superusuário do banco, usado só para conferir resultados e limpar
 *                            (padrão: postgres, sem senha)
 *
 * Tudo o que os testes criam leva o prefixo TESTE/teste e é apagado no fim,
 * mesmo se algum teste falhar. Termina com código 1 se qualquer verificação falhar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal: php tests/funcional.php\n");
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "A extensão curl do PHP é necessária.\n");
    exit(1);
}

$BASE = rtrim(getenv('LOCGESTOR_URL') ?: 'http://localhost/locgestor', '/');
$config = require __DIR__ . '/../config.php';
$cfg = $config['db_app'];
// "Hoje" do teste = "hoje" do sistema (servidores de CI rodam em UTC)
date_default_timezone_set($config['timezone'] ?? 'America/Fortaleza');
$admin = new PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}",
                 getenv('PGUSER') ?: 'postgres', getenv('PGPASSWORD') ?: '',
                 [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$admin->exec("SET TIME ZONE 'America/Fortaleza'");

// ---------------------------------------------------------------------
// Infraestrutura
// ---------------------------------------------------------------------
$falhas = 0;
$total = 0;

function secao(string $t): void
{
    echo "\n== $t\n";
}

function ok(bool $cond, string $desc, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if ($cond) {
        echo "  [OK]     $desc\n";
    } else {
        $falhas++;
        echo "  [FALHOU] $desc" . ($detalhe !== '' ? "  ($detalhe)" : '') . "\n";
    }
}

/** Consulta como superusuário (enxerga todas as locadoras): só para conferir e limpar. */
function db(string $sql, array $p = [])
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

function dia(int $n): string
{
    return date('Y-m-d', strtotime("$n days"));
}

/** Um navegador com cookies próprios (uma sessão). */
final class Navegador
{
    private string $cookies;
    public string $ultimaPagina = '';

    public function __construct(private string $base)
    {
        $this->cookies = tempnam(sys_get_temp_dir(), 'lgck');
    }

    public function __destruct()
    {
        @unlink($this->cookies);
    }

    /** @return array{codigo:int, local:string, corpo:string, cabecalhos:string} */
    public function req(string $caminho, ?array $post = null, string $referer = ''): array
    {
        $c = curl_init($this->base . '/' . ltrim($caminho, '/'));
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookies,
            CURLOPT_COOKIEFILE     => $this->cookies,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($referer !== '') {
            curl_setopt($c, CURLOPT_REFERER, $this->base . '/' . ltrim($referer, '/'));
        }
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $r = curl_exec($c);
        $tamCab = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        return [
            'codigo'     => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE),
            'local'      => (string) curl_getinfo($c, CURLINFO_REDIRECT_URL),
            'cabecalhos' => substr((string) $r, 0, $tamCab),
            'corpo'      => substr((string) $r, $tamCab),
        ];
    }

    public function get(string $caminho): array
    {
        $r = $this->req($caminho);
        $this->ultimaPagina = $r['corpo'];
        return $r;
    }

    /** Abre $pagina (para pegar o token CSRF, como faria o navegador) e envia o formulário para $acao. */
    public function enviar(string $pagina, string $acao, array $campos): array
    {
        $tela = $this->req($pagina)['corpo'];
        preg_match('/name="_csrf" value="([^"]+)"/', $tela, $m);
        return $this->req($acao, ['_csrf' => $m[1] ?? ''] + $campos, $pagina);
    }

    /** Como enviar(), mas em multipart (com arquivos). $campos já "achatados" (ex.: 'estado[uuid]' => 'ok'). */
    public function enviarArquivos(string $pagina, string $acao, array $campos, array $arquivos): array
    {
        $tela = $this->req($pagina)['corpo'];
        preg_match('/name="_csrf" value="([^"]+)"/', $tela, $m);
        $c = curl_init($this->base . '/' . ltrim($acao, '/'));
        $dados = ['_csrf' => $m[1] ?? ''] + $campos;
        foreach (array_values($arquivos) as $i => $f) {
            $dados["fotos[$i]"] = new CURLFile($f['caminho'], $f['tipo'], $f['nome']);
        }
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_COOKIEFILE => $this->cookies, CURLOPT_TIMEOUT => 60,
            CURLOPT_REFERER => $this->base . '/' . ltrim($pagina, '/'), CURLOPT_POST => true, CURLOPT_POSTFIELDS => $dados,
        ]);
        $r = curl_exec($c);
        $tamCab = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        return ['codigo' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'local' => (string) curl_getinfo($c, CURLINFO_REDIRECT_URL),
                'cabecalhos' => substr((string) $r, 0, $tamCab), 'corpo' => substr((string) $r, $tamCab)];
    }

    /** Mensagens (flash) mostradas ao abrir $pagina. */
    public function mensagem(string $pagina): string
    {
        $corpo = $this->get($pagina)['corpo'];
        preg_match_all('/class="flash flash-[a-z]+" role="alert">([^<]*)</', $corpo, $m);
        return html_entity_decode(implode(' | ', $m[1]), ENT_QUOTES, 'UTF-8');
    }

    public function entrar(string $empresa, string $login, string $senha): array
    {
        return $this->enviar('index.php?p=login', 'index.php?p=login&a=entrar',
                             ['empresa' => $empresa, 'login' => $login, 'senha' => $senha]);
    }
}

/** Texto visível da página: cada tag vira espaço (strip_tags juntaria "Saldo" e "R$ 120,00"). */
function texto(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#s', ' ', $html);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES, 'UTF-8')));
}

function num_locacao(array $r): ?int
{
    return preg_match('/[?&]n=(\d+)/', $r['local'], $m) ? (int) $m[1] : null;
}

// ---------------------------------------------------------------------
// Limpeza: o que existia antes e o que o teste criou
// ---------------------------------------------------------------------
$inicio = dbv('SELECT now()');
$base = [];
foreach (db('SELECT t.id, t.slug,
                    (SELECT COALESCE(max(numero), 0) FROM locacoes l WHERE l.tenant_id = t.id) AS loc,
                    (SELECT COALESCE(max(numero), 0) FROM reservas r WHERE r.tenant_id = t.id) AS res,
                    (SELECT COALESCE(max(numero), 0) FROM manutencoes m WHERE m.tenant_id = t.id) AS man
               FROM tenants t')->fetchAll() as $t) {
    $base[$t['slug']] = $t;
}
if (!isset($base['alfa-locacoes'], $base['beta-eventos'])) {
    fwrite(STDERR, "Locadoras de demonstração não encontradas: rode database/03_demo.sql.\n");
    exit(1);
}

register_shutdown_function(function () use ($base, $inicio) {
    $q = fn($s, $p = []) => db($s, $p);
    try {
        db('BEGIN');
        // A limpeza não entra na auditoria (o interruptor vale para o superusuário) e apaga a do teste
        db("SELECT set_config('app.auditoria', 'off', true)");
        // Orçamentos de teste saem antes: eles apontam para as locações e reservas que o teste criou
        $q('DELETE FROM orcamentos WHERE criado_em >= :i', ['i' => $inicio]);
        foreach ($base as $t) {
            $locs = "SELECT id FROM locacoes WHERE tenant_id = :t AND numero > :n";
            $p = ['t' => $t['id'], 'n' => $t['loc']];
            $q("DELETE FROM caixa WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM entregas WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM locacao_unidades WHERE locacao_item_id IN (SELECT id FROM locacao_itens WHERE locacao_id IN ($locs))", $p);
            $q("DELETE FROM devolucao_itens WHERE devolucao_id IN (SELECT id FROM devolucoes WHERE locacao_id IN ($locs))", $p);
            $q("DELETE FROM devolucoes WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM locacao_itens WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM locacoes WHERE id IN ($locs)", $p);
            $q('DELETE FROM reservas WHERE tenant_id = :t AND numero > :n', ['t' => $t['id'], 'n' => $t['res']]);
            $q("UPDATE tenant_sequencias SET ultimo = :n WHERE tenant_id = :t AND nome = 'locacao'", ['t' => $t['id'], 'n' => $t['loc']]);
            $q("UPDATE tenant_sequencias SET ultimo = :n WHERE tenant_id = :t AND nome = 'reserva'", ['t' => $t['id'], 'n' => $t['res']]);
            $q("UPDATE tenant_sequencias SET ultimo = :n WHERE tenant_id = :t AND nome = 'manutencao'", ['t' => $t['id'], 'n' => $t['man']]);
        }
        // Vistorias criadas pelo teste: apaga os registros e os arquivos do disco
        $pasta = rtrim((string) ($GLOBALS['config']['pasta_arquivos'] ?? __DIR__ . '/../storage'), '/\\');
        $arqs = db("SELECT f.arquivo FROM vistoria_fotos f JOIN vistorias v ON v.id = f.vistoria_id WHERE v.criado_em >= :i
                    UNION ALL SELECT assinatura_arquivo FROM vistorias WHERE criado_em >= :i2 AND assinatura_arquivo <> ''",
                   ['i' => $inicio, 'i2' => $inicio])->fetchAll(PDO::FETCH_COLUMN);
        $q('DELETE FROM vistorias WHERE criado_em >= :i', ['i' => $inicio]);
        foreach ($arqs as $a) {
            @unlink("$pasta/$a");
        }
        $q("DELETE FROM kits WHERE codigo LIKE 'TESTE-%'");
        // Manutenções dos produtos de teste (e o custo lançado no caixa), unidades e produtos
        $prodTeste = "SELECT id FROM produtos WHERE codigo LIKE 'TESTE-%'";
        $caixaMan = db("SELECT caixa_id FROM manutencoes WHERE caixa_id IS NOT NULL AND produto_id IN ($prodTeste)")->fetchAll(PDO::FETCH_COLUMN);
        $q("DELETE FROM manutencoes WHERE produto_id IN ($prodTeste)");
        foreach ($caixaMan as $cx) {
            $q('DELETE FROM caixa WHERE id = :id', ['id' => $cx]);
        }
        $q("DELETE FROM unidades WHERE produto_id IN ($prodTeste)");
        $q("DELETE FROM produtos WHERE codigo LIKE 'TESTE-%'");
        $q("DELETE FROM contas_pagar WHERE descricao LIKE 'TESTE%'");
        $q("DELETE FROM contas_recorrentes WHERE descricao LIKE 'TESTE%'");
        $q("DELETE FROM caixa WHERE descricao LIKE 'TESTE%' AND criado_em >= :i", ['i' => $inicio]);
        $q("DELETE FROM clientes WHERE nome LIKE 'TESTE%'");
        $q("DELETE FROM usuarios WHERE login LIKE 'teste.%'");
        // locadoras criadas pelo teste no painel da plataforma
        foreach (['tenant_sequencias', 'usuarios', 'categorias', 'empresa'] as $tab) {
            $q("DELETE FROM $tab WHERE tenant_id IN (SELECT id FROM tenants WHERE slug LIKE 'teste-%')");
        }
        $q('DELETE FROM auditoria WHERE quando >= :i', ['i' => $inicio]);
        $q("DELETE FROM assinatura_cobrancas WHERE locadora_id IN (SELECT id FROM tenants WHERE slug LIKE 'teste-%')");
        $q("DELETE FROM assinatura_pagamentos WHERE locadora_id IN (SELECT id FROM tenants WHERE slug LIKE 'teste-%')");
        $q("DELETE FROM tenants WHERE slug LIKE 'teste-%'");
        $q('DELETE FROM auditoria_plataforma WHERE quando >= :i', ['i' => $inicio]);   // depois das exclusões de teste
        $q("DELETE FROM planos WHERE nome LIKE 'TESTE%'");
        $q("UPDATE tenants SET status = 'ativa' WHERE slug IN ('alfa-locacoes', 'beta-eventos')");
        $q("UPDATE empresa SET pix_chave = '', pix_nome = '', pix_cidade = '' WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'beta-eventos')");
        $q('DELETE FROM login_tentativas');
        db('COMMIT');
        echo "\n(Limpeza concluída: dados de teste removidos.)\n";
    } catch (Throwable $e) {
        db('ROLLBACK');
        echo "\n*** Falha na limpeza: {$e->getMessage()}\n";
    }
});

db('DELETE FROM login_tentativas'); // começa sem bloqueios de login antigos

echo "LocGestor - testes funcionais em $BASE\n" . str_repeat('=', 60) . "\n";

$alfa = new Navegador($BASE);
$beta = new Navegador($BASE);
$alfaId = $base['alfa-locacoes']['id'];
$betaId = $base['beta-eventos']['id'];
$produto = fn(string $tenant, string $cod) => dbv('SELECT id FROM produtos WHERE tenant_id = :t AND codigo = :c', ['t' => $tenant, 'c' => $cod]);
$BET = $produto($alfaId, 'BET-400');
$CAR = $produto($alfaId, 'CAR-MAO');
$AND = $produto($alfaId, 'AND-001');
$MES_BETA = $produto($betaId, 'MES-4C');
$MARIA = 'Maria Lúcia Bezerra · 111.444.777-35';

// =====================================================================
secao('Login e contexto da locadora');
$r = $alfa->entrar('alfa-locacoes', 'admin', 'admin123');
ok(str_contains($r['local'], 'p=dashboard'), 'admin entra na Alfa');
ok(str_contains($alfa->get('index.php?p=dashboard')['corpo'], '>Alfa Locações</strong>'), 'menu mostra o nome da Alfa');
$r = $beta->entrar('beta-eventos', 'admin', 'admin123');
ok(str_contains($r['local'], 'p=dashboard'), 'admin entra na Beta (outra sessão)');
$pb = $beta->get('index.php?p=dashboard')['corpo'];
ok(str_contains($pb, '>Beta Eventos</strong>') && !str_contains($pb, 'Alfa'), 'painel da Beta mostra a Beta e não cita a Alfa');
$x = new Navegador($BASE);
$x->entrar('alfa-locacoes', 'admin', 'errada');
ok($x->mensagem('index.php?p=login') === 'Dados de acesso inválidos.', 'senha errada: mensagem genérica');
$x->entrar('nao-existe', 'admin', 'admin123');
ok($x->mensagem('index.php?p=login') === 'Dados de acesso inválidos.', 'empresa inexistente: a mesma mensagem genérica');
ok($x->req('index.php?p=login&a=sair', ['x' => 1])['codigo'] === 400, 'POST sem token CSRF é recusado (400)');
ok((new Navegador($BASE))->get('index.php?p=clientes')['codigo'] === 303, 'sem login, as telas redirecionam para o login');

// =====================================================================
secao('Clientes: CPF por locadora');
$alfa->enviar('index.php?p=clientes&a=novo', 'index.php?p=clientes&a=salvar',
              ['tipo' => 'PF', 'nome' => 'TESTE Cpf', 'cpf_cnpj' => '123.456.789-00', 'ativo' => '1']);
ok($alfa->mensagem('index.php?p=clientes&a=novo') === 'CPF inválido.', 'CPF com dígito errado é recusado');
$alfa->enviar('index.php?p=clientes&a=novo', 'index.php?p=clientes&a=salvar',
              ['tipo' => 'PF', 'nome' => 'TESTE Cópia', 'cpf_cnpj' => '529.982.247-25', 'ativo' => '1']);
ok($alfa->mensagem('index.php?p=clientes&a=novo') === 'Já existe um cliente com este CPF: José Airton Holanda.',
   'CPF repetido na mesma locadora é recusado');
$r = $beta->enviar('index.php?p=clientes&a=novo', 'index.php?p=clientes&a=salvar',
                   ['tipo' => 'PF', 'nome' => 'TESTE José na Beta', 'cpf_cnpj' => '529.982.247-25', 'ativo' => '1']);
ok(str_contains($r['local'], 'a=ver&id='), 'o mesmo CPF é aceito em outra locadora (Beta)');

// =====================================================================
secao('Produtos: estoque não fica abaixo do alugado');
$alfa->enviar("index.php?p=produtos&a=editar&id=$BET", 'index.php?p=produtos&a=salvar',
              ['id' => $BET, 'codigo' => 'BET-400', 'descricao' => 'Betoneira 400 L', 'unidade' => 'UN', 'qtd_total' => '0',
               'valor_diaria' => '45,00', 'valor_reposicao' => '3.800,00', 'ncm' => '84743100', 'ativo' => '1',
               'categoria_id' => dbv('SELECT categoria_id FROM produtos WHERE id = :id', ['id' => $BET])]);
$m = $alfa->mensagem("index.php?p=produtos&a=editar&id=$BET");
ok(str_contains($m, 'não pode ser menor que a quantidade alugada') && dbv('SELECT qtd_total FROM produtos WHERE id = :id', ['id' => $BET]) == 8,
   'betoneira com estoque 0 e 1 na rua é recusada', $m);

// =====================================================================
secao('Locação: roteiro de cobrança (R$ 845 previsto, R$ 520 final)');
$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(-4), 'data_prev_devolucao' => dia(3),
    'produto_id' => [$BET, $CAR], 'quantidade' => ['2', '5'], 'diaria' => ['45,00', ''],
    'acrescimo' => '50,00', 'desconto' => '10,00', 'adiantamento' => '100,00', 'forma' => 'PIX',
]);
$N = num_locacao($r);
$valores = fn(int $n, string $t) => db('SELECT v.valor_previsto, v.valor_atual, v.valor_pago, l.status, l.id
                                          FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                                         WHERE l.tenant_id = :t AND l.numero = :n', ['t' => $t, 'n' => $n])->fetch();
$v = $N ? $valores($N, $alfaId) : null;
ok($v && $v['valor_previsto'] === '845.00' && $v['valor_pago'] === '100.00', "locação nº $N: previsto R$ 845,00 e adiantamento de R$ 100,00",
   json_encode($v));
$LOC = $v['id'] ?? '';
$alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(2),
    'produto_id' => [$BET, $AND, $AND], 'quantidade' => ['8', '30', '20'], 'diaria' => ['', '', ''],
]);
$m = $alfa->mensagem('index.php?p=locacoes&a=nova');
ok(str_contains($m, 'Estoque insuficiente') && str_contains($m, 'Betoneira 400 L (pedido 8, disponível 5)')
   && str_contains($m, '(pedido 50, disponível 40)'), 'pedir mais que o disponível lista todos os itens em falta', $m);
$alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(1), 'data_prev_devolucao' => dia(3), 'produto_id' => [$BET], 'quantidade' => ['1'],
]);
ok(str_contains($alfa->mensagem('index.php?p=locacoes&a=nova'), 'faça uma reserva'), 'saída no futuro é recusada sugerindo reserva');

$item = fn(string $prod) => dbv('SELECT id FROM locacao_itens WHERE locacao_id = :l AND produto_id = :p', ['l' => $LOC, 'p' => $prod]);
$IB = $item($BET);
$IC = $item($CAR);
$dev = "index.php?p=locacoes&a=devolucao&n=$N";
$alfa->enviar($dev, 'index.php?p=locacoes&a=devolver', ['id' => $LOC, 'data' => dia(0), "qtd[$IB]" => '1', "qtd[$IC]" => '0']);
ok($valores($N, $alfaId)['status'] === 'aberta', 'devolver 1 betoneira hoje: devolução parcial, continua aberta');
$alfa->enviar($dev, 'index.php?p=locacoes&a=devolver', ['id' => $LOC, 'data' => dia(0), "qtd[$IB]" => '2', "qtd[$IC]" => '0']);
ok(str_contains($alfa->mensagem($dev), 'só há 1 pendente'), 'devolver mais que o pendente é recusado');
$alfa->enviar($dev, 'index.php?p=locacoes&a=devolver',
              ['id' => $LOC, 'data' => dia(0), "qtd[$IB]" => '1', "qtd[$IC]" => '5', 'avaria' => '20,00', 'receber' => '300,00', 'forma' => 'PIX']);
$v = $valores($N, $alfaId);
ok($v['status'] === 'devolvida' && $v['valor_atual'] === '520.00' && $v['valor_pago'] === '400.00',
   'restante com avaria R$ 20 e R$ 300 recebidos: devolvida, final R$ 520,00, pago R$ 400,00', json_encode($v));
ok(str_contains(texto($alfa->get("index.php?p=locacoes&a=ver&n=$N")['corpo']), 'Saldo R$ 120,00'), 'tela mostra saldo de R$ 120,00');
$ct = $alfa->get("index.php?p=locacoes&a=contrato&n=$N")['corpo'];
ok(str_contains(texto($ct), 'Valor previsto R$ 845,00') && str_contains($ct, 'CLÁUSULA 5ª') && str_contains($ct, "index.php?p=locacoes&amp;a=ver&amp;n=$N\">Voltar"),
   'contrato: valor previsto, cláusulas e botão Voltar para a locação');
ok(str_contains(texto($alfa->get("index.php?p=locacoes&a=fatura&n=$N")['corpo']), 'Total da locação R$ 520,00'), 'fatura com total de R$ 520,00');

// =====================================================================
secao('Preço semanal/mensal e kits');
// A regra existe no banco (view), no PHP (telas) e no JavaScript (prévia): as três têm de dar o mesmo valor
require_once __DIR__ . '/../app/negocio.php';
$difs = [];
$grade = [];
foreach ([[45, 225, 700], [10, 50, 150], [3.5, 17.5, 52], [12, 0, 200], [12, 60, 0], [7.33, 40.1, 99.9], [0, 0, 0]] as [$pd, $ps, $pm]) {
    $sql = db('SELECT d, preco_periodo(d, :a, :b, :c) AS v FROM generate_series(0, 400) d', ['a' => $pd, 'b' => $ps, 'c' => $pm])->fetchAll();
    foreach ($sql as $l) {
        $grade[] = [(int) $l['d'], $pd, $ps, $pm, (float) $l['v']];
        if (abs((float) $l['v'] - preco_periodo((int) $l['d'], $pd, $ps, $pm)) > 0.001) {
            $difs[] = "$pd/$ps/$pm d={$l['d']}: banco {$l['v']}, PHP " . preco_periodo((int) $l['d'], $pd, $ps, $pm);
        }
    }
}
ok(!$difs, 'preco_periodo: banco e PHP iguais de 0 a 400 dias em 7 tabelas de preço', implode('; ', array_slice($difs, 0, 3)));
$node = trim((string) @shell_exec('node --version 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
if (str_starts_with($node, 'v')) {
    $tmp = tempnam(sys_get_temp_dir(), 'lgp');
    file_put_contents($tmp, json_encode(array_map(fn($g) => array_slice($g, 0, 4), $grade)));
    $js = json_decode((string) shell_exec('node ' . escapeshellarg(__DIR__ . '/preco_periodo.js') . ' ' . escapeshellarg($tmp)), true);
    @unlink($tmp);
    $difs = [];
    foreach ($grade as $i => $g) {
        if (!is_array($js) || abs(($js[$i] ?? -1) - $g[4]) > 0.001) {
            $difs[] = "$g[1]/$g[2]/$g[3] d=$g[0]: banco $g[4], JS " . json_encode($js[$i] ?? null);
        }
    }
    ok(is_array($js) && count($js) === count($grade) && !$difs, 'preco_periodo: JavaScript (app.js) igual ao banco nos mesmos ' . count($grade) . ' casos',
       implode('; ', array_slice($difs, 0, 3)));
} else {
    echo "  (pulado: Node.js não encontrado para conferir o cálculo do app.js)\n";
}
ok(preco_periodo(10, 10, 50, 150) == 80 && preco_periodo(35, 10, 50, 150) == 200 && preco_periodo(6, 10, 50, 150) == 50
   && preco_periodo(29, 10, 50, 150) == 150 && preco_periodo(5, 10, 50, 150) == 50 && preco_periodo(4, 10, 50, 150) == 40,
   'regra: 10 dias = semana + 3 diárias; 35 = mês + semana; sobra de dias não passa da semana nem do mês');

$catSem = dbv('SELECT categoria_id FROM produtos WHERE id = :id', ['id' => $AND]);
$prodSem = ['codigo' => 'TESTE-SEM', 'descricao' => 'TESTE produto semanal', 'unidade' => 'UN', 'qtd_total' => '5',
            'valor_diaria' => '0', 'valor_semanal' => '50,00', 'valor_mensal' => '', 'ativo' => '1', 'categoria_id' => $catSem];
$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar', $prodSem);
ok(str_contains($alfa->mensagem('index.php?p=produtos&a=novo'), 'informe também a diária'), 'semanal sem diária é recusado');
$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar',
              ['valor_diaria' => '10,00', 'valor_mensal' => '40,00'] + $prodSem);
ok(str_contains($alfa->mensagem('index.php?p=produtos&a=novo'), 'mensal não pode ser menor que o semanal'), 'mensal menor que semanal é recusado');
$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar',
              ['valor_diaria' => '10,00', 'valor_mensal' => '150,00'] + $prodSem);
$SEM = $produto($alfaId, 'TESTE-SEM');
ok($SEM && db('SELECT valor_diaria, valor_semanal, valor_mensal FROM produtos WHERE id = :id', ['id' => $SEM])->fetch(PDO::FETCH_NUM) === ['10.00', '50.00', '150.00'],
   'produto com diária R$ 10, semana R$ 50 e mês R$ 150 cadastrado');
$pg = texto($alfa->get('index.php?p=produtos&q=TESTE-SEM')['corpo']);
ok(str_contains($pg, 'R$ 10,00/dia R$ 50,00/semana R$ 150,00/mês'), 'lista de produtos mostra a tabela de preços');

$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(-10), 'data_prev_devolucao' => dia(25),
    'produto_id' => [$SEM], 'quantidade' => ['2'], 'diaria' => [''],
]);
$NS = num_locacao($r);
$v = $NS ? $valores($NS, $alfaId) : null;
ok($v && $v['valor_previsto'] === '400.00' && $v['valor_atual'] === '160.00',
   "locação nº $NS de 35 dias: previsto 2 × (mês + semana) = R$ 400,00 (só diárias: R$ 700); até hoje (10 dias) R$ 160,00", json_encode($v));
// Preço novo no cadastro não muda locação já feita (diária, semana e mês ficam congelados)
$alfa->enviar("index.php?p=produtos&a=editar&id=$SEM", 'index.php?p=produtos&a=salvar',
              ['id' => $SEM, 'valor_diaria' => '20,00', 'valor_semanal' => '100,00', 'valor_mensal' => '300,00'] + $prodSem);
$v2 = $valores($NS, $alfaId);
ok(dbv('SELECT valor_semanal FROM produtos WHERE id = :id', ['id' => $SEM]) === '100.00' && $v2['valor_previsto'] === '400.00'
   && $v2['valor_atual'] === '160.00', 'mudar o preço do produto não altera a locação feita', json_encode($v2));
$tl = texto($alfa->get("index.php?p=locacoes&a=ver&n=$NS")['corpo']);
ok(str_contains($tl, 'R$ 10,00/dia R$ 50,00/semana R$ 150,00/mês R$ 160,00'), 'detalhe: tabela congelada e valor até hoje R$ 160,00 (cálculo do PHP = view)');
$ct = texto($alfa->get("index.php?p=locacoes&a=contrato&n=$NS")['corpo']);
ok(str_contains($ct, 'Subtotal R$ 400,00') && str_contains($ct, 'Valor previsto R$ 400,00') && str_contains($ct, '(35 dia(s))'),
   'contrato: subtotal do PHP igual ao previsto da view (R$ 400,00)');
$ISEM = dbv('SELECT id FROM locacao_itens WHERE locacao_id = :l', ['l' => $v['id']]);
$devForm = $alfa->get("index.php?p=locacoes&a=devolucao&n=$NS")['corpo'];
ok(str_contains($devForm, 'data-semanal="50.00"') && str_contains($devForm, 'data-mensal="150.00"'), 'devolução recebe semana e mês para o cálculo na tela');
$alfa->enviar("index.php?p=locacoes&a=devolucao&n=$NS", 'index.php?p=locacoes&a=devolver',
              ['id' => $v['id'], 'data' => dia(0), "qtd[$ISEM]" => '2']);
$v3 = $valores($NS, $alfaId);
ok($v3['status'] === 'devolvida' && $v3['valor_atual'] === '160.00'
   && dbv('SELECT valor FROM devolucao_itens WHERE locacao_item_id = :i', ['i' => $ISEM]) === '160.00',
   'devolução em 10 dias: R$ 160,00 gravado no item', json_encode($v3));

// Kits
$kit = ['codigo' => 'teste-kit', 'nome' => 'TESTE kit andaime', 'obs' => '', 'ativo' => '1',
        'produto_id' => [$SEM, $AND, '', $SEM], 'quantidade' => ['1', '3', '1', '1']];
$r = $alfa->enviar('index.php?p=produtos&a=kit_novo', 'index.php?p=produtos&a=kit_salvar', $kit);
$KIT = dbv("SELECT id FROM kits WHERE tenant_id = :t AND codigo = 'TESTE-KIT'", ['t' => $alfaId]);
ok($KIT && db('SELECT produto_id, quantidade FROM kit_itens WHERE kit_id = :k ORDER BY quantidade', ['k' => $KIT])->fetchAll(PDO::FETCH_NUM)
   === [[$SEM, 2], [$AND, 3]], 'kit criado: linha vazia ignorada e produto repetido somado (2 + 3 itens)');
$alfa->enviar('index.php?p=produtos&a=kit_novo', 'index.php?p=produtos&a=kit_salvar', ['nome' => 'TESTE outro'] + $kit);
ok($alfa->mensagem('index.php?p=produtos&a=kit_novo') === 'Já existe um kit com este código.', 'código de kit repetido é recusado');
$alfa->enviar('index.php?p=produtos&a=kit_novo', 'index.php?p=produtos&a=kit_salvar', ['codigo' => 'TESTE-VAZIO', 'produto_id' => ['']] + $kit);
ok(str_contains($alfa->mensagem('index.php?p=produtos&a=kit_novo'), 'pelo menos um item'), 'kit sem itens é recusado');
$nova = $alfa->get('index.php?p=locacoes&a=nova')['corpo'];
ok(str_contains($nova, 'id="kit-sel"') && str_contains($nova, htmlspecialchars(json_encode([['p' => $AND, 'q' => 3], ['p' => $SEM, 'q' => 2]]), ENT_QUOTES)),
   'nova locação oferece o kit com a composição para o JavaScript');
ok(str_contains($alfa->get('index.php?p=reservas&a=nova')['corpo'], 'TESTE-KIT'), 'nova reserva também oferece o kit');
$r = $alfa->enviar("index.php?p=produtos&a=kit_editar&id=$KIT", 'index.php?p=produtos&a=kit_salvar',
                   ['id' => $KIT, 'produto_id' => [$AND], 'quantidade' => ['4'], 'ativo' => ''] + $kit);
ok(db('SELECT produto_id, quantidade FROM kit_itens WHERE kit_id = :k', ['k' => $KIT])->fetchAll(PDO::FETCH_NUM) === [[$AND, 4]]
   && !str_contains($alfa->get('index.php?p=locacoes&a=nova')['corpo'], 'TESTE-KIT'), 'kit editado (composição trocada) e inativo some da locação');
// Isolamento dos kits
ok(!str_contains($beta->get('index.php?p=produtos&a=kits')['corpo'], 'TESTE-KIT')
   && $beta->get("index.php?p=produtos&a=kit_editar&id=$KIT")['codigo'] === 404, 'Beta não vê nem abre o kit da Alfa (404)');
$beta->enviar('index.php?p=produtos&a=kit_novo', 'index.php?p=produtos&a=kit_salvar', ['id' => $KIT, 'produto_id' => [$MES_BETA], 'quantidade' => ['1']] + $kit);
$m = $beta->mensagem('index.php?p=produtos&a=kit_novo');
ok(str_contains($m, 'Kit não encontrado.') && dbv('SELECT nome FROM kits WHERE id = :k', ['k' => $KIT]) === 'TESTE kit andaime', 'Beta não altera o kit da Alfa', $m);
$beta->enviar('index.php?p=produtos&a=kit_novo', 'index.php?p=produtos&a=kit_salvar', ['produto_id' => [$AND], 'quantidade' => ['1']] + $kit);
$m = $beta->mensagem('index.php?p=produtos&a=kit_novo');
ok(str_contains($m, 'Produto não encontrado na composição do kit.') && dbv("SELECT count(*) FROM kits WHERE tenant_id = :t", ['t' => $betaId]) == 0,
   'Beta não monta kit com produto da Alfa', $m);
$beta->enviar('index.php?p=produtos&a=kit_novo', 'index.php?p=produtos&a=kit_excluir', ['id' => $KIT]);
$m = $beta->mensagem('index.php?p=produtos&a=kit_novo');
ok(str_contains($m, 'Kit não encontrado.') && dbv('SELECT count(*) FROM kits WHERE id = :k', ['k' => $KIT]) == 1, 'Beta não exclui o kit da Alfa', $m);
$alfa->enviar("index.php?p=produtos&a=kit_editar&id=$KIT", 'index.php?p=produtos&a=kit_excluir', ['id' => $KIT]);
ok(dbv('SELECT count(*) FROM kits WHERE id = :k', ['k' => $KIT]) == 0 && dbv('SELECT count(*) FROM kit_itens WHERE kit_id = :k', ['k' => $KIT]) == 0
   && dbv('SELECT count(*) FROM produtos WHERE id = :p', ['p' => $AND]) == 1, 'Alfa exclui o kit (itens juntos; produtos ficam)');

// =====================================================================
secao('Patrimônio (unidades) e manutenção');
$prodPat = ['codigo' => 'TESTE-PAT', 'descricao' => 'TESTE gerador com patrimônio', 'unidade' => 'UN', 'qtd_total' => '3',
            'valor_diaria' => '50,00', 'ativo' => '1', 'categoria_id' => $catSem];
$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar', $prodPat);
$PAT = $produto($alfaId, 'TESTE-PAT');
$novaUn = fn(string $pat) => $alfa->enviar("index.php?p=produtos&a=unidade_nova&produto=$PAT", 'index.php?p=produtos&a=unidade_salvar',
                                           ['id' => '', 'produto_id' => $PAT, 'patrimonio' => $pat, 'serie' => "SN-$pat", 'aquisicao_valor' => '2.500,00']);
$un = fn(string $pat) => dbv('SELECT id FROM unidades WHERE tenant_id = :t AND patrimonio = :p', ['t' => $alfaId, 'p' => $pat]);
foreach (['teste-p1', 'teste-p2', 'teste-p3'] as $pp) {
    $novaUn($pp);
}
[$P1, $P2, $P3] = [$un('TESTE-P1'), $un('TESTE-P2'), $un('TESTE-P3')];
ok($P1 && $P2 && $P3 && dbv('SELECT aquisicao_valor FROM unidades WHERE id = :u', ['u' => $P1]) === '2500.00',
   '3 unidades cadastradas (patrimônio em maiúsculas, valor de aquisição)');
$novaUn('teste-p4');
ok(str_contains($alfa->mensagem("index.php?p=produtos&a=unidade_nova&produto=$PAT"), 'Aumente o estoque'), 'não cadastra mais unidades que o estoque (3)');
$alfa->enviar("index.php?p=produtos&a=unidade&id=$P2", 'index.php?p=produtos&a=unidade_salvar', ['id' => $P2, 'patrimonio' => 'TESTE-P1']);
ok($alfa->mensagem("index.php?p=produtos&a=unidade&id=$P2") === 'Já existe uma unidade com este número de patrimônio.', 'patrimônio repetido é recusado');

$abrir = fn(Navegador $n, array $c) => $n->enviar('index.php?p=manutencoes&a=nova', 'index.php?p=manutencoes&a=abrir',
                                                   $c + ['tipo' => 'corretiva', 'descricao' => 'TESTE motor falhando', 'data_inicio' => dia(0)]);
$abrir($alfa, ['unidade_id' => $P1]);
$M1 = dbv('SELECT id FROM manutencoes WHERE unidade_id = :u AND data_fim IS NULL', ['u' => $P1]);
$api = json_decode($alfa->get("index.php?p=api&a=disponivel&produto=$PAT&inicio=" . dia(0) . '&fim=' . dia(30))['corpo'], true);
ok($M1 && ($api['disponivel'] ?? null) === 2, 'unidade TESTE-P1 em manutenção: disponível cai para 2 (em qualquer período)', json_encode($api));
$lista = texto($alfa->get('index.php?p=produtos&q=TESTE-PAT')['corpo']);
ok(str_contains($lista, 'TESTE gerador com patrimônio') && preg_match('/R\$ 50,00\/dia 3 UN 0 0 1 2/', $lista) === 1,
   'lista de produtos: estoque 3, manutenção 1, disponível 2', $lista);
$abrir($alfa, ['unidade_id' => $P1]);
ok(str_contains($alfa->mensagem('index.php?p=manutencoes&a=nova'), 'já está na manutenção nº'), 'a mesma unidade não abre duas manutenções');

$alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(5), 'produto_id' => [$PAT], 'quantidade' => ['3'], 'diaria' => [''],
]);
ok(str_contains($alfa->mensagem('index.php?p=locacoes&a=nova'), '(pedido 3, disponível 2)'), 'locação de 3 recusada: 1 está em manutenção');
$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(5), 'produto_id' => [$PAT], 'quantidade' => ['2'], 'diaria' => [''],
]);
$NP = num_locacao($r);
$LP = $valores($NP, $alfaId)['id'];
$IP = dbv('SELECT id FROM locacao_itens WHERE locacao_id = :l', ['l' => $LP]);
$detP = $alfa->get("index.php?p=locacoes&a=ver&n=$NP")['corpo'];
ok(str_contains($detP, 'Patrimônio (unidades que saíram)') && str_contains($detP, 'TESTE-P2') && !str_contains($detP, "value=\"$P1\""),
   'detalhe da locação oferece as unidades livres (sem a que está em manutenção)');
$salvarUn = fn(Navegador $n, string $loc, array $lista) => $n->enviar("index.php?p=locacoes&a=ver&n=$NP", 'index.php?p=locacoes&a=unidades_salvar',
                                                                      ['id' => $loc, "unidades[$IP]" => $lista]);
$salvarUn($alfa, $LP, [$P1]);
ok(str_contains($alfa->mensagem("index.php?p=locacoes&a=ver&n=$NP"), 'TESTE-P1 está na manutenção nº'), 'unidade em manutenção não sai na locação');
$salvarUn($alfa, $LP, [$P1, $P2, $P3]);
ok(str_contains($alfa->mensagem("index.php?p=locacoes&a=ver&n=$NP"), 'no máximo 2'), 'não indica mais unidades que a quantidade na rua');
$salvarUn($alfa, $LP, [$P2, $P3]);
ok(dbv('SELECT count(*) FROM locacao_unidades WHERE locacao_item_id = :i AND devolucao_id IS NULL', ['i' => $IP]) == 2
   && str_contains(texto($alfa->get("index.php?p=locacoes&a=contrato&n=$NP")['corpo']), 'Patrimônio: TESTE-P2, TESTE-P3'),
   'TESTE-P2 e TESTE-P3 na locação, e o contrato mostra os números');
$alfa->enviar("index.php?p=produtos&a=unidade&id=$P2", 'index.php?p=produtos&a=unidade_baixar', ['id' => $P2, 'baixa_data' => dia(0), 'baixa_motivo' => 'TESTE']);
ok(str_contains($alfa->mensagem("index.php?p=produtos&a=unidade&id=$P2"), 'está na rua (locação nº'), 'unidade na rua não recebe baixa');
$abrir($alfa, ['unidade_id' => $P3]);
ok(str_contains($alfa->mensagem('index.php?p=manutencoes&a=nova'), 'está na rua'), 'unidade na rua não vai para manutenção');
$abrir($alfa, ['produto_id' => $PAT, 'quantidade' => '1']);
ok(str_contains($alfa->mensagem('index.php?p=manutencoes&a=nova'), 'Só há 0 peça(s)'), 'manutenção por quantidade respeita o que está livre (0)');
$alfa->enviar("index.php?p=produtos&a=editar&id=$PAT", 'index.php?p=produtos&a=salvar', ['id' => $PAT, 'qtd_total' => '2'] + $prodPat);
ok(str_contains($alfa->mensagem("index.php?p=produtos&a=editar&id=$PAT"), '2 na rua e 1 em manutenção'), 'estoque não fica abaixo do que está fora');

$devP = "index.php?p=locacoes&a=devolucao&n=$NP";
ok(str_contains($alfa->get($devP)['corpo'], 'name="unidades[]" value="' . $P2 . '"'), 'devolução lista as unidades na rua para marcar');
$alfa->enviar($devP, 'index.php?p=locacoes&a=devolver', ['id' => $LP, 'data' => dia(0), "qtd[$IP]" => '1', 'unidades' => [$P2, $P3]]);
ok(str_contains($alfa->mensagem($devP), 'mais unidades de TESTE gerador'), 'devolver 1 marcando 2 unidades é recusado');
$alfa->enviar($devP, 'index.php?p=locacoes&a=devolver', ['id' => $LP, 'data' => dia(0), "qtd[$IP]" => '1']);
ok(str_contains($alfa->mensagem($devP), 'Marque quais unidades'), 'devolução parcial sem dizer qual unidade voltou é recusada');
$alfa->enviar($devP, 'index.php?p=locacoes&a=devolver', ['id' => $LP, 'data' => dia(0), "qtd[$IP]" => '1', 'unidades' => [$P3]]);
ok(dbv('SELECT devolucao_id IS NOT NULL FROM locacao_unidades WHERE unidade_id = :u', ['u' => $P3]) === true
   && dbv('SELECT devolucao_id IS NULL FROM locacao_unidades WHERE unidade_id = :u', ['u' => $P2]) === true, 'parcial: TESTE-P3 voltou, TESTE-P2 continua na rua');

$alfa->enviar("index.php?p=manutencoes&a=ver&id=$M1", 'index.php?p=manutencoes&a=concluir',
              ['id' => $M1, 'data_fim' => dia(0), 'custo' => '150,00', 'solucao' => 'Troca da vela', 'lancar_caixa' => '1', 'forma' => 'PIX']);
$m1 = db('SELECT m.data_fim, m.custo, c.tipo, c.valor, c.categoria FROM manutencoes m LEFT JOIN caixa c ON c.id = m.caixa_id WHERE m.id = :id', ['id' => $M1])->fetch();
ok($m1['data_fim'] === dia(0) && $m1['custo'] === '150.00' && $m1['tipo'] === 'S' && $m1['valor'] === '150.00' && $m1['categoria'] === 'Manutenção',
   'manutenção concluída com custo de R$ 150,00 lançado como saída no caixa', json_encode($m1));
$alfa->enviar("index.php?p=manutencoes&a=ver&id=$M1", 'index.php?p=manutencoes&a=concluir', ['id' => $M1, 'data_fim' => dia(0)]);
ok($alfa->mensagem("index.php?p=manutencoes&a=ver&id=$M1") === 'Esta manutenção já foi concluída.', 'não conclui duas vezes');
$alfa->enviar($devP, 'index.php?p=locacoes&a=devolver', ['id' => $LP, 'data' => dia(0), "qtd[$IP]" => '1']);
ok($valores($NP, $alfaId)['status'] === 'devolvida' && dbv('SELECT count(*) FROM locacao_unidades WHERE locacao_item_id = :i AND devolucao_id IS NULL', ['i' => $IP]) == 0,
   'devolução do restante libera TESTE-P2 sozinha');
$hist = texto($alfa->get("index.php?p=produtos&a=unidade&id=$P1")['corpo']);
ok(str_contains($hist, 'Manutenção nº') && str_contains($hist, 'TESTE motor falhando'), 'histórico da unidade mostra a manutenção');
ok(str_contains(texto($alfa->get("index.php?p=produtos&a=unidade&id=$P2")['corpo']), "Locação nº $NP"), 'histórico da unidade mostra a locação');
$alfa->enviar("index.php?p=produtos&a=unidade&id=$P1", 'index.php?p=produtos&a=unidade_excluir', ['id' => $P1]);
ok(str_contains($alfa->mensagem("index.php?p=produtos&a=unidade&id=$P1"), 'em uso') && $un('TESTE-P1') !== null, 'unidade com histórico não é excluída');
$alfa->enviar("index.php?p=produtos&a=unidade&id=$P1", 'index.php?p=produtos&a=unidade_baixar', ['id' => $P1, 'baixa_data' => dia(0), 'baixa_motivo' => 'TESTE sucata']);
ok(dbv('SELECT ativa::text FROM unidades WHERE id = :u', ['u' => $P1]) === 'false' && dbv('SELECT qtd_total FROM produtos WHERE id = :p', ['p' => $PAT]) == 2,
   'baixa de TESTE-P1: unidade inativa e estoque 3 → 2');
// Rentabilidade: R$ 100 faturados (2 devoluções de R$ 50), R$ 150 de manutenção; investimento = 2 unidades ativas × R$ 2.500
$per = '&de=' . dia(-1) . '&ate=' . dia(0);
$rent = texto($alfa->get("index.php?p=relatorios&a=ver&r=rentabilidade$per")['corpo']);
ok(str_contains($rent, 'TESTE-PAT TESTE gerador com patrimônio R$ 100,00 R$ 150,00 -R$ 50,00 R$ 5.000,00 -1,0%'),
   'rentabilidade do produto: faturamento − manutenção, investimento pelas unidades e % recuperado');
$rentU = texto($alfa->get("index.php?p=relatorios&a=ver&r=rentabilidade_unidade$per")['corpo']);
ok(str_contains($rentU, 'TESTE-P3 TESTE gerador com patrimônio R$ 2.500,00 1 1 R$ 50,00 R$ 0,00 R$ 50,00')
   && !str_contains($rentU, 'TESTE-P1'), 'rentabilidade por unidade: a parte de cada unidade no item (baixada não aparece)');

// Cancelar a locação libera as unidades
$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(2), 'produto_id' => [$PAT], 'quantidade' => ['1'], 'diaria' => [''],
]);
$NC = num_locacao($r);
$LC = $valores($NC, $alfaId)['id'];
$IC2 = dbv('SELECT id FROM locacao_itens WHERE locacao_id = :l', ['l' => $LC]);
$alfa->enviar("index.php?p=locacoes&a=ver&n=$NC", 'index.php?p=locacoes&a=unidades_salvar', ['id' => $LC, "unidades[$IC2]" => [$P3]]);
$alfa->enviar("index.php?p=locacoes&a=ver&n=$NC", 'index.php?p=locacoes&a=cancelar', ['id' => $LC, 'motivo' => 'TESTE', 'forma' => 'PIX']);
ok($valores($NC, $alfaId)['status'] === 'cancelada' && dbv('SELECT count(*) FROM locacao_unidades WHERE locacao_item_id = :i', ['i' => $IC2]) == 0,
   'cancelar a locação libera a unidade');

// Isolamento
ok($beta->get("index.php?p=produtos&a=unidade&id=$P3")['codigo'] === 404 && $beta->get("index.php?p=manutencoes&a=ver&id=$M1")['codigo'] === 404
   && !str_contains($beta->get('index.php?p=manutencoes&situacao=todas')['corpo'], 'TESTE motor'), 'Beta não vê unidade nem manutenção da Alfa');
$abrir($beta, ['unidade_id' => $P3]);
$m = $beta->mensagem('index.php?p=manutencoes&a=nova');
$abrir($beta, ['produto_id' => $PAT, 'quantidade' => '1']);
$m2 = $beta->mensagem('index.php?p=manutencoes&a=nova');
ok(str_contains($m, 'Unidade não encontrada.') && str_contains($m2, 'Produto não encontrado.')
   && dbv('SELECT count(*) FROM manutencoes WHERE tenant_id = :t', ['t' => $betaId]) == 0, 'Beta não abre manutenção de unidade ou produto da Alfa', "$m / $m2");
$beta->enviar('index.php?p=manutencoes&a=nova', 'index.php?p=produtos&a=unidade_baixar', ['id' => $P3, 'baixa_data' => dia(0), 'baixa_motivo' => 'invasão']);
$m = $beta->mensagem('index.php?p=manutencoes&a=nova');
$beta->enviar('index.php?p=manutencoes&a=nova', 'index.php?p=manutencoes&a=excluir', ['id' => $M1]);
$m2 = $beta->mensagem('index.php?p=manutencoes&a=nova');
ok(str_contains($m, 'Unidade não encontrada.') && str_contains($m2, 'Só é possível excluir') && dbv('SELECT ativa FROM unidades WHERE id = :u', ['u' => $P3]) === true
   && dbv('SELECT count(*) FROM manutencoes WHERE id = :m', ['m' => $M1]) == 1, 'Beta não dá baixa em unidade nem exclui manutenção da Alfa', "$m / $m2");
$beta->enviar('index.php?p=manutencoes&a=nova', 'index.php?p=locacoes&a=unidades_salvar', ['id' => $LP, "unidades[$IP]" => [$P3]]);
ok(str_contains($beta->mensagem('index.php?p=manutencoes&a=nova'), 'Locação não encontrada.'), 'Beta não mexe no patrimônio de locação da Alfa');

// =====================================================================
secao('WhatsApp (links wa.me com a mensagem pronta)');
/** Links wa.me da página: [numero => [textos decodificados]] */
$links = function (string $html): array {
    preg_match_all('#href="https://wa\.me/(\d+)\?text=([^"]*)"#', $html, $m, PREG_SET_ORDER);
    $r = [];
    foreach ($m as $x) {
        $r[$x[1]][] = rawurldecode(html_entity_decode($x[2], ENT_QUOTES, 'UTF-8'));
    }
    return $r;
};
$pg = $alfa->get("index.php?p=locacoes&a=ver&n=$N")['corpo'];
$l = $links($pg)['5585997015566'] ?? [];
ok(count($l) === 2 && str_contains($pg, 'Enviar resumo') && str_contains($pg, 'Cobrar saldo') && !str_contains($pg, 'Lembrete de devolução'),
   "locação nº $N (devolvida, com saldo): resumo e cobrança para o celular 55 85 99701-5566, sem lembrete");
ok(isset($l[1]) && str_contains($l[1], 'Olá, Maria!') && str_contains($l[1], "*locação nº $N*") && str_contains($l[1], 'R$ 120,00'),
   'mensagem de cobrança: saudação pelo primeiro nome, número da locação e saldo de R$ 120,00');
$atr = $links($alfa->get('index.php?p=locacoes&a=ver&n=141')['corpo'])['5585981002000'] ?? [];
ok((bool) array_filter($atr, fn($t) => str_contains($t, 'Olá, Construtora Jaguaribe Ltda!') && str_contains($t, 'está *atrasada* desde')),
   'lembrete da locação atrasada (cliente PJ): razão social e aviso de atraso');
ok(isset($links($alfa->get('index.php?p=locacoes&aba=atrasadas')['corpo'])['5585981002000']), 'aba Atrasadas tem o botão de lembrete por linha');
$linksBeta = $links($beta->get('index.php?p=dashboard')['corpo']);
ok(!isset($linksBeta['5585981002000']), 'painel da Beta não oferece lembrete para cliente da Alfa');
$r = $alfa->enviar('index.php?p=clientes&a=novo', 'index.php?p=clientes&a=salvar', ['tipo' => 'PF', 'nome' => 'TESTE Sem Telefone', 'ativo' => '1']);
$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar',
                   ['cliente' => 'TESTE Sem Telefone', 'data_saida' => dia(0), 'data_prev_devolucao' => dia(1), 'produto_id' => [$CAR], 'quantidade' => ['1']]);
$pg = $alfa->get('index.php?p=locacoes&a=ver&n=' . num_locacao($r))['corpo'];
ok(!str_contains($pg, 'https://wa.me/') && str_contains($pg, 'btn-whats') && str_contains($pg, 'desativado')
   && str_contains($pg, 'Cadastre o celular do cliente'), 'cliente sem telefone: botões desativados, com a orientação');

// =====================================================================
secao('Vistoria: checklist, fotos e assinatura');
if (!function_exists('imagecreatetruecolor')) {
    echo "  (pulado: a extensão gd do PHP é necessária para gerar as imagens de teste)\n";
} else {
    $tmp = sys_get_temp_dir();
    $pastaArq = rtrim((string) ($config['pasta_arquivos'] ?? __DIR__ . '/../storage'), '/\\');
    $contarArquivos = function () use ($pastaArq): int {
        $n = 0;
        if (is_dir($pastaArq)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pastaArq, FilesystemIterator::SKIP_DOTS)) as $f) {
                $n += preg_match('/\.(jpg|png)$/', $f->getFilename()) ? 1 : 0;
            }
        }
        return $n;
    };
    // Foto 2000x1500 com um bloco EXIF falso contendo um "GPS" marcador: precisa sumir no arquivo gravado
    $img = imagecreatetruecolor(2000, 1500);
    imagefilledrectangle($img, 0, 0, 2000, 1500, imagecolorallocate($img, 200, 120, 40));
    ob_start(); imagejpeg($img); $jpeg = ob_get_clean();
    $marca = 'GPSLatitude-SEGREDO-' . bin2hex(random_bytes(4));
    $exif = "Exif\0\0" . $marca . str_repeat("\0", 16);
    $jpegComExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    file_put_contents("$tmp/teste-foto1.jpg", $jpegComExif);
    $png = imagecreatetruecolor(400, 300);
    ob_start(); imagepng($png); file_put_contents("$tmp/teste-foto2.png", ob_get_clean());
    file_put_contents("$tmp/teste-falsa.jpg", "isto nao e uma imagem");
    // Assinatura: traço escuro sobre branco; e uma "assinatura" em branco
    $ass = imagecreatetruecolor(900, 260);
    imagefill($ass, 0, 0, imagecolorallocate($ass, 255, 255, 255));
    imagesetthickness($ass, 4);
    imageline($ass, 80, 180, 820, 90, imagecolorallocate($ass, 10, 10, 10));
    ob_start(); imagepng($ass); $assinatura = 'data:image/png;base64,' . base64_encode(ob_get_clean());
    $branca = imagecreatetruecolor(900, 260);
    imagefill($branca, 0, 0, imagecolorallocate($branca, 255, 255, 255));
    ob_start(); imagepng($branca); $assinaturaBranca = 'data:image/png;base64,' . base64_encode(ob_get_clean());

    $L141 = dbv('SELECT id FROM locacoes WHERE tenant_id = :t AND numero = 141', ['t' => $alfaId]);
    $campos = ['id' => $L141, 'tipo' => 'retirada', 'obs' => 'TESTE vistoria', 'assinante_nome' => 'Responsável da Construtora'];
    foreach (db('SELECT id FROM locacao_itens WHERE locacao_id = :l', ['l' => $L141])->fetchAll(PDO::FETCH_COLUMN) as $k => $iid) {
        $campos["estado[$iid]"] = $k === 0 ? 'avariado' : 'ok';
        $campos["obs_item[$iid]"] = $k === 0 ? 'TESTE risco na lateral' : '';
    }
    $pgV = 'index.php?p=locacoes&a=vistoria&n=141&tipo=retirada';
    $acaoV = 'index.php?p=locacoes&a=vistoria_salvar';
    $fotos = [['caminho' => "$tmp/teste-foto1.jpg", 'tipo' => 'image/jpeg', 'nome' => 'foto1.jpg'],
              ['caminho' => "$tmp/teste-foto2.png", 'tipo' => 'image/png', 'nome' => 'foto2.png']];
    $antes = $contarArquivos();

    // Erros: nada pode sobrar no disco
    $alfa->enviarArquivos($pgV, $acaoV, $campos + ['assinatura' => $assinaturaBranca], $fotos);
    ok(str_contains($alfa->mensagem($pgV), 'assinatura está em branco'), 'assinatura em branco é recusada');
    $alfa->enviarArquivos($pgV, $acaoV, ['assinante_nome' => ''] + $campos + ['assinatura' => $assinatura], $fotos);
    ok($alfa->mensagem($pgV) === 'Informe o nome de quem assinou.', 'assinatura sem o nome de quem assinou é recusada');
    $alfa->enviarArquivos($pgV, $acaoV, $campos, [['caminho' => "$tmp/teste-falsa.jpg", 'tipo' => 'image/jpeg', 'nome' => 'falsa.jpg']]);
    ok(str_contains($alfa->mensagem($pgV), 'não é uma imagem válida'), 'arquivo de texto disfarçado de .jpg é recusado');
    $semEstado = array_filter($campos, fn($k) => !str_starts_with($k, 'estado['), ARRAY_FILTER_USE_KEY);
    $alfa->enviarArquivos($pgV, $acaoV, $semEstado, $fotos);
    ok(str_starts_with($alfa->mensagem($pgV), 'Informe o estado de'), 'sem o estado de cada equipamento, é recusada');
    ok($contarArquivos() === $antes && dbv('SELECT count(*) FROM vistorias WHERE criado_em >= :i', ['i' => $inicio]) == 0,
       'depois dos erros, nenhum arquivo nem registro ficou para trás');

    // Vistoria completa
    $r = $alfa->enviarArquivos($pgV, $acaoV, $campos + ['assinatura' => $assinatura], $fotos);
    $vis = db("SELECT v.id, v.assinatura_arquivo, v.assinante_nome, (SELECT count(*) FROM vistoria_fotos f WHERE f.vistoria_id = v.id) AS fotos,
                      (SELECT count(*) FROM vistoria_itens i WHERE i.vistoria_id = v.id) AS itens,
                      (SELECT count(*) FROM vistoria_itens i WHERE i.vistoria_id = v.id AND i.estado = 'avariado') AS avariados
                 FROM vistorias v WHERE v.locacao_id = :l AND v.tipo = 'retirada'", ['l' => $L141])->fetch();
    ok(str_contains($r['local'], 'a=ver&n=141') && $vis && (int) $vis['fotos'] === 2 && (int) $vis['itens'] === 3 && (int) $vis['avariados'] === 1
       && $vis['assinatura_arquivo'] !== '', 'vistoria de retirada gravada: 3 itens (1 avariado), 2 fotos e assinatura', json_encode($vis));
    $foto = db('SELECT id, arquivo, largura, altura FROM vistoria_fotos WHERE vistoria_id = :v ORDER BY largura DESC LIMIT 1', ['v' => $vis['id']])->fetch();
    $bytes = (string) @file_get_contents("$pastaArq/{$foto['arquivo']}");
    ok($foto['largura'] == 1600 && $foto['altura'] == 1200 && str_starts_with($bytes, "\xFF\xD8"), 'foto 2000x1500 reduzida para 1600x1200 e regravada em JPEG');
    ok($bytes !== '' && str_contains($jpegComExif, $marca) && !str_contains($bytes, $marca),
       'metadados da foto (o "GPS" de teste) removidos no arquivo gravado');
    ok(preg_match('#^' . $alfaId . '/\d{4}/[0-9a-f]{32}\.jpg$#', $foto['arquivo']) === 1, 'arquivo na pasta da locadora, com nome aleatório');

    // Entrega dos arquivos: só para a própria locadora
    $r = $alfa->req("index.php?p=arquivos&a=foto&id={$foto['id']}");
    ok($r['codigo'] === 200 && str_contains($r['cabecalhos'], 'image/jpeg') && $r['corpo'] === $bytes, 'Alfa abre a foto (200, image/jpeg)');
    ok($beta->req("index.php?p=arquivos&a=foto&id={$foto['id']}")['codigo'] === 404, 'Beta pedindo a foto da Alfa: 404');
    ok($beta->req("index.php?p=arquivos&a=assinatura&id={$vis['id']}")['codigo'] === 404, 'Beta pedindo a assinatura da Alfa: 404');
    ok((new Navegador($BASE))->req("index.php?p=arquivos&a=foto&id={$foto['id']}")['codigo'] === 303, 'sem login: redireciona ao login');
    $direto = (new Navegador($BASE))->req("storage/{$foto['arquivo']}");
    if (str_contains($direto['cabecalhos'], 'Development Server') || ($direto['codigo'] === 200 && !str_contains(strtolower($direto['cabecalhos']), 'apache'))) {
        echo "  (pulado: servidor embutido do PHP não aplica o .htaccess da pasta storage)\n";
    } else {
        ok(in_array($direto['codigo'], [403, 404], true), "acesso direto à pasta storage é bloqueado ({$direto['codigo']})");
    }
    ok($beta->get("index.php?p=locacoes&a=vistoria_ver&id={$vis['id']}")['codigo'] === 404, 'Beta abrindo a vistoria da Alfa: 404');
    $pv = $alfa->get("index.php?p=locacoes&a=vistoria_ver&id={$vis['id']}")['corpo'];
    ok(substr_count($pv, 'p=arquivos&amp;a=foto') === 2 && str_contains($pv, 'p=arquivos&amp;a=assinatura') && str_contains(texto($pv), 'Avariado TESTE risco na lateral'),
       'página da vistoria: itens, 2 fotos e assinatura');
    $ct = $alfa->get('index.php?p=locacoes&a=contrato&n=141')['corpo'];
    ok(str_contains($ct, "a=assinatura&amp;id={$vis['id']}") && str_contains(texto($ct), 'Assinado eletronicamente por Responsável da Construtora'),
       'contrato mostra a assinatura do cliente');
    $alfa->enviarArquivos($pgV, $acaoV, $campos + ['assinatura' => $assinatura], []);
    ok(str_contains($alfa->mensagem('index.php?p=locacoes&a=ver&n=141'), 'já tem vistoria de retirada'), 'segunda vistoria de retirada é recusada');
    $r = $alfa->enviarArquivos('index.php?p=locacoes&a=vistoria&n=141&tipo=devolucao', $acaoV, ['tipo' => 'devolucao'] + $campos, []);
    ok(dbv("SELECT count(*) FROM vistorias WHERE locacao_id = :l AND tipo = 'devolucao'", ['l' => $L141]) == 1, 'vistoria de devolução (sem fotos nem assinatura) aceita');
    $dv = $alfa->get('index.php?p=locacoes&a=ver&n=141')['corpo'];
    ok(str_contains($dv, 'Vistorias (checklist, fotos e assinatura)') && !str_contains($dv, '>Vistoria de retirada</a>'),
       'detalhe lista as vistorias e não oferece outra de retirada');
    foreach (["$tmp/teste-foto1.jpg", "$tmp/teste-foto2.png", "$tmp/teste-falsa.jpg"] as $f) {
        @unlink($f);
    }
}

// =====================================================================
secao('PIX (BR Code copia e cola + QR Code)');
require_once __DIR__ . '/../app/pix.php';
ok(pix_crc16('123456789') === '29B1', 'CRC16-CCITT confere com o valor padrão de verificação (29B1)');
ok(pix_payload('123e4567-e12b-12d1-a456-426655440000', 'Fulano de Tal', 'BRASILIA')
   === '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***63041D3D',
   'código idêntico ao exemplo do manual do Banco Central');
$crcValido = fn(string $p) => strlen($p) > 8 && pix_crc16(substr($p, 0, -4)) === substr($p, -4);
preg_match('/data-pix="([^"]+)"/', $alfa->get("index.php?p=locacoes&a=fatura&n=$N")['corpo'], $m);
$payload = html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
ok(str_contains($payload, '0114114447770001615') && str_contains($payload, '5406120.00') && str_contains($payload, '0506LOC' . $N) && $crcValido($payload),
   "fatura nº $N: PIX com a chave da Alfa, valor do saldo (120.00), identificação LOC$N e CRC válido");
$linksSaldo = $links($alfa->get("index.php?p=locacoes&a=ver&n=$N")['corpo'])['5585997015566'] ?? [];
ok((bool) array_filter($linksSaldo, fn($t) => str_contains($t, 'PIX Copia e Cola') && str_contains($t, $payload)),
   'cobrança pelo WhatsApp leva o código PIX copia e cola');
ok(str_contains($alfa->get('index.php?p=locacoes&a=ver&n=141')['corpo'], 'data-pix="'), 'locação aberta com saldo mostra o QR Code');
// Beta sem chave PIX: nada de QR, e o admin vê o aviso para configurar
$nBeta = dbv("SELECT l.numero FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
               WHERE l.tenant_id = :t AND l.status = 'aberta' AND v.valor_atual > v.valor_pago ORDER BY l.numero LIMIT 1", ['t' => $betaId]);
$pb = $beta->get("index.php?p=locacoes&a=ver&n=$nBeta")['corpo'];
ok(!str_contains($pb, 'data-pix=') && str_contains($pb, 'Cadastre a chave PIX'), 'locadora sem chave PIX: sem QR Code, com orientação ao admin');
// Validação da chave em Configurações (na Beta; a limpeza devolve a chave vazia)
$empBeta = db('SELECT * FROM empresa WHERE tenant_id = :t', ['t' => $betaId])->fetch();
$form = array_intersect_key($empBeta, array_flip(['razao_social', 'nome_fantasia', 'cnpj', 'ie', 'telefone', 'email', 'endereco',
                                                    'cidade', 'uf', 'cep', 'termos_contrato', 'obs_fatura']));
$beta->enviar('index.php?p=config', 'index.php?p=config&a=empresa_salvar', $form + ['pix_chave' => 'chave-errada']);
ok(str_contains($beta->mensagem('index.php?p=config'), 'Chave PIX inválida'), 'chave PIX inválida é recusada');
$beta->enviar('index.php?p=config', 'index.php?p=config&a=empresa_salvar', $form + ['pix_chave' => '(85) 99999-0000', 'pix_nome' => 'Beta Eventos e Locações Ltda']);
$pixBeta = db('SELECT pix_chave, pix_nome, pix_cidade FROM empresa WHERE tenant_id = :t', ['t' => $betaId])->fetch();
ok($pixBeta['pix_chave'] === '+5585999990000' && $pixBeta['pix_nome'] === 'Beta Eventos e Locacoes L' && $pixBeta['pix_cidade'] === 'Fortaleza',
   'celular vira +5585999990000; nome sem acento cortado em 25; cidade da empresa', json_encode($pixBeta, JSON_UNESCAPED_UNICODE));

// =====================================================================
secao('Isolamento pelas telas e pela API');
$r = $beta->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(1), 'produto_id' => [$MES_BETA], 'quantidade' => ['5'],
]);
ok(num_locacao($r) === $base['beta-eventos']['loc'] + 1, 'numeração própria: a Beta recebe o próximo número dela (' . num_locacao($r) . ')');
$r = $beta->get("index.php?p=locacoes&a=ver&n=$N");
ok($r['codigo'] === 404 && str_contains($r['corpo'], 'Locação não encontrada.'), "Beta abrindo ?n=$N (da Alfa): 404 'Locação não encontrada.'");
ok($beta->get("index.php?p=locacoes&a=ver&id=$LOC")['codigo'] === 404, 'Beta abrindo o id (UUID) da locação da Alfa: 404');
ok($beta->get("index.php?p=locacoes&a=contrato&id=$LOC")['codigo'] === 404, 'Beta abrindo o contrato da Alfa: 404');
$beta->enviar('index.php?p=locacoes', 'index.php?p=locacoes&a=receber', ['id' => $LOC, 'valor' => '999', 'forma' => 'PIX', 'data' => dia(0)]);
ok(dbv('SELECT count(*) FROM caixa WHERE locacao_id = :l AND valor = 999', ['l' => $LOC]) == 0, 'Beta recebendo na locação da Alfa: nada gravado');
$api = $beta->get("index.php?p=api&a=disponivel&produto=$BET&inicio=" . dia(0) . '&fim=' . dia(1));
ok($api['codigo'] === 404, 'API: a Beta não enxerga produto da Alfa');
$api = json_decode($alfa->get("index.php?p=api&a=disponivel&produto=$BET&inicio=" . dia(0) . '&fim=' . dia(3))['corpo'], true);
ok(($api['disponivel'] ?? null) === 7, 'API: Alfa vê 7 betoneiras disponíveis até +3 (8 - 1 atrasada)', json_encode($api));

// =====================================================================
// Betoneiras: 8 no estoque, 1 na rua (locação atrasada da demo) e 2 na reserva nº 1 da demo (+7..+9)
secao('Reservas: bloqueio de estoque e conversão');
$r = $alfa->enviar('index.php?p=reservas&a=nova', 'index.php?p=reservas&a=salvar', [
    'cliente' => $MARIA, 'data_inicio' => dia(20), 'data_fim' => dia(21), 'produto_id' => [$BET], 'quantidade' => ['5'], 'obs' => 'TESTE',
]);
$NR = num_locacao($r);
ok($NR !== null, "reserva nº $NR de 5 betoneiras em +20..+21");
$alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(20), 'produto_id' => [$BET], 'quantidade' => ['1'],
]);
ok(str_contains($alfa->mensagem('index.php?p=locacoes&a=nova'), 'Betoneira 400 L (pedido 1, disponível 0)'),
   'a reserva bloqueia o estoque: locação até +20 recusada (8 - 1 na rua - 2 - 5 reservadas = 0)');
$RID = dbv('SELECT id FROM reservas WHERE tenant_id = :t AND numero = :n', ['t' => $alfaId, 'n' => $NR]);
$r = $alfa->enviar("index.php?p=locacoes&a=nova&reserva=$RID", 'index.php?p=locacoes&a=criar', [
    'reserva_id' => $RID, 'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(21),
    'produto_id' => [$BET], 'quantidade' => ['5'],
]);
ok(num_locacao($r) !== null && dbv('SELECT status FROM reservas WHERE id = :id', ['id' => $RID]) === 'convertida',
   'Retirar → locação: a própria reserva não bloqueia e vira "convertida"');

// =====================================================================
secao('Caixa e perfis');
$alfa->enviar('index.php?p=config&a=usuarios', 'index.php?p=config&a=usuario_salvar',
              ['nome' => 'TESTE Operador', 'login' => 'teste.operador', 'perfil' => 'operador', 'senha' => 'temporaria1', 'ativo' => '1']);
ok(dbv("SELECT trocar_senha FROM usuarios WHERE login = 'teste.operador'") === true, 'operador criado pelo admin fica obrigado a trocar a senha');
$op = new Navegador($BASE);
$op->entrar('alfa-locacoes', 'teste.operador', 'temporaria1');
ok(str_contains($op->req('index.php?p=caixa')['local'], 'p=config&a=senha'), 'com senha temporária, o caixa redireciona para a troca de senha');
$op->enviar('index.php?p=config&a=senha', 'index.php?p=config&a=senha_salvar',
            ['senha_atual' => 'temporaria1', 'senha_nova' => 'Operador2026', 'senha_confirma' => 'Operador2026']);
ok($op->get('index.php?p=caixa')['codigo'] === 200, 'depois de trocar a senha, o caixa abre');
$alfa->enviar('index.php?p=caixa', 'index.php?p=caixa&a=lancar',
              ['data' => dia(0), 'tipo' => 'S', 'categoria' => 'Teste', 'descricao' => 'TESTE despesa', 'valor' => '12,34', 'forma' => 'PIX']);
$LID = dbv("SELECT id FROM caixa WHERE descricao = 'TESTE despesa'");
$op->enviar('index.php?p=caixa', 'index.php?p=caixa&a=excluir', ['id' => $LID]);
ok($op->mensagem('index.php?p=caixa') === 'Acesso restrito ao administrador.' && dbv('SELECT count(*) FROM caixa WHERE id = :i', ['i' => $LID]) == 1,
   'operador NÃO exclui lançamento (mesmo forçando o POST)');
$alfa->enviar('index.php?p=caixa', 'index.php?p=caixa&a=excluir', ['id' => dbv('SELECT id FROM caixa WHERE locacao_id = :l LIMIT 1', ['l' => $LOC])]);
ok(str_starts_with($alfa->mensagem('index.php?p=caixa'), 'Lançamentos de locação não podem ser excluídos.'), 'admin NÃO exclui lançamento ligado a locação');
$alfa->enviar('index.php?p=caixa', 'index.php?p=caixa&a=excluir', ['id' => $LID]);
ok(dbv('SELECT count(*) FROM caixa WHERE id = :i', ['i' => $LID]) == 0, 'admin exclui lançamento manual');

// =====================================================================
secao('Auditoria');
$aud = fn(string $cond, array $p = []) => db("SELECT * FROM auditoria WHERE tenant_id = :t AND quando >= :i AND $cond ORDER BY quando, id",
                                             ['t' => $alfaId, 'i' => $inicio] + $p)->fetchAll();
$ins = $aud("tabela = 'caixa' AND registro_id = :r", ['r' => $LID]);
$dx = json_decode($ins[1]['dados'] ?? '{}', true);
ok(count($ins) === 2 && $ins[0]['acao'] === 'I' && $ins[1]['acao'] === 'D' && $ins[1]['usuario'] === 'admin'
   && $ins[1]['rotulo'] === 'TESTE despesa' && (float) ($dx['valor'] ?? 0) === 12.34,
   'lançamento de caixa: inclusão e exclusão registradas, com usuário e valor', json_encode($ins));
$sx = $aud("tabela = 'usuarios' AND acao = 'U' AND rotulo = 'teste.operador'");
ok(count($sx) >= 1 && $sx[0]['usuario'] === 'teste.operador' && (json_decode($sx[0]['dados'], true)['senha_hash'] ?? null) === ['***', '***']
   && !str_contains($sx[0]['dados'], '$2y$'), 'troca de senha registrada sem o hash da senha');
$antes = count($aud("tabela = 'usuarios'"));
(new Navegador($BASE))->entrar('alfa-locacoes', 'admin', 'admin123');
ok(count($aud("tabela = 'usuarios'")) === $antes, 'login (só o último acesso muda) não entra na auditoria');
$lx = $aud("tabela = 'locacoes' AND rotulo = :n", ['n' => (string) $N]);
ok(count($lx) >= 2 && $lx[0]['acao'] === 'I' && end($lx)['acao'] === 'U' && end($lx)['usuario'] === 'admin'
   && (json_decode(end($lx)['dados'], true)['status'] ?? null) === ['aberta', 'devolvida'], "locação nº $N: criação e mudança de situação (aberta → devolvida)");
$alfa->enviar('index.php?p=clientes&a=novo', 'index.php?p=clientes&a=salvar',
              ['tipo' => 'PF', 'nome' => 'TESTE Auditoria', 'cpf_cnpj' => '390.533.447-05', 'celular' => '(85) 98888-7777', 'ativo' => '1']);
$CA = dbv("SELECT id FROM clientes WHERE nome = 'TESTE Auditoria'");
$alfa->enviar("index.php?p=clientes&a=editar&id=$CA", 'index.php?p=clientes&a=salvar',
              ['id' => $CA, 'tipo' => 'PF', 'nome' => 'TESTE Auditoria Silva', 'cpf_cnpj' => '390.533.447-05', 'celular' => '(85) 97777-6666', 'ativo' => '1']);
$cx = $aud("tabela = 'clientes' AND registro_id = :r", ['r' => $CA]);
$c0 = json_decode($cx[0]['dados'] ?? '{}', true);
$c1 = json_decode($cx[1]['dados'] ?? '{}', true);
ok(count($cx) === 2 && $c0['cpf_cnpj'] === '***' && $c0['celular'] === '***' && $c0['nome'] === 'TESTE Auditoria'
   && !str_contains($cx[0]['dados'] . $cx[1]['dados'], '39053344705') && !str_contains($cx[0]['dados'] . $cx[1]['dados'], '98888'),
   'cliente novo: CPF e celular ficam ocultos na auditoria');
ok(($c1['nome'] ?? null) === ['TESTE Auditoria', 'TESTE Auditoria Silva'] && ($c1['celular'] ?? null) === ['***', '***'] && !isset($c1['cpf_cnpj']),
   'cliente alterado: nome com antes/depois, celular só "alterado", CPF igual não aparece', json_encode($c1));
$tela = $alfa->get("index.php?p=config&a=auditoria&registro=$CA&de=2000-01-01")['corpo'];
ok(str_contains($tela, '2 registro(s)') && str_contains(texto($tela), 'nome: TESTE Auditoria → TESTE Auditoria Silva')
   && str_contains($tela, '(oculto, alterado)'), 'tela de auditoria (admin): histórico do cliente com antes → depois');
$tela = $op->get('index.php?p=config&a=auditoria&de=2000-01-01')['corpo'];
ok(str_contains($tela, 'Acesso restrito ao administrador.') && !str_contains($tela, 'TESTE Auditoria'), 'operador não abre a auditoria');
ok(!str_contains($beta->get('index.php?p=config&a=auditoria&de=2000-01-01')['corpo'], 'TESTE Auditoria'), 'Beta não vê a auditoria da Alfa');

// =====================================================================
secao('Contas a pagar, recorrentes e previsão de caixa');
$contas = 'index.php?p=caixa&a=contas';
$alfa->enviar($contas, 'index.php?p=caixa&a=conta_salvar',
              ['descricao' => 'TESTE luz de outubro', 'categoria' => 'TESTE Energia', 'valor' => '200,00', 'vencimento' => dia(-2)]);
$LUZ = dbv("SELECT id FROM contas_pagar WHERE tenant_id = :t AND descricao = 'TESTE luz de outubro'", ['t' => $alfaId]);
ok($LUZ !== null && str_contains(texto($alfa->get($contas)['corpo']), 'TESTE luz de outubro TESTE Energia R$ 200,00 vencida'), 'conta avulsa vencida aparece como vencida');
$rec = ['descricao' => 'TESTE aluguel do galpão', 'categoria' => 'TESTE Aluguel', 'valor' => '1.500,00', 'dia' => '15', 'inicio' => dia(-60), 'ativa' => '1'];
$op->enviar('index.php?p=caixa', 'index.php?p=caixa&a=recorrente_salvar', ['id' => ''] + $rec);
ok(str_contains($op->mensagem('index.php?p=caixa'), 'Acesso restrito ao administrador.'), 'operador não cria conta recorrente');
$alfa->enviar($contas, 'index.php?p=caixa&a=recorrente_salvar', ['id' => ''] + $rec);
$REC = dbv("SELECT id FROM contas_recorrentes WHERE tenant_id = :t AND descricao = 'TESTE aluguel do galpão'", ['t' => $alfaId]);
$parcelas = fn() => db('SELECT id, vencimento, valor FROM contas_pagar WHERE recorrente_id = :r ORDER BY vencimento', ['r' => $REC])->fetchAll();
$pc = $parcelas();
$esperadas = [];
for ($m = 0; $m < 5; $m++) {
    $v = date('Y-m-15', strtotime(date('Y-m-01') . " +$m month"));
    if ($v >= dia(0) && $v <= dia(90)) {
        $esperadas[] = $v;
    }
}
ok(array_column($pc, 'vencimento') === $esperadas && count(array_unique(array_column($pc, 'valor'))) === 1 && $pc[0]['valor'] === '1500.00',
   'recorrente gera as parcelas do dia 15 de hoje até 90 dias (nenhuma vencida, mesmo com início há 60 dias)', json_encode(array_column($pc, 'vencimento')));
$alfa->get($contas);
$alfa->get('index.php?p=caixa&a=previsao');
ok(count($parcelas()) === count($esperadas), 'abrir as telas de novo não duplica parcelas');
$ultima = end($pc)['id'];
$alfa->enviar($contas, 'index.php?p=caixa&a=conta_excluir', ['id' => $ultima]);
$alfa->get($contas);
ok(count($parcelas()) === count($esperadas) - 1, 'parcela excluída não volta');
$alfa->enviar($contas, 'index.php?p=caixa&a=recorrente_salvar', ['id' => $REC, 'valor' => '1.600,00'] + $rec);
ok(array_unique(array_column($parcelas(), 'valor')) === ['1600.00'], 'novo valor da recorrente vale para as parcelas em aberto');

// Pagar, não pagar duas vezes, desfazer
$op->enviar('index.php?p=caixa', 'index.php?p=caixa&a=conta_pagar', ['id' => $LUZ, 'data' => dia(0), 'forma' => 'Boleto']);
$pg = db('SELECT c.pago_em, x.tipo, x.valor, x.categoria, x.forma FROM contas_pagar c JOIN caixa x ON x.id = c.caixa_id WHERE c.id = :id', ['id' => $LUZ])->fetch();
ok($pg && $pg['pago_em'] === dia(0) && $pg['tipo'] === 'S' && $pg['valor'] === '200.00' && $pg['categoria'] === 'TESTE Energia' && $pg['forma'] === 'Boleto',
   'operador paga a conta: saída de R$ 200,00 no caixa com a categoria da conta', json_encode($pg));
$op->enviar('index.php?p=caixa', 'index.php?p=caixa&a=conta_pagar', ['id' => $LUZ, 'data' => dia(0), 'forma' => 'PIX']);
ok(str_contains($op->mensagem('index.php?p=caixa'), 'já foi paga'), 'não paga duas vezes');
$cxLuz = dbv('SELECT caixa_id FROM contas_pagar WHERE id = :id', ['id' => $LUZ]);
$alfa->enviar('index.php?p=caixa', 'index.php?p=caixa&a=excluir', ['id' => $cxLuz]);
ok(str_contains($alfa->mensagem('index.php?p=caixa'), 'Desfazer pagamento'), 'o lançamento do pagamento não é excluído pelo extrato');
$op->enviar('index.php?p=caixa', 'index.php?p=caixa&a=conta_desfazer', ['id' => $LUZ]);
ok(str_contains($op->mensagem('index.php?p=caixa'), 'Acesso restrito') && dbv('SELECT pago_em FROM contas_pagar WHERE id = :id', ['id' => $LUZ]) === dia(0),
   'operador não desfaz pagamento');
$alfa->enviar($contas, 'index.php?p=caixa&a=conta_desfazer', ['id' => $LUZ]);
ok(dbv('SELECT pago_em FROM contas_pagar WHERE id = :id', ['id' => $LUZ]) === null && dbv('SELECT count(*) FROM caixa WHERE id = :c', ['c' => $cxLuz]) == 0,
   'admin desfaz: a saída sai do caixa e a conta volta a ficar em aberto');

// Previsão: saldo de hoje = caixa; semanas fecham com o saldo final; conta vencida na 1ª semana; reserva estimada
$moeda = fn(string $v) => (float) str_replace(['R$', ' ', "\u{a0}", '.', ','], ['', '', '', '', '.'], $v);
$prev = texto($alfa->get('index.php?p=caixa&a=previsao&dias=60')['corpo']);
$saldoDb = (float) dbv("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) FROM caixa WHERE tenant_id = :t AND data <= CURRENT_DATE", ['t' => $alfaId]);
preg_match('/Saldo hoje (-?R\$ [\d.,]+)/u', $prev, $mh);
preg_match('/Saldo em 60 dias (-?R\$ [\d.,]+)/u', $prev, $mf);
preg_match_all('#(\d{2}/\d{2}/\d{4}) a (\d{2}/\d{2}/\d{4}) (-?R\$ [\d.,]+) (-?R\$ [\d.,]+) (-?R\$ [\d.,]+)#u', $prev, $sem, PREG_SET_ORDER);
$hoje = $moeda($mh[1] ?? 'x');
$somaE = array_sum(array_map(fn($x) => $moeda($x[3]), $sem));
$somaS = array_sum(array_map(fn($x) => $moeda($x[4]), $sem));
ok(abs($hoje - $saldoDb) < 0.01 && count($sem) === 9 && abs($hoje + $somaE - $somaS - $moeda(end($sem)[5])) < 0.01
   && abs($moeda(end($sem)[5]) - $moeda($mf[1] ?? 'x')) < 0.01,
   'previsão: saldo de hoje = caixa, 9 semanas, saldo final = hoje + entradas − saídas', "hoje $hoje / db $saldoDb / semanas " . count($sem));
ok(str_contains($prev, 'vencido TESTE luz de outubro R$ 200,00') && $moeda($sem[0][4]) >= 200, 'conta vencida entra como saída na primeira semana');
preg_match('/Menor saldo no período (-?R\$ [\d.,]+)/u', $prev, $mm);
ok(abs($moeda($mm[1] ?? 'x') - min(array_merge([$hoje], array_map(fn($x) => $moeda($x[5]), $sem)))) < 0.01, 'menor saldo considera o saldo de hoje e o de cada semana');
$r1 = db("SELECT status, data_fim FROM reservas WHERE tenant_id = :t AND numero = 1", ['t' => $alfaId])->fetch();
ok($r1['status'] !== 'ativa' || str_contains($prev, 'Reserva nº 1 - Francisco das Chagas Pinheiro (estimado) R$ 360,00'),
   'reserva ativa entra estimada pelos preços (2 betoneiras + 4 carrinhos + 20 painéis por 2 dias = R$ 360,00)');
ok(!str_contains(texto($beta->get('index.php?p=caixa&a=previsao')['corpo']), 'TESTE luz') && !str_contains($beta->get('index.php?p=caixa&a=contas')['corpo'], 'TESTE aluguel'),
   'Beta não vê contas nem previsão da Alfa');
$alfa->enviar($contas, 'index.php?p=caixa&a=recorrente_salvar', ['id' => $REC, 'ativa' => ''] + $rec);
ok(dbv('SELECT count(*) FROM contas_pagar WHERE recorrente_id = :r AND pago_em IS NULL AND vencimento >= CURRENT_DATE', ['r' => $REC]) == 0,
   'desativar a recorrente remove as parcelas futuras em aberto');

// =====================================================================
secao('Busca global');
$buscar = fn(Navegador $n, string $q) => $n->enviar('index.php?p=dashboard', 'index.php?p=busca&a=buscar', ['q' => $q]);
ok(str_contains($alfa->get('index.php?p=dashboard')['corpo'], 'class="busca-topo"'), 'campo de busca no topo das telas');
$r = $buscar($alfa, '111.444.777-35');
$fim = $alfa->req('index.php?p=busca');
$urls = urldecode($r['local'] . ' ' . $fim['local']);
ok(str_ends_with($r['local'], 'p=busca') && !str_contains($urls, '11144477735') && !str_contains($urls, '111.444')
   && str_contains($fim['local'], 'p=clientes&a=ver&id='), 'busca por CPF: vai por POST, a URL não leva o CPF e o único resultado abre direto');
$buscar($alfa, "#$N");
ok(str_contains($alfa->req('index.php?p=busca')['local'], "p=locacoes&a=ver&n=$N"), "\"#$N\" abre a locação nº $N");
$buscar($alfa, '98620');
ok(str_contains($alfa->req('index.php?p=busca')['local'], 'p=clientes&a=ver&id='), 'busca pelo trecho do celular acha o cliente');
$buscar($alfa, 'teste-p');
$t = texto($alfa->get('index.php?p=busca')['corpo']);
ok(str_contains($t, 'Produtos (1) TESTE-PAT — TESTE gerador com patrimônio') && str_contains($t, 'Unidade TESTE-P1 TESTE gerador com patrimônio · série SN-teste-p1 · baixada')
   && str_contains($t, 'Unidade TESTE-P3'), 'resultados agrupados: produto e unidades (com a baixada marcada)', $t);
$buscar($beta, 'teste-p');
ok(str_contains(texto($beta->get('index.php?p=busca')['corpo']), 'Nada encontrado'), 'Beta não acha produto nem unidade da Alfa');
$buscar($beta, "#$N");
ok(!str_contains($beta->req('index.php?p=busca')['local'], "n=$N") || dbv('SELECT count(*) FROM locacoes WHERE tenant_id = :t AND numero = :n', ['t' => $betaId, 'n' => $N]) == 1,
   'Beta não abre locação da Alfa pela busca');
$buscar($alfa, 'x');
ok(str_contains($alfa->get('index.php?p=busca')['corpo'], 'pelo menos 2 caracteres'), 'termo de 1 caractere pede mais');

// =====================================================================
secao('Roteiro do motorista');
$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(2), 'local_uso' => 'TESTE Rua das Flores, 100 - Fortaleza/CE',
    'produto_id' => [$CAR], 'quantidade' => ['1'], 'diaria' => [''],
]);
$NR8 = num_locacao($r);
$LR8 = $valores($NR8, $alfaId)['id'];
$rot = $alfa->get('index.php?p=roteiro')['corpo'];
$trot = texto($rot);
ok(str_contains($trot, "Entrega · nº $NR8 pendente Maria Lúcia Bezerra TESTE Rua das Flores, 100 - Fortaleza/CE Levar: 1 Carrinho de mão 60 L")
   && str_contains($rot, 'maps/search/?api=1&amp;query=TESTE%20Rua%20das%20Flores%2C%20100%20-%20Fortaleza%2FCE')
   && str_contains($rot, 'Estamos%20a%20caminho'), 'roteiro de hoje: entrega com endereço, itens, mapa e WhatsApp pronto');
$atrasadas = (int) dbv("SELECT count(*) FROM locacoes WHERE tenant_id = :t AND status = 'aberta' AND data_prev_devolucao < CURRENT_DATE", ['t' => $alfaId]);
ok($atrasadas > 0 && substr_count($trot, 'atrasada desde') === $atrasadas, "coletas de hoje incluem as $atrasadas locação(ões) atrasada(s)");
ok(str_contains(texto($alfa->get('index.php?p=roteiro&data=' . dia(2))['corpo']), "Coleta · nº $NR8"), 'a coleta aparece no dia da devolução prevista');
$marcar = fn(Navegador $n, string $tipo, string $data, string $obs = '') => $n->enviar('index.php?p=roteiro', 'index.php?p=roteiro&a=marcar',
                                                                                     ['id' => $LR8, 'tipo' => $tipo, 'data' => $data, 'obs' => $obs]);
$marcar($op, 'entrega', dia(0), 'TESTE deixado com o mestre de obras');
$marcar($op, 'entrega', dia(0));
$e = db('SELECT e.obs, u.login FROM entregas e JOIN usuarios u ON u.id = e.usuario_id WHERE e.locacao_id = :l', ['l' => $LR8])->fetchAll();
ok(count($e) === 1 && $e[0]['login'] === 'teste.operador' && $e[0]['obs'] === 'TESTE deixado com o mestre de obras'
   && str_contains(texto($alfa->get('index.php?p=roteiro')['corpo']), "Entrega · nº $NR8 feita"),
   'operador marca "Entregue" (com quem e observação); marcar de novo não duplica');
$marcar($alfa, 'coleta', dia(2));
ok(str_contains($alfa->mensagem('index.php?p=roteiro'), 'hoje ou de dias anteriores'), 'parada de dia futuro não é marcada');
$marcar($alfa, 'coleta', dia(0));
ok(str_contains($alfa->mensagem('index.php?p=roteiro'), 'não está no roteiro do dia'), 'parada que não está no roteiro do dia é recusada');
$marcar($beta, 'entrega', dia(0));
ok(str_contains($beta->mensagem('index.php?p=roteiro'), 'não está no roteiro do dia') && !str_contains($beta->get('index.php?p=roteiro')['corpo'], 'TESTE Rua das Flores'),
   'Beta não vê nem marca parada da Alfa');
$alfa->enviar('index.php?p=roteiro', 'index.php?p=roteiro&a=desmarcar', ['id' => $LR8, 'tipo' => 'entrega', 'data' => dia(0)]);
ok(dbv('SELECT count(*) FROM entregas WHERE locacao_id = :l', ['l' => $LR8]) == 0, 'desfazer apaga o registro da parada');

// =====================================================================
secao('Aplicativo (PWA)');
$anon = new Navegador($BASE);
$man = $anon->req('manifest.webmanifest');
$mj = json_decode($man['corpo'], true);
$iconesOk = is_array($mj) && count($mj['icons'] ?? []) === 3
            && array_reduce($mj['icons'], fn($ok, $i) => $ok && $anon->req($i['src'])['codigo'] === 200, true);
ok($man['codigo'] === 200 && ($mj['start_url'] ?? '') === 'index.php' && ($mj['display'] ?? '') === 'standalone' && $iconesOk,
   'manifesto válido, abre em tela cheia e os 3 ícones existem');
$sw = $anon->req('sw.js');
ok($sw['codigo'] === 200 && preg_match('/content-type:\s*(text|application)\/javascript/i', $sw['cabecalhos']) === 1
   && str_contains($sw['corpo'], "req.mode === 'navigate'") && !str_contains($sw['corpo'], 'index.php'),
   'service worker servido como JavaScript (páginas sempre da rede, nunca do cache)');
ok($anon->req('offline.html')['codigo'] === 200 && str_contains($anon->req('offline.html')['corpo'], 'Sem conexão'), 'página "sem conexão" existe');
$login = $anon->get('index.php?p=login')['corpo'];
$painel = $alfa->get('index.php?p=dashboard')['corpo'];
ok(str_contains($login, 'rel="manifest"') && str_contains($painel, 'rel="manifest"') && str_contains($painel, 'assets/js/pwa.js'),
   'login e telas da locadora oferecem a instalação');

// Cadastro público vem desligado (o teste com ele ligado é tests/cadastro.php)
if (!($config['cadastro_publico'] ?? false)) {
    ok($anon->req('cadastro.php')['codigo'] === 404 && !str_contains($login, 'cadastro.php'), 'cadastro público desligado: 404 e sem link no login');
}

// =====================================================================
secao('Fretes de entrega e recolhimento, endereço de entrega');
$r = $alfa->enviar('index.php?p=locacoes&a=nova', 'index.php?p=locacoes&a=criar', [
    'cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(0), 'local_uso' => 'TESTE Obra Central, 10',
    'produto_id' => [$CAR], 'quantidade' => ['1'], 'diaria' => ['5,00'],
    'acrescimo' => '0', 'frete_entrega' => '50,00', 'frete_recolhimento' => '30,00', 'endereco_entrega' => '',
]);
$NF = num_locacao($r);
$vF = $valores($NF, $alfaId);
$LF = $vF['id'];
ok($vF && $vF['valor_previsto'] === '85.00', 'fretes entram no valor previsto: 1 diária de R$ 5 + R$ 50 + R$ 30 = R$ 85,00', json_encode($vF));
$endF = dbv('SELECT endereco_entrega FROM locacoes WHERE id = :id', ['id' => $LF]);
ok($endF === 'TESTE Obra Central, 10', 'endereço de entrega vazio usa o local de uso', (string) $endF);
$detF = texto($alfa->get("index.php?p=locacoes&a=ver&n=$NF")['corpo']);
ok(str_contains($detF, 'Frete de entrega R$ 50,00') && str_contains($detF, 'Frete de recolhimento R$ 30,00'), 'detalhe mostra os dois fretes');
ok(str_contains(texto($alfa->get("index.php?p=locacoes&a=contrato&n=$NF")['corpo']), 'Frete de entrega R$ 50,00')
   && str_contains(texto($alfa->get("index.php?p=locacoes&a=fatura&n=$NF")['corpo']), 'Frete de recolhimento R$ 30,00'), 'contrato e fatura trazem os fretes');
$alfa->enviar("index.php?p=locacoes&a=ver&n=$NF", 'index.php?p=locacoes&a=entrega_salvar',
              ['id' => $LF, 'frete_entrega' => '60,00', 'frete_recolhimento' => '30,00', 'endereco_entrega' => 'TESTE Galpão Norte, 99']);
$vF2 = $valores($NF, $alfaId);
ok($vF2['valor_previsto'] === '95.00' && dbv('SELECT endereco_entrega FROM locacoes WHERE id = :id', ['id' => $LF]) === 'TESTE Galpão Norte, 99',
   'editar o frete e o endereço da locação em aberto atualiza o valor e o endereço', json_encode($vF2));
ok(str_contains(texto($alfa->get("index.php?p=locacoes&a=ver&n=$NF")['corpo']), 'Entrega: frete de entrega R$ 50,00 → R$ 60,00'),
   'a mudança de frete fica no histórico da locação');
$beta->enviar("index.php?p=locacoes&a=ver&n=$NF", 'index.php?p=locacoes&a=entrega_salvar', ['id' => $LF, 'frete_entrega' => '1,00']);
ok(dbv('SELECT frete_entrega FROM locacoes WHERE id = :id', ['id' => $LF]) === '60.00', 'Beta não altera o frete de locação da Alfa');
$alfa->enviar("index.php?p=locacoes&a=ver&n=$NF", 'index.php?p=locacoes&a=entrega_salvar', ['id' => $LF, 'frete_entrega' => '-5']);
ok(str_contains($alfa->mensagem("index.php?p=locacoes&a=ver&n=$NF"), 'não podem ser negativos'), 'frete negativo é recusado');
ok(str_contains($alfa->get("index.php?p=locacoes&a=devolucao&n=$NF")['corpo'], 'data-acrescimo="90.00"'),
   'a devolução soma os fretes ao saldo (60 + 30 = 90)');

// =====================================================================
// =====================================================================
secao('Orçamentos: cotação, conversão em locação e em reserva');
$orcId = fn(string $loc) => preg_match('/id=([0-9a-f-]{36})/', $loc, $m) ? $m[1] : '';
$novoOrc = function (string $saida, string $validade, array $extra = [], string $prev = '') use ($alfa, $CAR, $MARIA, $orcId) {
    $r = $alfa->enviar('index.php?p=orcamentos&a=novo', 'index.php?p=orcamentos&a=salvar', $extra + [
        'cliente' => $MARIA, 'data_saida' => $saida, 'data_prev_devolucao' => $prev !== '' ? $prev : dia(0), 'validade' => $validade,
        'produto_id' => [$CAR], 'quantidade' => ['2'], 'diaria' => ['5,00'],
        'acrescimo' => '0', 'desconto' => '0', 'frete_entrega' => '10,00', 'frete_recolhimento' => '0', 'obs' => 'TESTE orçamento',
    ]);
    return $orcId($r['local']);
};
ok(str_contains($alfa->get('index.php?p=orcamentos&a=novo')['corpo'], 'Salvar orçamento') && str_contains($alfa->get('index.php?p=orcamentos&a=novo')['corpo'], 'id="tabela-itens"'),
   'tela de novo orçamento com itens e valores');
$O1 = $novoOrc(dia(-2), dia(7));
$v1 = texto($alfa->get("index.php?p=orcamentos&a=ver&id=$O1")['corpo']);
ok($O1 !== '' && str_contains($v1, 'Valor previsto R$ 30,00') && str_contains($v1, 'em aberto'),
   'orçamento salvo: 2 carrinhos × 2 dias × R$ 5 + frete de R$ 10 = R$ 30,00 (sem reservar estoque)', mb_substr($v1, 0, 300));
$lista = texto($alfa->get('index.php?p=orcamentos')['corpo']);
ok(str_contains($lista, 'Maria Lúcia Bezerra') && str_contains($lista, 'em aberto'), 'orçamento aparece na lista');
ok(str_contains($alfa->get("index.php?p=orcamentos&a=imprimir&id=$O1")['corpo'], 'Total previsto'), 'orçamento tem página para impressão');
// Converter em locação (saída já feita)
$r = $alfa->enviar("index.php?p=orcamentos&a=ver&id=$O1", 'index.php?p=orcamentos&a=converter', ['id' => $O1, 'tipo' => 'locacao']);
$LOC1 = db("SELECT l.id, l.numero, l.frete_entrega, l.obs FROM orcamentos o JOIN locacoes l ON l.id = o.locacao_id WHERE o.id = :id", ['id' => $O1])->fetch();
ok($LOC1 && (float) $LOC1['frete_entrega'] === 10.0 && str_contains($LOC1['obs'], 'Do orçamento nº'),
   'converter em locação: a locação recebe os itens, o frete e a observação do orçamento', json_encode($LOC1));
$vLoc = $valores((int) $LOC1['numero'], $alfaId);
ok($vLoc && $vLoc['valor_previsto'] === '30.00', 'a locação criada tem o mesmo valor previsto do orçamento (R$ 30,00)', json_encode($vLoc));
ok(dbv('SELECT status FROM orcamentos WHERE id = :id', ['id' => $O1]) === 'convertido', 'orçamento fica convertido');
$alfa->enviar("index.php?p=orcamentos&a=ver&id=$O1", 'index.php?p=orcamentos&a=converter', ['id' => $O1, 'tipo' => 'locacao']);
ok(str_contains($alfa->mensagem("index.php?p=orcamentos&a=ver&id=$O1"), 'já foi convertido'), 'não converte duas vezes');
$alfa->enviar("index.php?p=orcamentos&a=ver&id=$O1", 'index.php?p=orcamentos&a=excluir', ['id' => $O1]);
ok(str_contains($alfa->mensagem("index.php?p=orcamentos&a=ver&id=$O1"), 'Só é possível excluir') && dbv('SELECT count(*) FROM orcamentos WHERE id = :id', ['id' => $O1]) == 1,
   'orçamento convertido não pode ser excluído');
// Saída futura: só vira reserva
$O2 = $novoOrc(dia(3), dia(10), [], dia(6));
$alfa->enviar("index.php?p=orcamentos&a=ver&id=$O2", 'index.php?p=orcamentos&a=converter', ['id' => $O2, 'tipo' => 'locacao']);
ok(str_contains($alfa->mensagem("index.php?p=orcamentos&a=ver&id=$O2"), 'converta em reserva'), 'saída futura não vira locação');
$alfa->enviar("index.php?p=orcamentos&a=ver&id=$O2", 'index.php?p=orcamentos&a=converter', ['id' => $O2, 'tipo' => 'reserva']);
$RES2 = db("SELECT r.numero, r.data_inicio, r.data_fim, o.status FROM orcamentos o JOIN reservas r ON r.id = o.reserva_id WHERE o.id = :id", ['id' => $O2])->fetch();
ok($RES2 && $RES2['data_inicio'] === dia(3) && $RES2['status'] === 'convertido' && (int) dbv('SELECT count(*) FROM reserva_itens ri JOIN reservas r ON r.id = ri.reserva_id WHERE r.numero = :n', ['n' => $RES2['numero']]) == 1,
   'converter em reserva: datas e itens vão para a reserva', json_encode($RES2));
// Validade vencida recusa a conversão
$O3 = $novoOrc(dia(-1), dia(5));
db("UPDATE orcamentos SET validade = CURRENT_DATE - 1 WHERE id = :id", ['id' => $O3]);
$alfa->enviar("index.php?p=orcamentos&a=ver&id=$O3", 'index.php?p=orcamentos&a=converter', ['id' => $O3, 'tipo' => 'locacao']);
ok(str_contains($alfa->mensagem("index.php?p=orcamentos&a=ver&id=$O3"), 'vencido'), 'orçamento vencido não converte');
$alfa->enviar("index.php?p=orcamentos&a=ver&id=$O3", 'index.php?p=orcamentos&a=excluir', ['id' => $O3]);
ok(dbv('SELECT count(*) FROM orcamentos WHERE id = :id', ['id' => $O3]) == 0, 'orçamento em aberto pode ser excluído');
// Validade fora da regra e outra locadora
$alfa->enviar('index.php?p=orcamentos&a=novo', 'index.php?p=orcamentos&a=salvar', ['cliente' => $MARIA, 'data_saida' => dia(0), 'data_prev_devolucao' => dia(0),
    'validade' => dia(-3), 'produto_id' => [$CAR], 'quantidade' => ['1'], 'diaria' => ['5,00']]);
ok(str_contains($alfa->mensagem('index.php?p=orcamentos&a=novo'), 'validade deve ser'), 'validade no passado é recusada');
ok($beta->get("index.php?p=orcamentos&a=ver&id=$O2")['codigo'] === 404 && !str_contains($beta->get('index.php?p=orcamentos')['corpo'], 'TESTE orçamento'),
   'Beta não vê nem abre orçamento da Alfa');
// =====================================================================
secao('Repetir locação (novo contrato a partir de um existente)');
$detLoc = $alfa->get("index.php?p=locacoes&a=ver&n={$LOC1['numero']}")['corpo'];
ok(str_contains($detLoc, 'Repetir locação') && str_contains($detLoc, "copiar=$LOC1[id]"), 'a locação tem o botão "Repetir locação"');
$rep = $alfa->get("index.php?p=locacoes&a=nova&copiar=$LOC1[id]")['corpo'];
ok(str_contains($rep, 'value="Maria Lúcia Bezerra · 11144477735"') || str_contains($rep, 'Maria Lúcia Bezerra'),
   'repetir: cliente já preenchido');
ok(str_contains($rep, 'value="' . $CAR . '"') || str_contains($rep, 'value="' . $CAR . '" selected'), 'repetir: produto da locação original nos itens');
ok(preg_match('/name="quantidade\[\]" class="qtd" value="2"/', $rep) === 1, 'repetir: quantidades copiadas');
ok(str_contains($rep, 'value="' . date('Y-m-d') . '"'), 'repetir: saída é hoje');
ok(str_contains($rep, 'value="10,00"') && !str_contains($rep, 'name="acrescimo" id="acrescimo" value="30'),
   'repetir: fretes copiados e acréscimo/desconto não copiados');
ok($beta->get("index.php?p=locacoes&a=nova&copiar=$LOC1[id]")['codigo'] === 404, 'Beta não repete locação da Alfa (404)');
// =====================================================================
secao('Códigos de barras (principal e similares)');
$codBar = ['codigo' => 'TESTE-BAR', 'descricao' => 'TESTE produto com código de barras', 'unidade' => 'UN', 'qtd_total' => '2',
           'valor_diaria' => '10,00', 'ativo' => '1', 'categoria_id' => $catSem];
$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar', $codBar + ['codigos_barras' => "7891234567890\n12345"]);
$BAR = $produto($alfaId, 'TESTE-BAR');
ok($BAR && dbv('SELECT codigo FROM produto_barras WHERE produto_id = :p AND principal', ['p' => $BAR]) === '7891234567890',
   'o primeiro código digitado vira o principal');
ok($BAR && dbv('SELECT count(*) FROM produto_barras WHERE produto_id = :p', ['p' => $BAR]) == 2, 'os dois códigos são gravados');

$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar',
              ['codigo' => 'TESTE-BAR2', 'codigos_barras' => '1234 ABC12345'] + array_diff_key($codBar, ['codigo' => 1]));
ok(str_contains($alfa->mensagem('index.php?p=produtos&a=novo'), 'Código de barras inválido'), 'código com menos de 5 dígitos ou letras é recusado');
ok(dbv('SELECT count(*) FROM produtos WHERE codigo = :c', ['c' => 'TESTE-BAR2']) == 0, 'produto com código de barras inválido não é gravado');

$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar',
              ['codigo' => 'TESTE-BAR2', 'codigos_barras' => '99999, 99999'] + array_diff_key($codBar, ['codigo' => 1]));
ok(str_contains($alfa->mensagem('index.php?p=produtos&a=novo'), 'está repetido no produto'), 'código repetido dentro do mesmo produto é recusado');

$alfa->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar',
              ['codigo' => 'TESTE-BAR3', 'codigos_barras' => '7891234567890'] + array_diff_key($codBar, ['codigo' => 1]));
ok(str_contains($alfa->mensagem('index.php?p=produtos&a=novo'), 'já está em outro produto'), 'código já usado em outro produto da mesma locadora é recusado');
ok(dbv('SELECT count(*) FROM produtos WHERE codigo = :c', ['c' => 'TESTE-BAR3']) == 0, 'o produto recusado não é gravado pela metade');

$beta->enviar('index.php?p=produtos&a=novo', 'index.php?p=produtos&a=salvar',
              ['codigo' => 'TESTE-BAR', 'descricao' => 'TESTE produto da Beta com a mesma barra', 'unidade' => 'UN', 'qtd_total' => '1',
               'valor_diaria' => '10,00', 'ativo' => '1', 'codigos_barras' => '7891234567890']);
$BAR_BETA = $produto($betaId, 'TESTE-BAR');
ok($BAR_BETA && dbv('SELECT count(*) FROM produto_barras WHERE produto_id = :p', ['p' => $BAR_BETA]) == 1,
   'outra locadora pode usar o mesmo código de barras');

$alfa->enviar("index.php?p=produtos&a=editar&id=$BAR", 'index.php?p=produtos&a=salvar',
              ['id' => $BAR] + $codBar + ['codigos_barras' => '55555']);
ok(dbv('SELECT count(*) FROM produto_barras WHERE produto_id = :p', ['p' => $BAR]) == 1
   && dbv('SELECT codigo FROM produto_barras WHERE produto_id = :p', ['p' => $BAR]) === '55555',
   'ao salvar, a lista de códigos é substituída');

ok(str_contains($alfa->get('index.php?p=produtos&q=55555')['corpo'], 'TESTE produto com código de barras'),
   'busca de produtos encontra pelo código de barras');
ok(!str_contains($beta->get('index.php?p=produtos&q=55555')['corpo'], 'TESTE produto com código de barras'),
   'Beta não encontra produto da Alfa pelo código de barras');
$rBusca = $buscar($alfa, '55555');
$fimBusca = $alfa->req('index.php?p=busca');
ok(str_contains($rBusca['local'] . ' ' . $fimBusca['local'], "id=$BAR") || str_contains($fimBusca['corpo'], 'TESTE produto com código de barras'),
   'busca global encontra produto pelo código de barras (abre direto se for o único resultado)');

// =====================================================================
secao('Painel, relatórios e CSV');
$k = db("SELECT (SELECT count(*) FROM locacoes WHERE tenant_id = :t1 AND status = 'aberta') AS abertas,
                (SELECT COALESCE(sum(greatest(valor_atual - valor_pago, 0)), 0) FROM vw_locacao_valores WHERE tenant_id = :t2 AND status <> 'cancelada') AS receber",
        ['t1' => $betaId, 't2' => $betaId])->fetch();
$tp = texto($beta->get('index.php?p=dashboard')['corpo']);
$receber = 'R$ ' . number_format((float) $k['receber'], 2, ',', '.');
ok(str_contains($tp, "Locações em aberto {$k['abertas']} ") && str_contains($tp, "Total a receber $receber"),
   "painel da Beta = números só da Beta ({$k['abertas']} abertas, a receber $receber)");
foreach (['atraso', 'debito', 'estoque', 'mais_alugados', 'sem_movimento', 'faturamento', 'clientes_top', 'periodo',
          'rentabilidade', 'rentabilidade_unidade'] as $rel) {
    $t1 = $alfa->get("index.php?p=relatorios&a=ver&r=$rel")['codigo'];
    $t2 = $alfa->get("index.php?p=relatorios&a=ver&r=$rel&imprimir=1")['codigo'];
    $csv = $alfa->req("index.php?p=relatorios&a=ver&r=$rel&csv=1");
    ok($t1 === 200 && $t2 === 200 && $csv['codigo'] === 200 && str_starts_with($csv['corpo'], "\xEF\xBB\xBF")
       && str_contains($csv['cabecalhos'], 'text/csv; charset=utf-8'), "relatório $rel: tela, impressão e CSV (BOM UTF-8)");
}
$csvBeta = $beta->req('index.php?p=relatorios&a=ver&r=estoque&csv=1')['corpo'];
ok(!str_contains($csvBeta, 'BET-400') && str_contains($csvBeta, ';'), 'CSV de estoque da Beta não traz produto da Alfa e usa ";"');

// Exportação para o contador (mês atual: os testes lançaram no caixa hoje)
$mesAtual = date('Y-m');
$baixar = fn(Navegador $n, string $arq) => $n->req("index.php?p=relatorios&a=contador_baixar&mes=$mesAtual&arquivo=$arq");
$t = $op->get('index.php?p=relatorios&a=contador')['corpo'] . $baixar($op, "recebimentos_locacao_$mesAtual.csv")['corpo'];
ok(str_contains($t, 'Acesso restrito ao administrador.') && !str_contains($t, '111.444.777-35'), 'operador não acessa a exportação (traz CPF)');
$cx = $baixar($alfa, "caixa_$mesAtual.csv");
ok($cx['codigo'] === 200 && str_starts_with($cx['corpo'], "\xEF\xBB\xBFData;Tipo;Categoria;Descrição;Forma;Entrada;Saída;\"Locação nº\"")
   && str_contains($cx['corpo'], 'TESTE gerador com patrimônio') && str_contains($cx['cabecalhos'], 'text/csv'),
   'caixa do mês em CSV (cabeçalho, ";" e os lançamentos)');
$rc = $baixar($alfa, "recebimentos_locacao_$mesAtual.csv")['corpo'];
ok(preg_match("/;$N;\"Maria Lúcia Bezerra\";111\\.444\\.777-35;Recebimento;PIX;100,00/u", $rc) === 1,
   "recebimentos de locação com CPF formatado (adiantamento da locação nº $N)");
$rs = $baixar($alfa, "resumo_$mesAtual.csv")['corpo'];
$fimMes = (float) dbv("SELECT COALESCE(SUM(CASE WHEN tipo = 'E' THEN valor ELSE -valor END), 0) FROM caixa
                        WHERE tenant_id = :t AND data <= (date_trunc('month', CURRENT_DATE) + INTERVAL '1 month - 1 day')::date", ['t' => $alfaId]);
ok(str_contains($rs, '"Saldo final (' . date('t/m/Y') . ')";;;' . number_format($fimMes, 2, ',', '')), 'resumo: saldo final do mês confere com o caixa');
$zip = $baixar($alfa, 'zip');
$ok = false;
if (class_exists('ZipArchive') && str_starts_with($zip['corpo'], 'PK')) {
    $tmp = tempnam(sys_get_temp_dir(), 'lgt');
    file_put_contents($tmp, $zip['corpo']);
    $z = new ZipArchive();
    $z->open($tmp);
    $ok = $z->numFiles === 5 && $z->getFromName("caixa_$mesAtual.csv") === $cx['corpo'] && str_contains((string) $z->getFromName('LEIAME.txt'), 'Regime de caixa');
    $z->close();
    unlink($tmp);
}
ok($ok, '.zip com os 4 CSV (iguais aos avulsos) e o LEIAME');
ok(!str_contains($baixar($beta, "caixa_$mesAtual.csv")['corpo'], 'TESTE gerador com patrimônio'), 'exportação da Beta não traz lançamento da Alfa');
ok($alfa->req("index.php?p=relatorios&a=contador_baixar&mes=$mesAtual&arquivo=..%2Fconfig.php")['codigo'] === 404, 'nome de arquivo fora da lista: 404');

// =====================================================================
secao('Proteção do login');
$t = new Navegador($BASE);
for ($i = 1; $i <= 5; $i++) {
    $t->entrar('beta-eventos', 'admin', "errada$i");
}
$t->entrar('beta-eventos', 'admin', 'admin123');
ok(str_starts_with($t->mensagem('index.php?p=login'), 'Muitas tentativas de acesso.'), '5 erros bloqueiam a conta, mesmo com a senha certa');
ok(dbv("SELECT count(*) FROM login_tentativas WHERE chave !~ '^[0-9a-f]{64}$'") == 0, 'o banco guarda só hashes das tentativas');
db('DELETE FROM login_tentativas');

// =====================================================================
secao('Painel da plataforma');
$senhaSuporte = getenv('LOCGESTOR_SUPORTE_SENHA');
if (!$senhaSuporte) {
    echo "  (pulado: defina LOCGESTOR_SUPORTE_SENHA para testar o painel da plataforma)\n";
} else {
    $pl = new Navegador($BASE);
    $r = $pl->enviar('plataforma.php?p=login', 'plataforma.php?p=login&a=entrar', ['login' => 'suporte', 'senha' => $senhaSuporte]);
    ok(str_contains($r['local'], 'p=tenants'), 'suporte entra no painel');
    ok($alfa->get('plataforma.php?p=tenants')['codigo'] === 303, 'sessão de locadora não abre o painel da plataforma');
    $basico = db("SELECT id, preco_mensal, limite_usuarios FROM planos WHERE nome = 'Básico'")->fetch();
    $r = $pl->enviar('plataforma.php?p=tenants&a=nova', 'plataforma.php?p=tenants&a=criar', [
        'nome' => 'TESTE Locadora', 'slug' => 'teste-ci', 'plano_id' => $basico['id'], 'vencimento' => dia(30), 'carencia_dias' => '5',
        'admin_nome' => 'Admin Teste', 'admin_login' => 'admin',
    ]);
    $nova = db("SELECT plano, limite_usuarios, vencimento, carencia_dias FROM tenants WHERE slug = 'teste-ci'")->fetch();
    ok($nova && $nova['plano'] === 'Básico' && (int) $nova['limite_usuarios'] === (int) $basico['limite_usuarios'] && $nova['vencimento'] === dia(30),
       'nova locadora com o plano Básico: limite de usuários do plano e 1º vencimento em 30 dias', json_encode($nova));
    preg_match('/id="senha-temp">([^<]+)</', $pl->get('plataforma.php?p=tenants&a=criada')['corpo'], $m);
    $temp = $m[1] ?? '';
    ok(strlen($temp) === 12, 'nova locadora criada; senha temporária de 12 caracteres mostrada');
    ok(!str_contains($pl->get('plataforma.php?p=tenants&a=criada')['corpo'], $temp ?: '##'), 'a senha temporária não aparece de novo');
    $nv = new Navegador($BASE);
    $nv->entrar('teste-ci', 'admin', $temp);
    ok(str_contains($nv->req('index.php?p=dashboard')['local'], 'p=config&a=senha'), 'admin novo é obrigado a trocar a senha temporária');
    $nv->enviar('index.php?p=config&a=senha', 'index.php?p=config&a=senha_salvar',
                ['senha_atual' => $temp, 'senha_nova' => 'NovaSenha2026', 'senha_confirma' => 'NovaSenha2026']);
    $tn = texto($nv->get('index.php?p=dashboard')['corpo']);
    $cats = dbv("SELECT count(*) FROM categorias WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'teste-ci')");
    ok(str_contains($tn, 'Locações em aberto 0 ') && $cats == 5, 'locadora nova: sistema vazio e 5 categorias padrão');
    $tid = dbv("SELECT id FROM tenants WHERE slug = 'teste-ci'");
    $ger = "plataforma.php?p=tenants&a=editar&id=$tid";

    secao('Plataforma: planos e mensalidade');
    $pl->enviar('plataforma.php?p=planos', 'plataforma.php?p=planos&a=salvar',
                ['nome' => 'TESTE Plano', 'preco_mensal' => '1,00', 'limite_usuarios' => '1', 'ativo' => '1']);
    $pid = dbv("SELECT id FROM planos WHERE nome = 'TESTE Plano'");
    ok($pid !== null && str_contains(texto($pl->get('plataforma.php?p=planos')['corpo']), 'TESTE Plano R$ 1,00'), 'cria plano (R$ 1,00, 1 usuário)');
    $pl->enviar("plataforma.php?p=planos&id=$pid", 'plataforma.php?p=planos&a=salvar',
                ['id' => $pid, 'nome' => 'TESTE Plano', 'preco_mensal' => '2,50', 'limite_usuarios' => '1', 'ativo' => '1']);
    ok(dbv('SELECT preco_mensal FROM planos WHERE id = :id', ['id' => $pid]) === '2.50', 'edita o preço do plano para R$ 2,50');
    $pl->enviar('plataforma.php?p=planos', 'plataforma.php?p=planos&a=excluir', ['id' => $pid]);
    ok(dbv('SELECT count(*) FROM planos WHERE id = :id', ['id' => $pid]) == 0, 'exclui plano sem locadoras');

    // Aviso ao admin da locadora conforme o vencimento
    db("UPDATE tenants SET vencimento = CURRENT_DATE + 3 WHERE id = :id", ['id' => $tid]);
    ok(str_contains(texto($nv->get('index.php?p=dashboard')['corpo']), 'mensalidade do LocGestor (plano Básico) vence em 3 dias'),
       'admin vê o aviso "vence em 3 dias"');
    db("UPDATE tenants SET vencimento = CURRENT_DATE - 2 WHERE id = :id", ['id' => $tid]);
    $tn = texto($nv->get('index.php?p=dashboard')['corpo']);
    ok(str_contains($tn, 'vencida desde ' . date('d/m/Y', strtotime('-2 days'))) && str_contains($tn, 'Regularize até ' . date('d/m/Y', strtotime('+3 days'))),
       'vencida há 2 dias (carência de 5): aviso com a data-limite e o sistema continua funcionando');

    // Inadimplência: passou da carência
    db("UPDATE tenants SET vencimento = CURRENT_DATE - 10 WHERE id = :id", ['id' => $tid]);
    $nv->req('index.php?p=dashboard');
    ok($nv->mensagem('index.php?p=login') === 'Acesso suspenso por falta de pagamento da mensalidade. Entre em contato com o suporte.',
       'passou da carência: a sessão aberta cai na hora, com o motivo', 'veio: ' . $nv->mensagem('index.php?p=login'));
    $nv->entrar('teste-ci', 'admin', 'NovaSenha2026');
    ok($nv->mensagem('index.php?p=login') === 'Dados de acesso inválidos.', 'novo login da inadimplente: mensagem genérica');
    $pl->get('plataforma.php?p=tenants');
    $st = db('SELECT status, motivo_suspensao FROM tenants WHERE id = :id', ['id' => $tid])->fetch();
    ok($st['status'] === 'suspensa' && $st['motivo_suspensao'] === 'inadimplencia', 'o painel marca a locadora como suspensa por inadimplência');
    ok(str_contains(texto($pl->get('plataforma.php?p=tenants')['corpo']), 'inadimplente'), 'lista do painel mostra "inadimplente"');

    // Pagamento: avança o vencimento e reativa
    $antes = dbv('SELECT vencimento FROM tenants WHERE id = :id', ['id' => $tid]);
    $pl->enviar($ger, 'plataforma.php?p=tenants&a=pagamento', ['id' => $tid, 'meses' => '2', 'valor' => '', 'data' => dia(0), 'forma' => 'PIX']);
    $m = $pl->mensagem($ger);
    $depois = db('SELECT vencimento, status, motivo_suspensao FROM tenants WHERE id = :id', ['id' => $tid])->fetch();
    $esperado = dbv("SELECT (CAST(:d AS date) + INTERVAL '2 months')::date", ['d' => $antes]);
    $pg = db('SELECT valor, meses FROM assinatura_pagamentos WHERE locadora_id = :id', ['id' => $tid])->fetch();
    ok($depois['vencimento'] === $esperado && $depois['status'] === 'ativa' && $depois['motivo_suspensao'] === ''
       && $pg['valor'] === number_format(2 * (float) $basico['preco_mensal'], 2, '.', '') && str_contains($m, 'Locadora reativada'),
       "pagamento de 2 meses: R$ {$pg['valor']}, vencimento {$antes} → {$depois['vencimento']} e locadora reativada", $m);
    ok(entrar_ok($nv, 'teste-ci', 'admin', 'NovaSenha2026'), 'depois do pagamento, o admin volta a entrar');
    $pl->enviar($ger, 'plataforma.php?p=tenants&a=estornar', ['id' => $tid]);
    ok(dbv('SELECT vencimento FROM tenants WHERE id = :id', ['id' => $tid]) === $antes
       && dbv('SELECT count(*) FROM assinatura_pagamentos WHERE locadora_id = :id', ['id' => $tid]) == 0,
       'estornar o último pagamento devolve o vencimento anterior');
    db("UPDATE tenants SET vencimento = CURRENT_DATE + 30 WHERE id = :id", ['id' => $tid]);

    // Suspensão manual
    $pl->enviar($ger, 'plataforma.php?p=tenants&a=status', ['id' => $tid, 'status' => 'suspensa']);
    ok(dbv('SELECT motivo_suspensao FROM tenants WHERE id = :id', ['id' => $tid]) === 'manual', 'suspensão manual fica registrada como "manual"');
    $nv->req('index.php?p=dashboard');
    ok(str_contains($nv->mensagem('index.php?p=login'), 'Acesso suspenso'), 'suspensa pelo painel: a sessão aberta cai com "Acesso suspenso"');

    // Atenção e filtros: locadoras de teste com situações conhecidas (criadas direto no banco)
    $planoBasico = dbv("SELECT id FROM planos WHERE nome = 'Básico'");
    $criar = fn(string $slug, string $nome, ?string $venc, string $status = 'ativa', string $motivo = '', string $criado = 'now()') =>
        dbv("INSERT INTO tenants (slug, nome, plano_id, plano, vencimento, carencia_dias, status, motivo_suspensao, criado_em)
             VALUES (:s, :n, :p, 'Básico', :v, 5, :st, :m, $criado - INTERVAL '0 days') RETURNING id",
            ['s' => $slug, 'n' => $nome, 'p' => $planoBasico, 'v' => $venc, 'st' => $status, 'm' => $motivo]);
    $vencida = $criar('teste-atn-venc', 'TESTE Atencao vencida', dia(-10), 'ativa', '', "now() - INTERVAL '30 days'");
    $vence = $criar('teste-atn-vence', 'TESTE Atencao vence logo', dia(3));
    $semCob = $criar('teste-atn-sem', 'TESTE Atencao sem cobranca', null);
    $suspensa = $criar('teste-atn-susp', 'TESTE Atencao suspensa', dia(3), 'suspensa', 'inadimplencia');
    $parada = $criar('teste-atn-parada', 'TESTE Atencao parada', dia(20), 'ativa', '', "now() - INTERVAL '30 days'");
    db("INSERT INTO usuarios (tenant_id, nome, login, senha_hash, perfil, ultimo_acesso)
        VALUES (:t, 'Parado', 'parado', 'x', 'admin', now() - INTERVAL '10 days')", ['t' => $parada]);
    $tudo = texto($pl->get('plataforma.php?p=tenants')['corpo']);
    // Só a tabela (a faixa "Precisam de atenção" fica no topo de toda página e citaria todas)
    $lista = function (string $f) use ($pl): string {
        $h = $pl->get("plataforma.php?p=tenants&filtro=$f")['corpo'];
        $p = strpos($h, 'Último acesso');
        return texto($p === false ? '' : substr($h, $p));
    };
    // A rotina diária suspende sozinha a vencida além da carência: o motivo mostrado é esse
    ok(str_contains($tudo, 'Precisam de atenção') && str_contains($tudo, 'TESTE Atencao vencida suspensa por inadimplência · mensalidade vencida há 10 dia(s)')
       && str_contains($tudo, 'TESTE Atencao suspensa suspensa por inadimplência'), 'faixa "Precisam de atenção" traz o motivo de cada uma');
    ok(str_contains($tudo, 'TESTE Atencao parada sem acesso desde') && str_contains($tudo, 'TESTE Atencao sem cobranca nunca acessou') === false,
       'locadora sem acesso há 7+ dias entra na faixa; sem cobrança e recém-criada, não');
    $v = $lista('vencidas');
    ok(str_contains($v, 'TESTE Atencao vencida') && !str_contains($v, 'TESTE Atencao vence logo'), 'filtro "Mensalidade vencida" mostra só as vencidas');
    $vc = $lista('vencendo');
    ok(str_contains($vc, 'TESTE Atencao vence logo') && !str_contains($vc, 'TESTE Atencao vencida') && !str_contains($vc, 'TESTE Atencao parada'),
       'filtro "Vencem em até 5 dias" mostra só as que vencem logo');
    ok(str_contains($lista('suspensas'), 'TESTE Atencao suspensa') && !str_contains($lista('suspensas'), 'TESTE Atencao vence logo'),
       'filtro "Suspensas" mostra só as suspensas');
    ok(str_contains($lista('sem_acesso'), 'TESTE Atencao parada') && !str_contains($lista('sem_acesso'), 'TESTE Atencao vence logo'),
       'filtro "Sem acesso há 7 dias" mostra a locadora parada');
    ok(str_contains($lista('sem_cobranca'), 'TESTE Atencao sem cobranca') && !str_contains($lista('sem_cobranca'), 'TESTE Atencao vencida'),
       'filtro "Sem cobrança" mostra só as isentas');
    ok(str_contains($lista('atencao'), 'TESTE Atencao suspensa') && !str_contains($lista('atencao'), 'TESTE Atencao sem cobranca'),
       'filtro "Precisam de atenção" esconde as que estão em dia');
    ok(str_contains($lista('nao-existe'), 'Alfa Locações') && str_contains($lista('todas'), 'Alfa Locações'), 'filtro inválido mostra todas');
    ok(str_contains($lista('vencidas'), 'Nenhuma locadora neste filtro') === false, 'lista com resultado não mostra a mensagem de vazio');

    // limpa as de teste de atenção (o cleanup geral remove o resto)
    db("DELETE FROM usuarios WHERE tenant_id = :t", ['t' => $parada]);
    foreach ([$vencida, $vence, $semCob, $suspensa, $parada] as $id) {
        db('DELETE FROM auditoria WHERE tenant_id = :id', ['id' => $id]);   // o que o próprio teste gerou
        db('DELETE FROM tenants WHERE id = :id', ['id' => $id]);
    }

    // Minha senha (painel da plataforma): troca num usuário temporário, para não mexer no 'suporte' dos testes
    db("INSERT INTO plataforma_usuarios (nome, login, senha_hash) VALUES ('TESTE Conta', 'teste-conta', :h)",
       ['h' => password_hash('SenhaAntiga1', PASSWORD_DEFAULT)]);
    $conta = new Navegador($BASE);
    $conta->enviar('plataforma.php?p=login', 'plataforma.php?p=login&a=entrar', ['login' => 'teste-conta', 'senha' => 'SenhaAntiga1']);
    $menu = $conta->get('plataforma.php?p=tenants')['corpo'];
    ok(str_contains($menu, 'href="plataforma.php?p=conta"') && str_contains($menu, 'Minha senha'), 'painel tem o item "Minha senha" no menu');
    $pelaMenu = $conta->get('plataforma.php?p=conta');   // o endereço que o item do menu abre
    ok($pelaMenu['codigo'] === 200 && str_contains($pelaMenu['corpo'], 'name="senha_atual"'), 'o item "Minha senha" do menu abre a tela (sem "página não encontrada")');
    $tela = $conta->get('plataforma.php?p=conta&a=senha')['corpo'];
    ok(str_contains($tela, 'name="senha_atual"') && str_contains($tela, 'teste-conta'), 'tela "Minha senha" mostra o usuário logado');
    $conta->enviar('plataforma.php?p=conta&a=senha', 'plataforma.php?p=conta&a=senha_salvar',
                   ['senha_atual' => 'errada123', 'senha_nova' => 'NovaSenha77', 'senha_confirma' => 'NovaSenha77']);
    ok(str_contains($conta->mensagem('plataforma.php?p=conta&a=senha'), 'A senha atual não confere.'), 'senha atual errada é recusada');
    $conta->enviar('plataforma.php?p=conta&a=senha', 'plataforma.php?p=conta&a=senha_salvar',
                   ['senha_atual' => 'SenhaAntiga1', 'senha_nova' => 'curta', 'senha_confirma' => 'curta']);
    ok(str_contains($conta->mensagem('plataforma.php?p=conta&a=senha'), 'pelo menos 8 caracteres'), 'nova senha com menos de 8 caracteres é recusada');
    $conta->enviar('plataforma.php?p=conta&a=senha', 'plataforma.php?p=conta&a=senha_salvar',
                   ['senha_atual' => 'SenhaAntiga1', 'senha_nova' => 'NovaSenha77', 'senha_confirma' => 'Outra8888']);
    ok(str_contains($conta->mensagem('plataforma.php?p=conta&a=senha'), 'não é igual'), 'confirmação diferente é recusada');
    $conta->enviar('plataforma.php?p=conta&a=senha', 'plataforma.php?p=conta&a=senha_salvar',
                   ['senha_atual' => 'SenhaAntiga1', 'senha_nova' => 'SenhaAntiga1', 'senha_confirma' => 'SenhaAntiga1']);
    ok(str_contains($conta->mensagem('plataforma.php?p=conta&a=senha'), 'diferente da atual'), 'nova igual à atual é recusada');
    $conta->enviar('plataforma.php?p=conta&a=senha', 'plataforma.php?p=conta&a=senha_salvar',
                   ['senha_atual' => 'SenhaAntiga1', 'senha_nova' => 'NovaSenha77', 'senha_confirma' => 'NovaSenha77']);
    ok(str_contains($conta->mensagem('plataforma.php?p=conta&a=senha'), 'Senha trocada'), 'troca válida confirmada');
    $antiga = new Navegador($BASE);
    $r = $antiga->enviar('plataforma.php?p=login', 'plataforma.php?p=login&a=entrar', ['login' => 'teste-conta', 'senha' => 'SenhaAntiga1']);
    ok(!str_contains($r['local'], 'p=tenants'), 'a senha antiga deixa de entrar');
    $nova = new Navegador($BASE);
    $r = $nova->enviar('plataforma.php?p=login', 'plataforma.php?p=login&a=entrar', ['login' => 'teste-conta', 'senha' => 'NovaSenha77']);
    ok(str_contains($r['local'], 'p=tenants'), 'a senha nova entra no painel');
    db("DELETE FROM plataforma_usuarios WHERE login = 'teste-conta'");
    db('DELETE FROM login_tentativas');

    // Números do negócio e cancelamento de contrato
    $kpi = function (string $rotulo): int {
        $h = $GLOBALS['pl']->get('plataforma.php?p=numeros')['corpo'];
        return preg_match('#' . preg_quote($rotulo, '#') . '</span><strong>([\d.,]+)</strong>#u', $h, $m) ? (int) str_replace('.', '', $m[1]) : -1;
    };
    $novasBanco = (int) dbv("SELECT count(*) FROM tenants WHERE date_trunc('month', criado_em) = date_trunc('month', now())");
    ok($pl->get('plataforma.php?p=numeros')['codigo'] === 200 && $kpi('Novas locadoras no mês') === $novasBanco,
       "números: novas locadoras do mês batem com o banco ($novasBanco)");
    $recBanco = (float) dbv("SELECT COALESCE(SUM(valor), 0) FROM assinatura_pagamentos WHERE date_trunc('month', data) = date_trunc('month', CURRENT_DATE)");
    ok(str_contains($pl->get('plataforma.php?p=numeros')['corpo'], money_br($recBanco)), 'números: receita do mês bate com os pagamentos registrados');
    $menuNum = $pl->get('plataforma.php?p=tenants')['corpo'];
    ok(str_contains($menuNum, 'href="plataforma.php?p=numeros"') && str_contains($menuNum, 'Números'), 'painel tem o item "Números" no menu');

    $cid = dbv("INSERT INTO tenants (slug, nome, plano_id, plano, vencimento, carencia_dias)
                VALUES ('teste-canc', 'TESTE Cancelada', :p, 'Básico', :v, 5) RETURNING id", ['p' => $basico['id'], 'v' => dia(10)]);
    db("INSERT INTO usuarios (tenant_id, nome, login, senha_hash, perfil) VALUES (:t, 'Dono', 'admin', :h, 'admin')",
       ['t' => $cid, 'h' => password_hash('SenhaCanc1', PASSWORD_DEFAULT)]);
    $cancAntes = $kpi('Cancelamentos no mês');
    $pl->enviar("plataforma.php?p=tenants&a=editar&id=$cid", 'plataforma.php?p=tenants&a=cancelar', ['id' => $cid]);
    $linha = db('SELECT status, cancelada_em, vencimento FROM tenants WHERE id = :id', ['id' => $cid])->fetch();
    ok($linha['status'] === 'cancelada' && $linha['cancelada_em'] === date('Y-m-d') && $linha['vencimento'] === null,
       'cancelar: status cancelada, data do cancelamento e sem vencimento', json_encode($linha));
    ok($kpi('Cancelamentos no mês') === $cancAntes + 1, 'números: o cancelamento entra no contador do mês');
    $edit = $pl->get("plataforma.php?p=tenants&a=editar&id=$cid")['corpo'];
    ok(str_contains($edit, 'Contrato cancelado em') && !str_contains($edit, 'Cancelar contrato') && !str_contains($edit, 'Suspender acesso'),
       'tela da locadora cancelada: mostra a data e não oferece suspender, reativar nem cancelar');
    $pl->enviar("plataforma.php?p=tenants&a=editar&id=$cid", 'plataforma.php?p=tenants&a=salvar',
                ['id' => $cid, 'nome' => 'TESTE Mudou', 'cnpj' => '', 'email_contato' => '', 'telefone' => '', 'plano_id' => $basico['id'],
                 'vencimento' => dia(40), 'carencia_dias' => '5', 'limite_usuarios' => '']);
    ok(str_contains($pl->mensagem("plataforma.php?p=tenants&a=editar&id=$cid"), 'Locadora cancelada: o contrato não pode mais ser alterado.')
       && dbv('SELECT nome FROM tenants WHERE id = :id', ['id' => $cid]) === 'TESTE Cancelada', 'contrato cancelado não é alterado');
    $pl->enviar("plataforma.php?p=tenants&a=editar&id=$cid", 'plataforma.php?p=tenants&a=pagamento',
                ['id' => $cid, 'meses' => '1', 'data' => dia(0), 'forma' => 'PIX']);
    ok(str_contains($pl->mensagem("plataforma.php?p=tenants&a=editar&id=$cid"), 'não há mensalidade') && dbv('SELECT count(*) FROM assinatura_pagamentos WHERE locadora_id = :id', ['id' => $cid]) == 0,
       'não registra pagamento de locadora cancelada');
    $pl->enviar("plataforma.php?p=tenants&a=editar&id=$cid", 'plataforma.php?p=tenants&a=status', ['id' => $cid, 'status' => 'ativa']);
    ok(dbv('SELECT status FROM tenants WHERE id = :id', ['id' => $cid]) === 'cancelada', 'não reativa pelo painel');
    $pl->enviar("plataforma.php?p=tenants&a=editar&id=$cid", 'plataforma.php?p=tenants&a=cancelar', ['id' => $cid]);
    ok(str_contains($pl->mensagem("plataforma.php?p=tenants&a=editar&id=$cid"), 'já está cancelada'), 'não cancela duas vezes');
    $cli = new Navegador($BASE);
    $r = $cli->entrar('teste-canc', 'admin', 'SenhaCanc1');
    ok(!str_contains($r['local'], 'p=dashboard'), 'admin da locadora cancelada não entra no sistema');
    // Usuários primeiro (a exclusão deles também gera auditoria), depois a auditoria, depois a locadora
    db('DELETE FROM usuarios WHERE tenant_id = :t', ['t' => $cid]);
    db("DELETE FROM auditoria WHERE tenant_id = :t", ['t' => $cid]);
    db('DELETE FROM tenants WHERE id = :t', ['t' => $cid]);
    db('DELETE FROM login_tentativas');

    // Uso por locadora
    $usoHtml = $pl->get('plataforma.php?p=uso')['corpo'];
    $locDb = (int) dbv("SELECT count(*) FROM locacoes WHERE status <> 'cancelada' AND data_saida >= date_trunc('month', CURRENT_DATE)::date");
    $somaTela = 0;
    if (preg_match('#Locações no mês \(todas\)</span><strong>([\d.,]+)</strong>#u', $usoHtml, $mu)) {
        $somaTela = (int) str_replace('.', '', $mu[1]);
    }
    ok($pl->get('plataforma.php?p=uso')['codigo'] === 200 && $somaTela === $locDb,
       "uso: locações do mês batem com o banco ($locDb)", "tela: $somaTela");
    ok(str_contains($usoHtml, 'Nenhum dado de cliente aparece aqui') && str_contains($menuNum, 'plataforma.php?p=uso'),
       'uso: aparece no menu e não mostra dados de clientes');
    $uid = dbv("INSERT INTO tenants (slug, nome, plano_id, plano, vencimento, carencia_dias)
                VALUES ('teste-uso', 'TESTE Uso', :p, 'Básico', :v, 5) RETURNING id", ['p' => $basico['id'], 'v' => dia(20)]);
    for ($i = 1; $i <= 3; $i++) {
        db("INSERT INTO usuarios (tenant_id, nome, login, senha_hash, perfil) VALUES (:t, 'U$i', 'u$i', 'x', 'operador')", ['t' => $uid]);
    }
    db("INSERT INTO kits (tenant_id, codigo, nome) VALUES (:t, 'TESTE-USO', 'TESTE kit de uso')", ['t' => $uid]);
    $usoHtml = $pl->get('plataforma.php?p=uso')['corpo'];
    $linhaUso = texto(substr($usoHtml, strpos($usoHtml, 'TESTE Uso')));
    ok(str_contains($linhaUso, 'perto do limite de usuários') && str_contains($linhaUso, 'sugerir o plano') && str_contains($linhaUso, 'Kits (1 de 6)'),
       'locadora com 3 usuários de um limite de 3: alerta de limite, sugestão de plano e o recurso usado', mb_substr($linhaUso, 0, 300));
    db('DELETE FROM kits WHERE tenant_id = :t', ['t' => $uid]);
    db('DELETE FROM usuarios WHERE tenant_id = :t', ['t' => $uid]);
    db('DELETE FROM auditoria WHERE tenant_id = :t', ['t' => $uid]);
    db('DELETE FROM tenants WHERE id = :t', ['t' => $uid]);

    // Acesso de suporte ("Entrar como a locadora"): código de uso único, somente leitura e registrado
    $motivoSup = 'TESTE chamado 45: conferir o contrato';
    $r = $pl->enviar("plataforma.php?p=tenants&a=editar&id=$betaId", 'plataforma.php?p=tenants&a=suporte_entrar',
                     ['id' => $betaId, 'motivo' => 'curto']);
    ok(str_contains($pl->mensagem("plataforma.php?p=tenants&a=editar&id=$betaId"), 'de 10 a 300 caracteres'), 'suporte: motivo curto é recusado');
    $r = $pl->enviar("plataforma.php?p=tenants&a=editar&id=$betaId", 'plataforma.php?p=tenants&a=suporte_entrar',
                     ['id' => $betaId, 'motivo' => $motivoSup]);
    $link = $r['local'];
    $link = 'index.php?p=suporte&a=entrar&t=' . (explode('t=', $link)[1] ?? '');   // o Navegador devolve o endereço completo
    ok(str_starts_with($link, 'index.php?p=suporte&a=entrar&t=') && preg_match('/t=[0-9a-f-]{36}\.[0-9a-f]{64}$/', $link) === 1,
       'painel gera o link com código de uso único (locadora.aleatório)', $link);
    ok(str_contains($pl->get("plataforma.php?p=tenants&a=editar&id=$betaId")['corpo'], 'Acesso de suporte')
       && str_contains($pl->get("plataforma.php?p=tenants&a=editar&id=$betaId")['corpo'], $motivoSup), 'tela da locadora lista o acesso com o motivo');
    $sup = new Navegador($BASE);
    $abre = $sup->get($link);
    ok(str_contains($abre['local'], 'p=dashboard'), 'o link abre a sessão de suporte e vai para o painel da locadora');
    $painelSup = texto($sup->get('index.php?p=dashboard')['corpo']);
    ok(str_contains($painelSup, 'Acesso de suporte (somente leitura)') && str_contains($painelSup, 'Encerrar acesso'), 'faixa de suporte aparece no topo');
    $antesLanc = dbv("SELECT count(*) FROM caixa WHERE tenant_id = :t", ['t' => $betaId]);
    $sup->enviar('index.php?p=caixa', 'index.php?p=caixa&a=lancar',
                 ['data' => dia(0), 'tipo' => 'S', 'categoria' => 'TESTE', 'descricao' => 'TESTE gravado pelo suporte', 'valor' => '9,99', 'forma' => 'PIX']);
    ok(str_contains($sup->mensagem('index.php?p=caixa'), 'somente leitura') && dbv("SELECT count(*) FROM caixa WHERE tenant_id = :t", ['t' => $betaId]) == $antesLanc,
       'na sessão de suporte nada é gravado (lançamento recusado)');
    $segundo = new Navegador($BASE);
    ok(str_contains($segundo->get($link)['corpo'], 'já foi usado'), 'o mesmo link não serve de novo');
    $aud = db("SELECT usuario, dados::text AS dados, rotulo FROM auditoria WHERE tenant_id = :t AND tabela = 'acesso_suporte' ORDER BY quando DESC LIMIT 1", ['t' => $betaId])->fetch();
    ok($aud && $aud['usuario'] === 'suporte: suporte' && str_contains($aud['dados'], 'chamado 45') && !str_contains($aud['dados'], explode('t=', $link)[1] ?? '-'),
       'acesso fica na auditoria da locadora (quem, motivo) sem o código', json_encode($aud));
    $sup->get('index.php?p=suporte&a=sair');
    ok(str_contains($sup->get('index.php?p=dashboard')['local'], 'p=login'), 'encerrar acesso: volta para o login');
    // Código de outra locadora não serve para a Beta
    $r = $pl->enviar("plataforma.php?p=tenants&a=editar&id=$alfaId", 'plataforma.php?p=tenants&a=suporte_entrar',
                     ['id' => $alfaId, 'motivo' => 'TESTE para outra locadora']);
    $codigoAlfa = explode('t=', $r['local'])[1] ?? '';
    $trocado = $betaId . '.' . explode('.', $codigoAlfa)[1];
    $intruso = new Navegador($BASE);
    ok(str_contains($intruso->get('index.php?p=suporte&a=entrar&t=' . $trocado)['corpo'], 'não é desta locadora'), 'código da Alfa não abre a Beta');
    $expirado = new Navegador($BASE);
    db("UPDATE acessos_suporte SET expira_em = now() - INTERVAL '1 minute' WHERE tenant_id = :t AND login_suporte = 'suporte'", ['t' => $alfaId]);
    ok(str_contains($expirado->get('index.php?p=suporte&a=entrar&t=' . $codigoAlfa)['corpo'], 'expirou'), 'código expirado não abre');
    db("DELETE FROM acessos_suporte WHERE tenant_id = :t", ['t' => $alfaId]);
    db("DELETE FROM acessos_suporte WHERE tenant_id = :t", ['t' => $betaId]);
    db("DELETE FROM auditoria WHERE tenant_id = :t AND tabela = 'acesso_suporte'", ['t' => $betaId]);

    // Recados do painel para todas as locadoras
    $recHtml = fn() => $pl->get('plataforma.php?p=recados')['corpo'];
    $pl->enviar('plataforma.php?p=recados', 'plataforma.php?p=recados&a=criar',
                ['tipo' => 'manutencao', 'titulo' => 'TESTE manutenção', 'texto' => 'Sistema fora do ar sábado das 22h às 23h.', 'expira_em' => dia(7)]);
    ok(str_contains($recHtml(), 'TESTE manutenção') && str_contains($recHtml(), 'no ar'), 'recado publicado aparece na lista do painel');
    $pl->enviar('plataforma.php?p=recados', 'plataforma.php?p=recados&a=criar',
                ['tipo' => 'aviso', 'titulo' => 'TESTE aviso', 'texto' => '<script>alert(1)</script> olá', 'expira_em' => dia(7)]);
    $pl->enviar('plataforma.php?p=recados', 'plataforma.php?p=recados&a=criar',
                ['tipo' => 'aviso', 'titulo' => 'TESTE antigo', 'texto' => 'Recado já vencido no prazo.', 'expira_em' => dia(-1)]);
    $pl->enviar('plataforma.php?p=recados', 'plataforma.php?p=recados&a=criar',
                ['tipo' => 'inventado', 'titulo' => 'TESTE x', 'texto' => 'texto de teste', 'expira_em' => dia(3)]);
    ok(str_contains($pl->mensagem('plataforma.php?p=recados'), 'Escolha o tipo'), 'recado com tipo inválido é recusado');
    $pl->enviar('plataforma.php?p=recados', 'plataforma.php?p=recados&a=criar',
                ['tipo' => 'aviso', 'titulo' => 'TESTE x', 'texto' => 'texto de teste', 'expira_em' => dia(-3)]);
    ok(str_contains($pl->mensagem('plataforma.php?p=recados'), 'hoje ou depois'), 'data de validade no passado é recusada');
    $recados = new Navegador($BASE);
    $recados->entrar('alfa-locacoes', 'admin', 'admin123');
    $tela = $recados->get('index.php?p=dashboard')['corpo'];
    ok(str_contains($tela, 'TESTE manutenção') && str_contains($tela, 'Sistema fora do ar'), 'locadora vê o recado no topo do sistema');
    ok(!str_contains($tela, 'TESTE antigo'), 'recado com validade vencida não aparece');
    ok(str_contains($tela, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($tela, '<script>alert(1)</script>'),
       'texto do recado é mostrado como texto (sem executar script)');
    $idRec = dbv("SELECT id FROM recados WHERE titulo = 'TESTE manutenção'");
    $pl->enviar('plataforma.php?p=recados', 'plataforma.php?p=recados&a=desativar', ['id' => $idRec]);
    ok(!str_contains($recados->get('index.php?p=dashboard')['corpo'], 'TESTE manutenção') && str_contains($recHtml(), 'desativado'),
       'desativado pelo painel: some das locadoras e fica marcado na lista');
    db("DELETE FROM recados WHERE titulo LIKE 'TESTE%'");

    // Redefinir senha de um usuário pelo painel: derruba as sessões dele e obriga a trocar a senha
    $opId = dbv("SELECT id FROM usuarios WHERE tenant_id = :t AND login = 'teste.operador'", ['t' => $alfaId]);
    ok($opId !== null && str_contains($op->get('index.php?p=dashboard')['local'] . $op->get('index.php?p=dashboard')['corpo'], 'Operador'),
       'pré-condição: o operador de teste está logado antes da redefinição');
    $pl->enviar("plataforma.php?p=tenants&a=editar&id=$alfaId", 'plataforma.php?p=tenants&a=reset_senha', ['id' => $alfaId, 'usuario' => $opId]);
    $telaReset = $pl->get("plataforma.php?p=tenants&a=editar&id=$alfaId")['corpo'];
    preg_match('/class="senha-temp-mostra">([^<]+)</', $telaReset, $mt);
    $senhaTemp = $mt[1] ?? '';
    ok(strlen($senhaTemp) === 12 && str_contains($telaReset, 'teste.operador'), 'painel gera senha temporária de 12 caracteres para o usuário');
    ok(!str_contains($pl->get("plataforma.php?p=tenants&a=editar&id=$alfaId")['corpo'], $senhaTemp ?: '##'), 'a senha temporária aparece uma única vez');
    $caiu = $op->get('index.php?p=dashboard');
    ok(str_contains($caiu['local'], 'p=login') && str_contains($op->mensagem('index.php?p=login'), 'redefinida pelo administrador'),
       'sessão aberta do usuário cai na hora, com o aviso de senha redefinida');
    $velha = new Navegador($BASE);
    ok(!entrar_ok($velha, 'alfa-locacoes', 'teste.operador', 'Operador2026'), 'a senha anterior do usuário deixa de entrar');
    $nova = new Navegador($BASE);
    $r = $nova->entrar('alfa-locacoes', 'teste.operador', $senhaTemp);
    ok(str_contains($nova->req('index.php?p=dashboard')['local'], 'p=config&a=senha'), 'com a senha temporária, o usuário é obrigado a trocá-la');
    $nova->enviar('index.php?p=config&a=senha', 'index.php?p=config&a=senha_salvar',
                  ['senha_atual' => $senhaTemp, 'senha_nova' => 'Operador2027', 'senha_confirma' => 'Operador2027']);
    ok(entrar_ok(new Navegador($BASE), 'alfa-locacoes', 'teste.operador', 'Operador2027'), 'depois da troca, entra com a senha escolhida pelo usuário');
    $tudoAntes = db("SELECT count(*) FROM usuarios WHERE tenant_id = :t AND login = 'admin'", ['t' => $alfaId])->fetchColumn();
    ok($tudoAntes == 1 && entrar_ok(new Navegador($BASE), 'alfa-locacoes', 'admin', 'admin123'), 'o admin da locadora não foi afetado');

    // Auditoria do painel: o que o painel fez fica registrado, com quem e com os dados mascarados
    $ap = db("SELECT usuario, acao, dados::text AS dados FROM auditoria_plataforma WHERE tabela = 'tenants' AND rotulo = 'teste-ci' ORDER BY quando")->fetchAll();
    $statusMudou = false;
    foreach ($ap as $x) {
        $d = json_decode($x['dados'], true) ?: [];
        if (($d['status'] ?? null) === ['ativa', 'suspensa'] && $x['usuario'] === 'plataforma: suporte') {
            $statusMudou = true;
        }
    }
    ok($statusMudou, 'suspensão no painel fica na auditoria do painel (ativa → suspensa, quem: plataforma: suporte)', json_encode($ap));
    $cnpjOculto = true;
    foreach ($ap as $x) {
        $d = json_decode($x['dados'], true) ?: [];
        foreach (['cnpj', 'email_contato', 'telefone'] as $c) {
            if (isset($d[$c]) && !in_array($d[$c], ['', '***', ['***', '***']], true)) {
                $cnpjOculto = false;
            }
        }
    }
    ok($cnpjOculto, 'CNPJ, e-mail e telefone da locadora aparecem mascarados na auditoria do painel');
    $telaAud = $pl->get('plataforma.php?p=auditoria')['corpo'];
    ok(str_contains($telaAud, 'Auditoria do painel') && str_contains($telaAud, 'teste-ci') && str_contains(texto($telaAud), 'plataforma: suporte'),
       'tela "Auditoria" do painel lista as ações com quem fez', mb_substr(texto($telaAud), 0, 500));
    ok(str_contains($pl->get('plataforma.php?p=tenants')['corpo'], 'href="plataforma.php?p=auditoria"'), 'painel tem o item "Auditoria" no menu');
}

/** Valor em moeda brasileira, como aparece nas telas (R$ 1.234,56). */
function money_br(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

/** Login com sucesso? (vai para o painel) */
function entrar_ok(Navegador $n, string $empresa, string $login, string $senha): bool
{
    return str_contains($n->entrar($empresa, $login, $senha)['local'], 'p=dashboard');
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $falhas === 0 ? "RESULTADO: todos os $total testes funcionais passaram.\n" : "RESULTADO: $falhas de $total verificação(ões) FALHARAM.\n";
exit($falhas === 0 ? 0 : 1);

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
                    (SELECT COALESCE(max(numero), 0) FROM reservas r WHERE r.tenant_id = t.id) AS res
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
        foreach ($base as $t) {
            $locs = "SELECT id FROM locacoes WHERE tenant_id = :t AND numero > :n";
            $p = ['t' => $t['id'], 'n' => $t['loc']];
            $q("DELETE FROM caixa WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM devolucao_itens WHERE devolucao_id IN (SELECT id FROM devolucoes WHERE locacao_id IN ($locs))", $p);
            $q("DELETE FROM devolucoes WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM locacao_itens WHERE locacao_id IN ($locs)", $p);
            $q("DELETE FROM locacoes WHERE id IN ($locs)", $p);
            $q('DELETE FROM reservas WHERE tenant_id = :t AND numero > :n', ['t' => $t['id'], 'n' => $t['res']]);
            $q("UPDATE tenant_sequencias SET ultimo = :n WHERE tenant_id = :t AND nome = 'locacao'", ['t' => $t['id'], 'n' => $t['loc']]);
            $q("UPDATE tenant_sequencias SET ultimo = :n WHERE tenant_id = :t AND nome = 'reserva'", ['t' => $t['id'], 'n' => $t['res']]);
        }
        $q("DELETE FROM caixa WHERE descricao LIKE 'TESTE%' AND criado_em >= :i", ['i' => $inicio]);
        $q("DELETE FROM clientes WHERE nome LIKE 'TESTE%'");
        $q("DELETE FROM usuarios WHERE login LIKE 'teste.%'");
        // locadoras criadas pelo teste no painel da plataforma
        foreach (['tenant_sequencias', 'usuarios', 'categorias', 'empresa'] as $tab) {
            $q("DELETE FROM $tab WHERE tenant_id IN (SELECT id FROM tenants WHERE slug LIKE 'teste-%')");
        }
        $q("DELETE FROM tenants WHERE slug LIKE 'teste-%'");
        $q("UPDATE tenants SET status = 'ativa' WHERE slug IN ('alfa-locacoes', 'beta-eventos')");
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
secao('Painel, relatórios e CSV');
$k = db("SELECT (SELECT count(*) FROM locacoes WHERE tenant_id = :t1 AND status = 'aberta') AS abertas,
                (SELECT COALESCE(sum(greatest(valor_atual - valor_pago, 0)), 0) FROM vw_locacao_valores WHERE tenant_id = :t2 AND status <> 'cancelada') AS receber",
        ['t1' => $betaId, 't2' => $betaId])->fetch();
$tp = texto($beta->get('index.php?p=dashboard')['corpo']);
$receber = 'R$ ' . number_format((float) $k['receber'], 2, ',', '.');
ok(str_contains($tp, "Locações em aberto {$k['abertas']} ") && str_contains($tp, "Total a receber $receber"),
   "painel da Beta = números só da Beta ({$k['abertas']} abertas, a receber $receber)");
foreach (['atraso', 'debito', 'estoque', 'mais_alugados', 'sem_movimento', 'faturamento', 'clientes_top', 'periodo'] as $rel) {
    $t1 = $alfa->get("index.php?p=relatorios&a=ver&r=$rel")['codigo'];
    $t2 = $alfa->get("index.php?p=relatorios&a=ver&r=$rel&imprimir=1")['codigo'];
    $csv = $alfa->req("index.php?p=relatorios&a=ver&r=$rel&csv=1");
    ok($t1 === 200 && $t2 === 200 && $csv['codigo'] === 200 && str_starts_with($csv['corpo'], "\xEF\xBB\xBF")
       && str_contains($csv['cabecalhos'], 'text/csv; charset=utf-8'), "relatório $rel: tela, impressão e CSV (BOM UTF-8)");
}
$csvBeta = $beta->req('index.php?p=relatorios&a=ver&r=estoque&csv=1')['corpo'];
ok(!str_contains($csvBeta, 'BET-400') && str_contains($csvBeta, ';'), 'CSV de estoque da Beta não traz produto da Alfa e usa ";"');

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
    $r = $pl->enviar('plataforma.php?p=tenants&a=nova', 'plataforma.php?p=tenants&a=criar', [
        'nome' => 'TESTE Locadora', 'slug' => 'teste-ci', 'plano' => 'Básico', 'limite_usuarios' => '2',
        'admin_nome' => 'Admin Teste', 'admin_login' => 'admin',
    ]);
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
    $pl->enviar('plataforma.php?p=tenants', 'plataforma.php?p=tenants&a=status', ['id' => $tid, 'status' => 'suspensa']);
    $nv->req('index.php?p=dashboard');
    ok(str_contains($nv->mensagem('index.php?p=login'), 'Acesso suspenso'), 'suspensa pelo painel: a sessão aberta cai com "Acesso suspenso"');
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $falhas === 0 ? "RESULTADO: todos os $total testes funcionais passaram.\n" : "RESULTADO: $falhas de $total verificação(ões) FALHARAM.\n";
exit($falhas === 0 ? 0 : 1);

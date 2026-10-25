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
        // Vistorias criadas pelo teste: apaga os registros e os arquivos do disco
        $pasta = rtrim((string) ($GLOBALS['config']['pasta_arquivos'] ?? __DIR__ . '/../storage'), '/\\');
        $arqs = db("SELECT f.arquivo FROM vistoria_fotos f JOIN vistorias v ON v.id = f.vistoria_id WHERE v.criado_em >= :i
                    UNION ALL SELECT assinatura_arquivo FROM vistorias WHERE criado_em >= :i2 AND assinatura_arquivo <> ''",
                   ['i' => $inicio, 'i2' => $inicio])->fetchAll(PDO::FETCH_COLUMN);
        $q('DELETE FROM vistorias WHERE criado_em >= :i', ['i' => $inicio]);
        foreach ($arqs as $a) {
            @unlink("$pasta/$a");
        }
        $q("DELETE FROM caixa WHERE descricao LIKE 'TESTE%' AND criado_em >= :i", ['i' => $inicio]);
        $q("DELETE FROM clientes WHERE nome LIKE 'TESTE%'");
        $q("DELETE FROM usuarios WHERE login LIKE 'teste.%'");
        // locadoras criadas pelo teste no painel da plataforma
        foreach (['tenant_sequencias', 'usuarios', 'categorias', 'empresa'] as $tab) {
            $q("DELETE FROM $tab WHERE tenant_id IN (SELECT id FROM tenants WHERE slug LIKE 'teste-%')");
        }
        $q("DELETE FROM assinatura_pagamentos WHERE locadora_id IN (SELECT id FROM tenants WHERE slug LIKE 'teste-%')");
        $q("DELETE FROM tenants WHERE slug LIKE 'teste-%'");
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
       'passou da carência: a sessão aberta cai na hora, com o motivo');
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
}

/** Login com sucesso? (vai para o painel) */
function entrar_ok(Navegador $n, string $empresa, string $login, string $senha): bool
{
    return str_contains($n->entrar($empresa, $login, $senha)['local'], 'p=dashboard');
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $falhas === 0 ? "RESULTADO: todos os $total testes funcionais passaram.\n" : "RESULTADO: $falhas de $total verificação(ões) FALHARAM.\n";
exit($falhas === 0 ? 0 : 1);

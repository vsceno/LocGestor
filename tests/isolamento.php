<?php
/*
 * LocGestor - testes de isolamento entre locadoras (RLS).
 *
 * Uso (no terminal, na pasta do projeto):   php tests/isolamento.php
 *
 * Conecta DIRETAMENTE como locgestor_app, com usuário e senha do config.php
 * (nunca como postgres + SET ROLE, que mascararia o resultado).
 * Precisa das locadoras de demonstração alfa-locacoes e beta-eventos (03_demo.sql).
 * Tudo o que os testes gravam é desfeito com ROLLBACK.
 * Termina com código 1 se qualquer verificação falhar.
 */

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal: php tests/isolamento.php\n");
}

$arqConfig = __DIR__ . '/../config.php';
if (!is_file($arqConfig)) {
    fwrite(STDERR, "Copie config.example.php para config.php e preencha as senhas do banco.\n");
    exit(1);
}
$cfg = (require $arqConfig)['db_app'];

// Tabelas de dados de locadora. Para cada uma: coluna usada para identificar a linha.
const TABELAS = [
    'empresa'           => 'tenant_id',
    'usuarios'          => 'id',
    'clientes'          => 'id',
    'categorias'        => 'id',
    'produtos'          => 'id',
    'reservas'          => 'id',
    'reserva_itens'     => 'id',
    'locacoes'          => 'id',
    'locacao_itens'     => 'id',
    'devolucoes'        => 'id',
    'devolucao_itens'   => 'id',
    'caixa'             => 'id',
    'tenant_sequencias' => 'nome',
    'vistorias'         => 'id',
    'vistoria_itens'    => 'id',
    'vistoria_fotos'    => 'id',
    'kits'              => 'id',
    'kit_itens'         => 'id',
    'unidades'          => 'id',
    'manutencoes'       => 'id',
    'locacao_unidades'  => 'id',
    'auditoria'         => 'id',
];
const VIEWS = ['vw_produto_alugado', 'vw_locacao_valores', 'vw_produto_manutencao'];

// Mínimo que a demo da Beta cria (as telas podem acrescentar registros depois)
const MINIMO_BETA = ['empresa' => 1, 'usuarios' => 1, 'clientes' => 3, 'categorias' => 5, 'produtos' => 4,
                     'locacoes' => 9, 'locacao_itens' => 1, 'devolucoes' => 1, 'devolucao_itens' => 1,
                     'caixa' => 1, 'tenant_sequencias' => 1];

// ---------------------------------------------------------------------
// Infraestrutura mínima de teste
// ---------------------------------------------------------------------
$falhas = 0;
$testeAtual = '';
$falhasTeste = 0;

function teste(string $nome): void
{
    global $testeAtual, $falhasTeste;
    fim_teste();
    $testeAtual = $nome;
    $falhasTeste = 0;
    echo "\n$nome\n";
}

function fim_teste(): void
{
    global $testeAtual, $falhasTeste;
    if ($testeAtual !== '') {
        echo $falhasTeste === 0 ? "  => OK\n" : "  => FALHOU ($falhasTeste verificação(ões))\n";
    }
    $testeAtual = '';
}

function confere(bool $ok, string $descricao, string $motivo = ''): void
{
    global $falhas, $falhasTeste;
    if ($ok) {
        echo "  [OK]     $descricao\n";
    } else {
        $falhas++;
        $falhasTeste++;
        echo "  [FALHOU] $descricao" . ($motivo !== '' ? " -- $motivo" : '') . "\n";
    }
}

function q(PDO $pdo, string $sql, array $p = []): PDOStatement
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st;
}

function val(PDO $pdo, string $sql, array $p = [])
{
    return q($pdo, $sql, $p)->fetchColumn();
}

/** Define o contexto da locadora na sessão do banco ('' = sem contexto). */
function contexto(PDO $pdo, string $tenantId): void
{
    q($pdo, "SELECT set_config('app.tenant_id', :t, false)", ['t' => $tenantId]);
}

/**
 * Executa $sql dentro de uma transação desfeita no fim e exige que dê erro com o SQLSTATE esperado.
 * 42501 = permissão/política RLS, 23503 = chave estrangeira, 23502 = NOT NULL, 23505 = duplicado.
 */
function deve_falhar(PDO $pdo, string $descricao, string $sql, array $p, string $sqlstate): void
{
    $pdo->beginTransaction();
    try {
        q($pdo, $sql, $p);
        confere(false, $descricao, 'o comando foi ACEITO, mas deveria falhar');
    } catch (PDOException $e) {
        $msg = preg_replace('/\s+/', ' ', $e->errorInfo[2] ?? $e->getMessage());
        confere($e->getCode() === $sqlstate, $descricao,
                "esperado SQLSTATE $sqlstate, veio {$e->getCode()}: $msg");
        if ($e->getCode() === $sqlstate) {
            echo "           (" . mb_strimwidth(trim(preg_replace('/^ERRO:\s*/', '', $msg)), 0, 110, '…') . ")\n";
        }
    } finally {
        $pdo->rollBack();
    }
}

/**
 * Executa $sql numa transação desfeita no fim e devolve o nº de linhas afetadas.
 * Erro inesperado devolve -1 (o chamador registra como falha).
 */
function linhas_afetadas(PDO $pdo, string $sql, array $p): int
{
    $pdo->beginTransaction();
    try {
        return q($pdo, $sql, $p)->rowCount();
    } catch (PDOException $e) {
        echo "           (erro inesperado: {$e->getCode()})\n";
        return -1;
    } finally {
        $pdo->rollBack();
    }
}

// Qualquer exceção não prevista encerra como falha, nunca como sucesso
set_exception_handler(function (Throwable $e) {
    global $pdo;
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n  [FALHOU] erro inesperado: " . $e->getMessage() . "\n";
    echo "\nRESULTADO: execução interrompida por erro. Considere o isolamento NÃO verificado.\n";
    exit(1);
});

// ---------------------------------------------------------------------
// Conexão como locgestor_app
// ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}",
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Não foi possível conectar como {$cfg['user']}: {$e->getMessage()}\n");
    exit(1);
}

echo "LocGestor - testes de isolamento entre locadoras\n";
echo str_repeat('=', 60) . "\n";

// Pré-condição: a sessão é mesmo do papel da aplicação e ele não tem superpoderes
$quem = q($pdo, "SELECT current_user AS atual, session_user AS sessao, r.rolsuper, r.rolbypassrls
                   FROM pg_roles r WHERE r.rolname = current_user")->fetch();
echo "Conectado como: sessão={$quem['sessao']} atual={$quem['atual']} "
   . "superusuário=" . ($quem['rolsuper'] ? 'sim' : 'não') . " bypassrls=" . ($quem['rolbypassrls'] ? 'sim' : 'não') . "\n";
if ($quem['sessao'] !== 'locgestor_app' || $quem['rolsuper'] || $quem['rolbypassrls']) {
    fwrite(STDERR, "FALHOU: os testes precisam rodar como locgestor_app, sem SUPERUSER e sem BYPASSRLS.\n");
    exit(1);
}

$alfa = val($pdo, "SELECT id FROM tenants WHERE slug = 'alfa-locacoes'");
$beta = val($pdo, "SELECT id FROM tenants WHERE slug = 'beta-eventos'");
if (!$alfa || !$beta) {
    fwrite(STDERR, "FALHOU: locadoras de demonstração não encontradas. Rode database/03_demo.sql.\n");
    exit(1);
}
echo "Alfa = $alfa\nBeta = $beta\n";

// Coleta, no contexto da própria Alfa, as contagens e uma amostra de chaves de cada tabela
contexto($pdo, $alfa);
$contAlfa = [];
$chavesAlfa = [];
foreach (TABELAS as $t => $chave) {
    $contAlfa[$t] = (int) val($pdo, "SELECT count(*) FROM $t");
    $chavesAlfa[$t] = q($pdo, "SELECT $chave::text FROM $t")->fetchAll(PDO::FETCH_COLUMN);
}
$alfaCliente   = val($pdo, "SELECT id FROM clientes WHERE cpf_cnpj = '52998224725'");
$alfaProduto   = val($pdo, "SELECT id FROM produtos WHERE codigo = 'BET-400'");
$alfaLocacao   = val($pdo, "SELECT id FROM locacoes WHERE status = 'aberta' ORDER BY numero LIMIT 1");
$alfaCaixa     = val($pdo, "SELECT id FROM caixa ORDER BY data LIMIT 1");
$alfaUsuario   = val($pdo, "SELECT id FROM usuarios WHERE login = 'admin'");
$alfaClienteNome = val($pdo, "SELECT nome FROM clientes WHERE id = :id", ['id' => $alfaCliente]);
$alfaVwSoma    = val($pdo, "SELECT sum(valor_atual) FROM vw_locacao_valores");

// =====================================================================
teste('T-ISO-01  Contexto da Beta não enxerga nenhuma linha da Alfa');
contexto($pdo, $beta);
foreach (TABELAS as $t => $chave) {
    $n        = (int) val($pdo, "SELECT count(*) FROM $t");
    $deOutros = (int) val($pdo, "SELECT count(*) FROM $t WHERE tenant_id <> :b", ['b' => $beta]);
    $daAlfa   = (int) val($pdo, "SELECT count(*) FROM $t WHERE tenant_id = :a", ['a' => $alfa]);
    // Para empresa e tenant_sequencias a chave não é única entre locadoras; compara por tenant
    $chavesVazadas = ($chave === 'id')
        ? (int) val($pdo, "SELECT count(*) FROM $t WHERE id::text = ANY (CAST(:ids AS text[]))",
                    ['ids' => '{' . implode(',', $chavesAlfa[$t]) . '}'])
        : 0;
    $minimo = MINIMO_BETA[$t] ?? 0;
    confere($deOutros === 0 && $daAlfa === 0 && $chavesVazadas === 0 && $n >= $minimo,
            sprintf('%-18s Beta vê %4d linha(s) (Alfa tem %4d); de outra locadora: %d',
                    $t, $n, $contAlfa[$t], $deOutros + $daAlfa + $chavesVazadas),
            $n < $minimo ? "esperado pelo menos $minimo linha(s) da demo" : 'linhas de outra locadora visíveis');
}
confere(val($pdo, "SELECT count(*) FROM clientes WHERE nome = :n AND cpf_cnpj = '52998224725'", ['n' => $alfaClienteNome]) == 0,
        'busca direta pelo CPF de cliente exclusivo da Alfa não retorna nada');

// =====================================================================
teste('T-ISO-02  Sem contexto definido: SELECT vazio e INSERT recusado');
contexto($pdo, '');
foreach (array_merge(array_keys(TABELAS), VIEWS) as $t) {
    $n = (int) val($pdo, "SELECT count(*) FROM $t");
    confere($n === 0, sprintf('%-18s %d linha(s)', $t, $n));
}
// Sem contexto o DEFAULT dá tenant_id NULL; o PostgreSQL confere a política RLS
// (NULL = NULL não é verdadeiro) antes do NOT NULL, por isso o erro é 42501.
deve_falhar($pdo, 'INSERT em categorias sem tenant (tenant_id fica NULL)',
            "INSERT INTO categorias (nome) VALUES ('Sem contexto')", [], '42501');
deve_falhar($pdo, 'INSERT em clientes informando o tenant da Alfa',
            "INSERT INTO clientes (tenant_id, nome) VALUES (:a, 'Sem contexto')", ['a' => $alfa], '42501');
deve_falhar($pdo, 'proximo_numero() sem contexto',
            "SELECT proximo_numero('locacao')", [], '42501');

// =====================================================================
teste('T-ISO-03  Contexto da Beta: INSERT com tenant_id da Alfa é barrado pela política');
contexto($pdo, $beta);
$inserts = [
    'categorias'        => "INSERT INTO categorias (tenant_id, nome) VALUES (:a, 'Invasora')",
    'clientes'          => "INSERT INTO clientes (tenant_id, nome) VALUES (:a, 'Cliente invasor')",
    'produtos'          => "INSERT INTO produtos (tenant_id, codigo, descricao) VALUES (:a, 'INV-1', 'Invasor')",
    'usuarios'          => "INSERT INTO usuarios (tenant_id, nome, login, senha_hash) VALUES (:a, 'Invasor', 'invasor', 'x')",
    'caixa'             => "INSERT INTO caixa (tenant_id, data, tipo, descricao, valor) VALUES (:a, CURRENT_DATE, 'E', 'Invasão', 1)",
    'tenant_sequencias' => "INSERT INTO tenant_sequencias (tenant_id, nome, ultimo) VALUES (:a, 'invasao', 1)",
];
foreach ($inserts as $t => $sql) {
    deve_falhar($pdo, "INSERT em $t com tenant_id da Alfa", $sql, ['a' => $alfa], '42501');
}
deve_falhar($pdo, 'UPDATE movendo um cliente da Beta para a Alfa',
            "UPDATE clientes SET tenant_id = :a WHERE id = (SELECT id FROM clientes LIMIT 1)", ['a' => $alfa], '42501');
// Contraprova: sem informar tenant_id, o DEFAULT grava na Beta
$pdo->beginTransaction();
$novo = val($pdo, "INSERT INTO categorias (nome) VALUES ('Teste default') RETURNING tenant_id");
$pdo->rollBack();
confere($novo === $beta, 'INSERT sem tenant_id recebe o tenant da Beta pelo DEFAULT', "gravou em $novo");

// =====================================================================
teste('T-ISO-04  Contexto da Beta: chave estrangeira para registro da Alfa é barrada (FK composta)');
contexto($pdo, $beta);
$betaCliente = val($pdo, "SELECT id FROM clientes ORDER BY id LIMIT 1");
$betaLocacao = val($pdo, "SELECT id FROM locacoes ORDER BY numero LIMIT 1");
deve_falhar($pdo, 'INSERT em locacoes com cliente_id da Alfa',
            "INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao)
             VALUES (99999, :c, CURRENT_DATE, CURRENT_DATE)", ['c' => $alfaCliente], '23503');
deve_falhar($pdo, 'INSERT em locacao_itens com produto_id da Alfa',
            "INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria) VALUES (:l, :p, 1, 1)",
            ['l' => $betaLocacao, 'p' => $alfaProduto], '23503');
deve_falhar($pdo, 'INSERT em locacao_itens pendurado em locação da Alfa',
            "INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria)
             VALUES (:l, (SELECT id FROM produtos LIMIT 1), 1, 1)", ['l' => $alfaLocacao], '23503');
deve_falhar($pdo, 'INSERT em caixa com locacao_id da Alfa',
            "INSERT INTO caixa (data, tipo, descricao, valor, locacao_id) VALUES (CURRENT_DATE, 'E', 'x', 1, :l)",
            ['l' => $alfaLocacao], '23503');
deve_falhar($pdo, 'INSERT em reservas com usuario_id da Alfa',
            "INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, usuario_id)
             VALUES (99999, :c, CURRENT_DATE, CURRENT_DATE, :u)", ['c' => $betaCliente, 'u' => $alfaUsuario], '23503');
deve_falhar($pdo, 'UPDATE de locação da Beta apontando para cliente da Alfa',
            "UPDATE locacoes SET cliente_id = :c WHERE id = :l", ['c' => $alfaCliente, 'l' => $betaLocacao], '23503');

// =====================================================================
teste('T-ISO-05  Contexto da Beta: UPDATE e DELETE com id da Alfa afetam 0 linhas');
contexto($pdo, $beta);
$casos = [
    ['UPDATE clientes SET nome = \'Invadido\' WHERE id = :id',          $alfaCliente],
    ['UPDATE clientes SET bloqueado = true WHERE id = :id',             $alfaCliente],
    ['UPDATE produtos SET qtd_total = 0 WHERE id = :id',                $alfaProduto],
    ['UPDATE locacoes SET status = \'cancelada\' WHERE id = :id',       $alfaLocacao],
    ['UPDATE usuarios SET senha_hash = \'x\' WHERE id = :id',           $alfaUsuario],
    ['UPDATE caixa SET valor = 0.01 WHERE id = :id',                    $alfaCaixa],
    ['DELETE FROM caixa WHERE id = :id',                                $alfaCaixa],
    ['DELETE FROM clientes WHERE id = :id',                             $alfaCliente],
    ['DELETE FROM produtos WHERE id = :id',                             $alfaProduto],
];
foreach ($casos as [$sql, $id]) {
    $n = linhas_afetadas($pdo, $sql, ['id' => $id]);
    confere($n === 0, sprintf('%-62s -> %d linha(s)', $sql, $n));
}
foreach (['empresa', 'locacoes', 'caixa', 'clientes'] as $t) {
    $n = linhas_afetadas($pdo, "UPDATE $t SET tenant_id = tenant_id WHERE tenant_id = :a", ['a' => $alfa]);
    confere($n === 0, sprintf('UPDATE em massa de %-10s filtrando pelo tenant da Alfa -> %d linha(s)', $t, $n));
}
// Contraprova: no contexto da Alfa os registros continuam intactos
contexto($pdo, $alfa);
confere(val($pdo, 'SELECT nome FROM clientes WHERE id = :id', ['id' => $alfaCliente]) === $alfaClienteNome
        && val($pdo, 'SELECT count(*) FROM caixa WHERE id = :id', ['id' => $alfaCaixa]) == 1,
        'dados da Alfa continuam intactos');

// =====================================================================
teste('T-ISO-06  Views no contexto da Beta não somam nada da Alfa');
contexto($pdo, $beta);
// Toda view do schema precisa de security_invoker=true. Esta checagem pelo catálogo é necessária
// porque uma view sem a opção só vaza se o dono dela escapar do RLS (ex.: criada pelo postgres);
// com o locgestor_owner + FORCE ela não vaza, e a contagem abaixo sozinha não perceberia o erro.
foreach (q($pdo, "SELECT c.relname, pg_get_userbyid(c.relowner) AS dono,
                         COALESCE('security_invoker=true' = ANY (c.reloptions), false) AS invoker
                    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                   WHERE n.nspname = 'public' AND c.relkind IN ('v', 'm')
                   ORDER BY 1")->fetchAll() as $v) {
    confere($v['invoker'] === true, "{$v['relname']} tem security_invoker=true (dono {$v['dono']})",
            'view sem security_invoker pode ignorar o RLS e vazar dados');
}
foreach (VIEWS as $v) {
    $vazou = (int) val($pdo, "SELECT count(*) FROM $v WHERE tenant_id <> :b", ['b' => $beta]);
    confere($vazou === 0, "$v: linhas de outra locadora = $vazou");
}
$vwLocacoes = (int) val($pdo, 'SELECT count(*) FROM vw_locacao_valores');
$betaLocacoes = (int) val($pdo, 'SELECT count(*) FROM locacoes');
confere($vwLocacoes === $betaLocacoes, "vw_locacao_valores tem $vwLocacoes linha(s) = locações da Beta ($betaLocacoes)");
confere((int) val($pdo, 'SELECT count(*) FROM vw_locacao_valores WHERE locacao_id = :l', ['l' => $alfaLocacao]) === 0,
        'locação aberta da Alfa não aparece na view');
// Recalcula o "na rua" direto das tabelas (já filtradas pelo RLS) e compara com a view
$naRuaView = (int) val($pdo, 'SELECT COALESCE(sum(qtd_alugada), 0) FROM vw_produto_alugado');
$naRuaBase = (int) val($pdo, "SELECT COALESCE(sum(li.quantidade - li.qtd_devolvida), 0)
                                FROM locacao_itens li JOIN locacoes l ON l.id = li.locacao_id
                               WHERE l.status = 'aberta'");
confere($naRuaView === $naRuaBase, "vw_produto_alugado soma $naRuaView = itens na rua da Beta ($naRuaBase)");
confere((int) val($pdo, 'SELECT count(*) FROM vw_produto_alugado WHERE produto_id = :p', ['p' => $alfaProduto]) === 0,
        'produto da Alfa não aparece em vw_produto_alugado');
$betaVwSoma = val($pdo, 'SELECT COALESCE(sum(valor_atual), 0) FROM vw_locacao_valores');
confere($betaVwSoma !== $alfaVwSoma, "soma de valor_atual da Beta (R$ $betaVwSoma) ≠ da Alfa (R$ $alfaVwSoma)");

// =====================================================================
teste('T-ISO-07  locgestor_app não consegue contornar o RLS');
contexto($pdo, $beta);
$pdo->beginTransaction();
q($pdo, 'SET LOCAL row_security = off');
try {
    q($pdo, 'SELECT count(*) FROM clientes');
    confere(false, 'SELECT com row_security = off', 'a consulta foi executada sem erro');
} catch (PDOException $e) {
    confere($e->getCode() === '42501', 'SELECT com row_security = off dá erro',
            "esperado 42501, veio {$e->getCode()}");
    echo "           (" . trim(preg_replace('/\s+/', ' ', preg_replace('/^.*ERRO:\s*/', '', $e->errorInfo[2]))) . ")\n";
}
$pdo->rollBack();
deve_falhar($pdo, 'ALTER TABLE clientes DISABLE ROW LEVEL SECURITY', 'ALTER TABLE clientes DISABLE ROW LEVEL SECURITY', [], '42501');
deve_falhar($pdo, 'ALTER TABLE clientes NO FORCE ROW LEVEL SECURITY', 'ALTER TABLE clientes NO FORCE ROW LEVEL SECURITY', [], '42501');
deve_falhar($pdo, 'DROP POLICY isolamento_tenant ON clientes', 'DROP POLICY isolamento_tenant ON clientes', [], '42501');
deve_falhar($pdo, 'SET ROLE locgestor_owner', 'SET ROLE locgestor_owner', [], '42501');
deve_falhar($pdo, 'SET ROLE postgres', 'SET ROLE postgres', [], '42501');
deve_falhar($pdo, 'SET SESSION AUTHORIZATION postgres', 'SET SESSION AUTHORIZATION postgres', [], '42501');
deve_falhar($pdo, 'CREATE TABLE no schema public', 'CREATE TABLE invasora (x int)', [], '42501');
deve_falhar($pdo, 'CREATE FUNCTION (tentativa de SECURITY DEFINER)',
            'CREATE FUNCTION invasora() RETURNS int LANGUAGE sql SECURITY DEFINER AS $$ SELECT 1 $$', [], '42501');
deve_falhar($pdo, 'CREATE VIEW sem security_invoker',
            'CREATE VIEW vw_invasora AS SELECT * FROM clientes', [], '42501');
deve_falhar($pdo, 'UPDATE em tenants (reativar/mudar locadora)', "UPDATE tenants SET status = 'ativa'", [], '42501');
deve_falhar($pdo, 'SELECT de coluna não liberada em tenants (email_contato)', 'SELECT email_contato FROM tenants', [], '42501');
deve_falhar($pdo, 'SELECT em plataforma_usuarios', 'SELECT * FROM plataforma_usuarios', [], '42501');
deve_falhar($pdo, 'SELECT em planos (dado da plataforma)', 'SELECT * FROM planos', [], '42501');
deve_falhar($pdo, 'SELECT em assinatura_pagamentos (mensalidades de todas as locadoras)', 'SELECT * FROM assinatura_pagamentos', [], '42501');
deve_falhar($pdo, 'UPDATE do próprio vencimento em tenants', "UPDATE tenants SET vencimento = CURRENT_DATE + 999", [], '42501');
// Vistoria é prova: o sistema só inclui e lê
deve_falhar($pdo, 'UPDATE em vistorias (prova não se altera)', "UPDATE vistorias SET obs = 'x'", [], '42501');
deve_falhar($pdo, 'DELETE em vistoria_fotos (prova não se apaga)', 'DELETE FROM vistoria_fotos', [], '42501');
// Cobranças do Asaas: a locadora só lê o link das próprias (RLS); nada de ids do gateway nem alteração
deve_falhar($pdo, 'SELECT do id do gateway em assinatura_cobrancas', 'SELECT gateway_id FROM assinatura_cobrancas', [], '42501');
deve_falhar($pdo, 'UPDATE em assinatura_cobrancas (marcar como paga)', "UPDATE assinatura_cobrancas SET status = 'paga'", [], '42501');
deve_falhar($pdo, 'SELECT em gateway_eventos', 'SELECT * FROM gateway_eventos', [], '42501');
// Auditoria: o histórico não se altera nem se apaga, e o sistema não consegue desligar a gravação
deve_falhar($pdo, 'UPDATE em auditoria (reescrever o histórico)', "UPDATE auditoria SET usuario = 'outro'", [], '42501');
deve_falhar($pdo, 'DELETE em auditoria (apagar o histórico)', 'DELETE FROM auditoria', [], '42501');
$pdo->beginTransaction();
q($pdo, "SELECT set_config('app.auditoria', 'off', true), set_config('app.usuario', 'teste-isolamento', true)");
$cat = val($pdo, 'SELECT id FROM categorias ORDER BY nome LIMIT 1');
q($pdo, "UPDATE categorias SET nome = nome || ' *' WHERE id = :id", ['id' => $cat]);
$gravou = (int) val($pdo, "SELECT count(*) FROM auditoria WHERE registro_id = :id AND usuario = 'teste-isolamento'", ['id' => $cat]);
$pdo->rollBack();
confere($gravou === 1, "app.auditoria = 'off' não desliga a auditoria para locgestor_app", "registros gravados: $gravou");

// =====================================================================
teste('T-ISO-08  Mesmo CPF e mesmo código de produto em locadoras diferentes');
foreach (['Alfa' => $alfa, 'Beta' => $beta] as $nome => $tid) {
    contexto($pdo, $tid);
    confere((int) val($pdo, "SELECT count(*) FROM clientes WHERE cpf_cnpj = '11144477735'") === 1,
            "$nome tem o cliente com CPF 111.444.777-35");
    confere((int) val($pdo, "SELECT count(*) FROM produtos WHERE codigo = 'GER-6K'") === 1,
            "$nome tem o produto GER-6K");
}
contexto($pdo, $beta);
$pdo->beginTransaction();
try {
    q($pdo, "INSERT INTO clientes (nome, cpf_cnpj) VALUES ('Cópia de cliente da Alfa', '52998224725')");
    q($pdo, "INSERT INTO produtos (codigo, descricao) VALUES ('BET-400', 'Betoneira da Beta')");
    confere(true, 'Beta cadastra CPF e código que só existiam na Alfa');
} catch (PDOException $e) {
    confere(false, 'Beta cadastra CPF e código que só existiam na Alfa', $e->getMessage());
} finally {
    $pdo->rollBack();
}
deve_falhar($pdo, 'CPF repetido DENTRO da Beta continua proibido',
            "INSERT INTO clientes (nome, cpf_cnpj) VALUES ('Duplicada', '11144477735')", [], '23505');
deve_falhar($pdo, 'Código de produto repetido DENTRO da Beta continua proibido',
            "INSERT INTO produtos (codigo, descricao) VALUES ('GER-6K', 'Duplicado')", [], '23505');

// =====================================================================
teste('T-ISO-09  (extra) Numeração independente por locadora');
$numeros = [];
foreach (['Alfa' => $alfa, 'Beta' => $beta] as $nome => $tid) {
    contexto($pdo, $tid);
    $max = (int) val($pdo, 'SELECT COALESCE(max(numero), 0) FROM locacoes');
    $pdo->beginTransaction();
    $prox = (int) val($pdo, "SELECT proximo_numero('locacao')");
    $pdo->rollBack();
    $numeros[$nome] = $prox;
    confere($prox === $max + 1, "$nome: maior número atual $max, próximo $prox");
}
confere($numeros['Alfa'] !== $numeros['Beta'], 'as duas sequências são diferentes (não compartilhadas)');

// =====================================================================
teste('T-ISO-10  (extra) Auditoria do catálogo: RLS forçado, donos sem superpoderes, sem SECURITY DEFINER');
$tabelas = q($pdo, "SELECT c.relname, c.relrowsecurity AS rls, c.relforcerowsecurity AS forcado,
                           (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS politicas,
                           r.rolname AS dono, r.rolsuper OR r.rolbypassrls AS dono_escapa
                      FROM pg_class c
                      JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
                      JOIN pg_roles r ON r.oid = c.relowner
                     WHERE c.relkind IN ('r', 'p')
                       AND EXISTS (SELECT 1 FROM pg_attribute a
                                    WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped)
                     ORDER BY 1")->fetchAll();
confere(count($tabelas) === count(TABELAS), sprintf('%d tabelas com tenant_id no banco, %d conhecidas pelo teste',
        count($tabelas), count(TABELAS)), 'tabela nova precisa entrar na lista TABELAS deste teste');
foreach ($tabelas as $t) {
    confere($t['rls'] && $t['forcado'] && $t['politicas'] > 0 && !$t['dono_escapa'],
            sprintf('%-18s RLS=%s FORCE=%s políticas=%d dono=%s', $t['relname'],
                    $t['rls'] ? 'sim' : 'não', $t['forcado'] ? 'sim' : 'não', $t['politicas'], $t['dono']));
}
$definer = q($pdo, "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = 'public' AND p.prosecdef")->fetchAll(PDO::FETCH_COLUMN);
confere($definer === [], 'nenhuma função SECURITY DEFINER no schema', 'encontradas: ' . implode(', ', $definer));

fim_teste();
contexto($pdo, '');

echo "\n" . str_repeat('=', 60) . "\n";
if ($falhas === 0) {
    echo "RESULTADO: todos os testes de isolamento passaram.\n";
    exit(0);
}
echo "RESULTADO: $falhas verificação(ões) FALHARAM.\n";
exit(1);

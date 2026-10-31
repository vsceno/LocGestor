<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolamento entre locadoras no próprio banco (RLS): as verificações T-ISO-01 a 10 de tests/isolamento.php, feitas como
 * locgestor_app (o papel da aplicação, sem superpoderes), com as mesmas demonstrações Alfa e Beta. Cada comando que
 * deve falhar roda num SAVEPOINT e é desfeito logo depois.
 */
class IsolamentoBancoTest extends TestCase
{
    /** Tabelas de dados de locadora: coluna usada para identificar a linha. */
    private const TABELAS = [
        'empresa' => 'tenant_id', 'usuarios' => 'id', 'clientes' => 'id', 'categorias' => 'id', 'produtos' => 'id',
        'produto_barras' => 'id', 'reservas' => 'id', 'reserva_itens' => 'id', 'locacoes' => 'id', 'locacao_itens' => 'id',
        'devolucoes' => 'id', 'devolucao_itens' => 'id', 'caixa' => 'id', 'tenant_sequencias' => 'nome', 'vistorias' => 'id',
        'vistoria_itens' => 'id', 'vistoria_fotos' => 'id', 'kits' => 'id', 'kit_itens' => 'id', 'unidades' => 'id',
        'manutencoes' => 'id', 'locacao_unidades' => 'id', 'auditoria' => 'id', 'contas_recorrentes' => 'id',
        'contas_pagar' => 'id', 'acessos_suporte' => 'id', 'orcamentos' => 'id', 'orcamento_itens' => 'id', 'recibos' => 'id',
        'devolucao_perdas' => 'id', 'entregas' => 'id',
    ];

    private const VIEWS = ['vw_produto_alugado', 'vw_locacao_valores', 'vw_produto_manutencao'];

    /** Mínimo que a demonstração da Beta cria (as telas podem acrescentar registros depois). */
    private const MINIMO_BETA = ['empresa' => 1, 'usuarios' => 1, 'clientes' => 3, 'categorias' => 5, 'produtos' => 4,
        'locacoes' => 9, 'locacao_itens' => 1, 'devolucoes' => 1, 'devolucao_itens' => 1, 'caixa' => 1, 'tenant_sequencias' => 1];

    private string $alfa;
    private string $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alfa = $this->idLocadora('alfa-locacoes');
        $this->beta = $this->idLocadora('beta-eventos');
    }

    protected function tearDown(): void
    {
        $this->contexto('');
        parent::tearDown();
    }

    /** Define a locadora do contexto desta conexão ('' = sem contexto), como o sistema faz depois do login. */
    private function contexto(string $tenantId): void
    {
        DB::select("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    }

    /** O comando precisa dar erro com este SQLSTATE (42501 permissão/RLS, 23503 chave estrangeira, 23505 duplicado). */
    private function deveFalhar(string $sql, array $params, string $sqlstate, string $descricao): void
    {
        DB::beginTransaction();
        try {
            DB::statement($sql, $params);
            $this->fail("$descricao: o comando foi ACEITO, mas deveria falhar");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), "$descricao: SQLSTATE " . $e->getCode());
        } finally {
            DB::rollBack();
        }
    }

    /** Linhas que o comando afeta, desfazendo tudo em seguida. */
    private function linhasAfetadas(string $sql, array $params): int
    {
        DB::beginTransaction();
        try {
            return DB::affectingStatement($sql, $params);
        } finally {
            DB::rollBack();
        }
    }

    private function contar(string $sql, array $params = []): int
    {
        return (int) DB::selectOne($sql, $params)->n;
    }

    public function test_contexto_da_beta_nao_enxerga_nenhuma_linha_da_alfa(): void
    {
        $this->contexto($this->alfa);
        $contAlfa = [];
        $chavesAlfa = [];
        foreach (self::TABELAS as $t => $chave) {
            $contAlfa[$t] = $this->contar("SELECT count(*) AS n FROM $t");
            $chavesAlfa[$t] = array_map(fn ($r) => (string) $r->chave, DB::select("SELECT $chave::text AS chave FROM $t"));
        }
        $clienteAlfa = DB::selectOne("SELECT id, nome FROM clientes WHERE cpf_cnpj = '52998224725'");

        $this->contexto($this->beta);
        foreach (self::TABELAS as $t => $chave) {
            $n = $this->contar("SELECT count(*) AS n FROM $t");
            $deOutros = $this->contar("SELECT count(*) AS n FROM $t WHERE tenant_id <> ?", [$this->beta]);
            $daAlfa = $this->contar("SELECT count(*) AS n FROM $t WHERE tenant_id = ?", [$this->alfa]);
            $vazadas = $chave === 'id'
                ? $this->contar("SELECT count(*) AS n FROM $t WHERE id::text = ANY (CAST(? AS text[]))", ['{' . implode(',', $chavesAlfa[$t]) . '}'])
                : 0;
            $this->assertSame(0, $deOutros + $daAlfa + $vazadas, "$t: a Beta vê linhas de outra locadora (Alfa tem {$contAlfa[$t]})");
            $this->assertGreaterThanOrEqual(self::MINIMO_BETA[$t] ?? 0, $n, "$t: a demonstração da Beta tem linhas demais de menos");
        }
        $this->assertSame(0, $this->contar("SELECT count(*) AS n FROM clientes WHERE nome = ? AND cpf_cnpj = '52998224725'", [$clienteAlfa->nome]),
                          'busca direta pelo CPF de cliente exclusivo da Alfa não retorna nada');
    }

    public function test_sem_contexto_o_select_vem_vazio_e_o_insert_e_recusado(): void
    {
        $this->contexto('');
        foreach (array_merge(array_keys(self::TABELAS), self::VIEWS) as $t) {
            $this->assertSame(0, $this->contar("SELECT count(*) AS n FROM $t"), "$t sem contexto");
        }
        // Sem contexto o DEFAULT dá tenant_id NULL; a política RLS recusa antes do NOT NULL, por isso 42501
        $this->deveFalhar("INSERT INTO categorias (nome) VALUES ('Sem contexto')", [], '42501', 'INSERT em categorias sem tenant');
        $this->deveFalhar("INSERT INTO clientes (tenant_id, nome) VALUES (?, 'Sem contexto')", [$this->alfa], '42501', 'INSERT em clientes com o tenant da Alfa');
        $this->deveFalhar("SELECT proximo_numero('locacao')", [], '42501', 'proximo_numero() sem contexto');
    }

    public function test_contexto_da_beta_nao_grava_nem_move_dados_para_a_alfa(): void
    {
        $this->contexto($this->beta);
        $inserts = [
            "INSERT INTO categorias (tenant_id, nome) VALUES (?, 'Invasora')",
            "INSERT INTO clientes (tenant_id, nome) VALUES (?, 'Cliente invasor')",
            "INSERT INTO produtos (tenant_id, codigo, descricao) VALUES (?, 'INV-1', 'Invasor')",
            "INSERT INTO usuarios (tenant_id, nome, login, senha_hash) VALUES (?, 'Invasor', 'invasor', 'x')",
            "INSERT INTO caixa (tenant_id, data, tipo, descricao, valor) VALUES (?, CURRENT_DATE, 'E', 'Invasão', 1)",
            "INSERT INTO tenant_sequencias (tenant_id, nome, ultimo) VALUES (?, 'invasao', 1)",
        ];
        foreach ($inserts as $sql) {
            $this->deveFalhar($sql, [$this->alfa], '42501', "INSERT com tenant_id da Alfa: $sql");
        }
        $this->deveFalhar('UPDATE clientes SET tenant_id = ? WHERE id = (SELECT id FROM clientes LIMIT 1)', [$this->alfa], '42501',
                          'UPDATE movendo um cliente da Beta para a Alfa');

        // Contraprova: sem informar tenant_id, o DEFAULT grava na Beta
        DB::beginTransaction();
        $novo = DB::selectOne("INSERT INTO categorias (nome) VALUES ('Teste default') RETURNING tenant_id")->tenant_id;
        DB::rollBack();
        $this->assertSame($this->beta, $novo, 'INSERT sem tenant_id recebe o tenant da Beta pelo DEFAULT');
    }

    public function test_chave_estrangeira_para_registro_da_alfa_e_barrada(): void
    {
        $this->contexto($this->beta);
        $betaCliente = DB::selectOne('SELECT id FROM clientes ORDER BY id LIMIT 1')->id;
        $betaLocacao = DB::selectOne('SELECT id FROM locacoes ORDER BY numero LIMIT 1')->id;
        $this->contexto($this->alfa);
        $alfaCliente = DB::selectOne("SELECT id FROM clientes WHERE cpf_cnpj = '52998224725'")->id;
        $alfaProduto = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'BET-400'")->id;
        $alfaLocacao = DB::selectOne("SELECT id FROM locacoes WHERE status = 'aberta' ORDER BY numero LIMIT 1")->id;
        $alfaUsuario = DB::selectOne("SELECT id FROM usuarios WHERE login = 'admin'")->id;
        $this->contexto($this->beta);

        $this->deveFalhar('INSERT INTO locacoes (numero, cliente_id, data_saida, data_prev_devolucao) VALUES (99999, ?, CURRENT_DATE, CURRENT_DATE)',
                          [$alfaCliente], '23503', 'locação da Beta com cliente da Alfa');
        $this->deveFalhar('INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria, data_prev_devolucao) VALUES (?, ?, 1, 1, CURRENT_DATE)',
                          [$betaLocacao, $alfaProduto], '23503', 'item com produto da Alfa');
        $this->deveFalhar('INSERT INTO locacao_itens (locacao_id, produto_id, quantidade, valor_diaria, data_prev_devolucao) VALUES (?, (SELECT id FROM produtos LIMIT 1), 1, 1, CURRENT_DATE)',
                          [$alfaLocacao], '23503', 'item pendurado em locação da Alfa');
        $this->deveFalhar("INSERT INTO caixa (data, tipo, descricao, valor, locacao_id) VALUES (CURRENT_DATE, 'E', 'x', 1, ?)",
                          [$alfaLocacao], '23503', 'lançamento de caixa com locação da Alfa');
        $this->deveFalhar('INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, usuario_id) VALUES (99999, ?, CURRENT_DATE, CURRENT_DATE, ?)',
                          [$betaCliente, $alfaUsuario], '23503', 'reserva com usuário da Alfa');
        $this->deveFalhar('UPDATE locacoes SET cliente_id = ? WHERE id = ?', [$alfaCliente, $betaLocacao], '23503',
                          'locação da Beta apontando para cliente da Alfa');
    }

    public function test_update_e_delete_com_id_da_alfa_nao_afetam_nada(): void
    {
        $this->contexto($this->alfa);
        $ids = [
            'cliente' => DB::selectOne("SELECT id FROM clientes WHERE cpf_cnpj = '52998224725'")->id,
            'produto' => DB::selectOne("SELECT id FROM produtos WHERE codigo = 'BET-400'")->id,
            'locacao' => DB::selectOne("SELECT id FROM locacoes WHERE status = 'aberta' ORDER BY numero LIMIT 1")->id,
            'caixa' => DB::selectOne('SELECT id FROM caixa ORDER BY data LIMIT 1')->id,
            'usuario' => DB::selectOne("SELECT id FROM usuarios WHERE login = 'admin'")->id,
        ];
        $nomeCliente = DB::selectOne('SELECT nome FROM clientes WHERE id = ?', [$ids['cliente']])->nome;
        $this->contexto($this->beta);

        $casos = [
            ["UPDATE clientes SET nome = 'Invadido' WHERE id = ?", $ids['cliente']],
            ['UPDATE clientes SET bloqueado = true WHERE id = ?', $ids['cliente']],
            ['UPDATE produtos SET qtd_total = 0 WHERE id = ?', $ids['produto']],
            ["UPDATE locacoes SET status = 'cancelada' WHERE id = ?", $ids['locacao']],
            ["UPDATE usuarios SET senha_hash = 'x' WHERE id = ?", $ids['usuario']],
            ['UPDATE caixa SET valor = 0.01 WHERE id = ?', $ids['caixa']],
            ['DELETE FROM caixa WHERE id = ?', $ids['caixa']],
            ['DELETE FROM clientes WHERE id = ?', $ids['cliente']],
            ['DELETE FROM produtos WHERE id = ?', $ids['produto']],
        ];
        foreach ($casos as [$sql, $id]) {
            $this->assertSame(0, $this->linhasAfetadas($sql, [$id]), "$sql afetou linhas da Alfa");
        }
        foreach (['empresa', 'locacoes', 'caixa', 'clientes'] as $t) {
            $this->assertSame(0, $this->linhasAfetadas("UPDATE $t SET tenant_id = tenant_id WHERE tenant_id = ?", [$this->alfa]),
                              "UPDATE em massa de $t filtrado pelo tenant da Alfa afetou linhas");
        }

        // Contraprova: no contexto da Alfa os registros continuam intactos
        $this->contexto($this->alfa);
        $this->assertSame($nomeCliente, DB::selectOne('SELECT nome FROM clientes WHERE id = ?', [$ids['cliente']])->nome);
        $this->assertSame(1, $this->contar('SELECT count(*) AS n FROM caixa WHERE id = ?', [$ids['caixa']]));
    }

    public function test_views_no_contexto_da_beta_nao_somam_nada_da_alfa(): void
    {
        // Toda view precisa de security_invoker=true: sem isso, uma view criada pelo dono poderia vazar dados
        foreach (DB::select("SELECT c.relname, COALESCE('security_invoker=true' = ANY (c.reloptions), false) AS invoker
                               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                              WHERE n.nspname = 'public' AND c.relkind IN ('v', 'm') ORDER BY 1") as $v) {
            $this->assertTrue((bool) $v->invoker, "{$v->relname} sem security_invoker=true");
        }

        $this->contexto($this->alfa);
        $alfaLocacao = DB::selectOne("SELECT id FROM locacoes WHERE status = 'aberta' ORDER BY numero LIMIT 1")->id;
        $alfaProduto = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'BET-400'")->id;
        $alfaVwSoma = DB::selectOne('SELECT sum(valor_atual) AS s FROM vw_locacao_valores')->s;

        $this->contexto($this->beta);
        foreach (self::VIEWS as $v) {
            $this->assertSame(0, $this->contar("SELECT count(*) AS n FROM $v WHERE tenant_id <> ?", [$this->beta]), "$v com linhas de outra locadora");
        }
        $this->assertSame($this->contar('SELECT count(*) AS n FROM locacoes'), $this->contar('SELECT count(*) AS n FROM vw_locacao_valores'),
                          'vw_locacao_valores tem uma linha por locação da Beta');
        $this->assertSame(0, $this->contar('SELECT count(*) AS n FROM vw_locacao_valores WHERE locacao_id = ?', [$alfaLocacao]));

        $naRuaBase = $this->contar("SELECT COALESCE(sum(li.quantidade - li.qtd_devolvida), 0) AS n FROM locacao_itens li
                                      JOIN locacoes l ON l.id = li.locacao_id WHERE l.status = 'aberta'");
        $naRuaView = $this->contar('SELECT COALESCE(sum(qtd_alugada), 0) AS n FROM vw_produto_alugado');
        $this->assertSame($naRuaBase, $naRuaView, 'vw_produto_alugado não bate com os itens na rua da Beta');
        $this->assertSame(0, $this->contar('SELECT count(*) AS n FROM vw_produto_alugado WHERE produto_id = ?', [$alfaProduto]));
        $this->assertNotEquals($alfaVwSoma, DB::selectOne('SELECT COALESCE(sum(valor_atual), 0) AS s FROM vw_locacao_valores')->s);
    }

    public function test_locgestor_app_nao_consegue_contornar_o_rls(): void
    {
        $this->contexto($this->beta);
        DB::beginTransaction();
        try {
            DB::select('SET LOCAL row_security = off');
            DB::select('SELECT count(*) FROM clientes');
            $this->fail('SELECT com row_security = off foi executado sem erro');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        } finally {
            DB::rollBack();
        }

        $this->deveFalhar('ALTER TABLE clientes DISABLE ROW LEVEL SECURITY', [], '42501', 'desligar o RLS');
        $this->deveFalhar('ALTER TABLE clientes NO FORCE ROW LEVEL SECURITY', [], '42501', 'tirar o FORCE do RLS');
        $this->deveFalhar('DROP POLICY isolamento_tenant ON clientes', [], '42501', 'apagar a política');
        $this->deveFalhar('SET ROLE locgestor_owner', [], '42501', 'assumir o dono');
        $this->deveFalhar('SET ROLE postgres', [], '42501', 'assumir o superusuário');
        $this->deveFalhar('SET SESSION AUTHORIZATION postgres', [], '42501', 'trocar a autorização da sessão');
        $this->deveFalhar('CREATE TABLE invasora (x int)', [], '42501', 'criar tabela no schema public');
        $this->deveFalhar('CREATE FUNCTION invasora() RETURNS int LANGUAGE sql SECURITY DEFINER AS $$ SELECT 1 $$', [], '42501', 'função SECURITY DEFINER');
        $this->deveFalhar('CREATE VIEW vw_invasora AS SELECT * FROM clientes', [], '42501', 'view sem security_invoker');
        $this->deveFalhar("UPDATE tenants SET status = 'ativa'", [], '42501', 'mudar o status de uma locadora');
        $this->deveFalhar('SELECT email_contato FROM tenants', [], '42501', 'ler coluna não liberada de tenants');
        $this->deveFalhar('SELECT * FROM plataforma_usuarios', [], '42501', 'ler os operadores da plataforma');
        $this->deveFalhar('SELECT * FROM planos', [], '42501', 'ler os planos da plataforma');
        $this->deveFalhar('SELECT * FROM assinatura_pagamentos', [], '42501', 'ler as mensalidades de todas as locadoras');
        $this->deveFalhar('UPDATE tenants SET vencimento = CURRENT_DATE + 999', [], '42501', 'mudar o próprio vencimento');
        // Vistoria é prova: o sistema só inclui e lê
        $this->deveFalhar("UPDATE vistorias SET obs = 'x'", [], '42501', 'alterar vistoria');
        $this->deveFalhar('DELETE FROM vistoria_fotos', [], '42501', 'apagar foto de vistoria');
        // Cobranças do Asaas: a locadora só lê o link das próprias
        $this->deveFalhar('SELECT gateway_id FROM assinatura_cobrancas', [], '42501', 'ler o id do Asaas');
        $this->deveFalhar("UPDATE assinatura_cobrancas SET status = 'paga'", [], '42501', 'marcar cobrança como paga');
        $this->deveFalhar('SELECT * FROM gateway_eventos', [], '42501', 'ler os eventos do gateway');
        // Auditoria: o histórico não se altera nem se apaga
        $this->deveFalhar("UPDATE auditoria SET usuario = 'outro'", [], '42501', 'reescrever a auditoria');
        $this->deveFalhar('DELETE FROM auditoria', [], '42501', 'apagar a auditoria');

        DB::beginTransaction();
        try {
            DB::select("SELECT set_config('app.auditoria', 'off', true), set_config('app.usuario', 'teste-isolamento', true)");
            $cat = DB::selectOne('SELECT id FROM categorias ORDER BY nome LIMIT 1')->id;
            DB::update("UPDATE categorias SET nome = nome || ' *' WHERE id = ?", [$cat]);
            $gravou = $this->contar("SELECT count(*) AS n FROM auditoria WHERE registro_id = ? AND usuario = 'teste-isolamento'", [$cat]);
            $this->assertSame(1, $gravou, "app.auditoria = 'off' não pode desligar a auditoria para locgestor_app");
        } finally {
            DB::rollBack();
        }
    }

    public function test_mesmo_cpf_e_mesmo_codigo_de_produto_em_locadoras_diferentes(): void
    {
        foreach (['Alfa' => $this->alfa, 'Beta' => $this->beta] as $nome => $tid) {
            $this->contexto($tid);
            $this->assertSame(1, $this->contar("SELECT count(*) AS n FROM clientes WHERE cpf_cnpj = '11144477735'"), "$nome tem o cliente com CPF 111.444.777-35");
            $this->assertSame(1, $this->contar("SELECT count(*) AS n FROM produtos WHERE codigo = 'GER-6K'"), "$nome tem o produto GER-6K");
        }

        $this->contexto($this->beta);
        DB::beginTransaction();
        try {
            DB::insert("INSERT INTO clientes (nome, cpf_cnpj) VALUES ('Cópia de cliente da Alfa', '52998224725')");
            DB::insert("INSERT INTO produtos (codigo, descricao) VALUES ('BET-400', 'Betoneira da Beta')");
        } finally {
            DB::rollBack();
        }
        $this->deveFalhar("INSERT INTO clientes (nome, cpf_cnpj) VALUES ('Duplicada', '11144477735')", [], '23505', 'CPF repetido dentro da Beta');
        $this->deveFalhar("INSERT INTO produtos (codigo, descricao) VALUES ('GER-6K', 'Duplicado')", [], '23505', 'código repetido dentro da Beta');
    }

    public function test_numeracao_e_independente_por_locadora(): void
    {
        $proximos = [];
        foreach (['Alfa' => $this->alfa, 'Beta' => $this->beta] as $nome => $tid) {
            $this->contexto($tid);
            $max = $this->contar('SELECT COALESCE(max(numero), 0) AS n FROM locacoes');
            DB::beginTransaction();
            try {
                $prox = $this->contar("SELECT proximo_numero('locacao') AS n");
            } finally {
                DB::rollBack();
            }
            $this->assertSame($max + 1, $prox, "$nome: o próximo número deve seguir o maior atual");
            $proximos[$nome] = $prox;
        }
        $this->assertNotSame($proximos['Alfa'], $proximos['Beta'], 'as duas sequências são compartilhadas');
    }

    public function test_catalogo_forca_o_rls_e_nenhum_dono_escapa(): void
    {
        $this->contexto('');
        $tabelas = DB::select("SELECT c.relname, c.relrowsecurity AS rls, c.relforcerowsecurity AS forcado,
                                      (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS politicas,
                                      r.rolname AS dono, r.rolsuper OR r.rolbypassrls AS dono_escapa
                                 FROM pg_class c
                                 JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
                                 JOIN pg_roles r ON r.oid = c.relowner
                                WHERE c.relkind IN ('r', 'p')
                                  AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped)
                                ORDER BY 1");
        // Tabela com tenant_id nova precisa entrar em TABELAS (e ganhar os testes acima)
        $this->assertSame(count(self::TABELAS), count($tabelas), 'tabela com tenant_id nova: incluir em TABELAS deste teste');
        foreach ($tabelas as $t) {
            $this->assertTrue((bool) $t->rls && (bool) $t->forcado && $t->politicas > 0 && ! $t->dono_escapa,
                              "{$t->relname}: RLS, FORCE, políticas e dono sem superpoderes");
        }
        $definer = DB::select("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                                WHERE n.nspname = 'public' AND p.prosecdef");
        $this->assertSame([], array_map(fn ($f) => $f->proname, $definer), 'nenhuma função SECURITY DEFINER no schema public');
    }
}

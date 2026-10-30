<?php

namespace Tests\Feature;

use App\Http\Controllers\NovaLocacaoController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Nova locação: regras de locacao_criar() do sistema antigo. */
class NovaLocacaoTest extends TestCase
{
    private function produto(string $codigo): object
    {
        return DB::selectOne('SELECT * FROM produtos WHERE codigo = ?', [$codigo]);
    }

    private function cliente(): object
    {
        return DB::selectOne("SELECT * FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1");
    }

    /** Dados mínimos válidos: 1 betoneira por 3 dias na diária. */
    private function dados(array $mudar = []): array
    {
        $hoje = now()->toDateString();
        $prev = now()->addDays(2)->toDateString();
        return $mudar + [
            'cliente' => NovaLocacaoController::rotuloCliente($this->cliente()), 'data_saida' => $hoje, 'data_prev_devolucao' => $prev,
            'produto_id' => [$this->produto('BET-400')->id], 'quantidade' => ['1'], 'diaria' => [''], 'periodo' => ['diaria'], 'prev' => [$prev],
            'local_uso' => 'TESTE obra', '_enviado' => '1',
        ];
    }

    private function contador(): int
    {
        return (int) (DB::selectOne("SELECT ultimo FROM tenant_sequencias WHERE nome = 'locacao'")->ultimo ?? 0);
    }

    public function test_cria_locacao_com_plano_semanal_adiantamento_e_valores_do_banco(): void
    {
        $this->entrarComo();
        $bet = $this->produto('BET-400');
        $esc = $this->produto('ESC-300');
        $hoje = now()->toDateString();
        $r = $this->post('/locacoes', $this->dados([
            'produto_id' => [$bet->id, $esc->id], 'quantidade' => ['2', '10'], 'diaria' => ['', '1,20'], 'periodo' => ['semanal', 'diaria'],
            'prev' => [now()->addDays(6)->toDateString(), now()->addDays(4)->toDateString()], 'frete_entrega' => '30,00',
            'adiantamento' => '100', 'forma' => 'PIX',
        ]));
        $l = DB::selectOne("SELECT l.*, v.valor_previsto, v.valor_pago FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                             WHERE l.local_uso = 'TESTE obra'");
        $r->assertRedirect(route('locacoes.show', $l->numero));
        $this->assertSame([$hoje, now()->addDays(6)->toDateString(), 'TESTE obra'], [$l->data_saida, $l->data_prev_devolucao, $l->endereco_entrega]);
        // Semanal: 2 x 375 (7 dias) + escoras: 10 x 1,20 x 5 dias + frete 30
        $esperado = 2 * $bet->valor_semanal + 10 * 1.20 * 5 + 30;
        $this->assertEqualsWithDelta($esperado, (float) $l->valor_previsto, 0.001);
        $this->assertEquals(100, (float) $l->valor_pago);
        $itens = DB::select('SELECT produto_id, quantidade, periodo, valor_diaria, valor_semanal FROM locacao_itens WHERE locacao_id = ? ORDER BY quantidade', [$l->id]);
        $this->assertSame(['semanal', $bet->valor_diaria, $bet->valor_semanal], [$itens[0]->periodo, $itens[0]->valor_diaria, $itens[0]->valor_semanal]);
        $this->assertSame(['diaria', '1.20'], [$itens[1]->periodo, $itens[1]->valor_diaria]);
        $this->assertNotNull(DB::selectOne("SELECT 1 AS ok FROM caixa WHERE locacao_id = ? AND tipo = 'E' AND valor = 100 AND forma = 'PIX' AND descricao LIKE 'Adiantamento%'", [$l->id]));
    }

    public function test_recusas_nao_gravam_nada(): void
    {
        $this->entrarComo();
        $antes = $this->contador();
        $casos = [
            'Escolha o cliente.'                 => ['cliente' => ''],
            'Cliente não encontrado'             => ['cliente' => 'Ninguém Com Esse Nome'],
            'não pode ser no futuro'             => ['data_saida' => now()->addDay()->toDateString(), 'data_prev_devolucao' => now()->addDays(3)->toDateString(), 'prev' => [now()->addDays(3)->toDateString()]],
            'não pode ser antes da saída'        => ['prev' => [now()->subDay()->toDateString()]],
            'Inclua pelo menos um item'          => ['produto_id' => ['']],
            'Quantidade inválida na linha 1'     => ['quantidade' => ['0']],
            'Período inválido na linha 1'        => ['periodo' => ['anual']],
            'Valor inválido: abc'                => ['diaria' => ['abc']],
            'Escolha a forma de pagamento.'      => ['adiantamento' => '10', 'forma' => ''],
            'Estoque insuficiente: Betoneira'    => ['quantidade' => ['999']],
            'períodos diferentes'                => ['produto_id' => [$this->produto('BET-400')->id, $this->produto('BET-400')->id], 'quantidade' => ['1', '1'],
                                                     'periodo' => ['diaria', 'semanal'], 'diaria' => ['', ''], 'prev' => ['', '']],
        ];
        foreach ($casos as $msg => $mudar) {
            $this->post('/locacoes', $this->dados($mudar))->assertSessionHasErrors('locacao');
            $this->assertStringContainsString($msg, session('errors')->first('locacao'), "caso: $msg");
        }
        $this->assertNull(DB::selectOne("SELECT id FROM locacoes WHERE local_uso = 'TESTE obra'"));
        $this->assertSame($antes, $this->contador(), 'número de locação não pode ser consumido por recusa');
    }

    public function test_cliente_bloqueado_e_produto_inativo_sao_recusados(): void
    {
        $this->entrarComo();
        $c = $this->cliente();
        $dados = $this->dados();   // antes de bloquear: depois, cliente() escolheria outro
        DB::update('UPDATE clientes SET bloqueado = true WHERE id = ?', [$c->id]);
        $this->post('/locacoes', $dados)->assertSessionHasErrors(['locacao' => "O cliente {$c->nome} está bloqueado e não pode fazer novas locações."]);
        DB::update('UPDATE clientes SET bloqueado = false WHERE id = ?', [$c->id]);
        DB::update("UPDATE produtos SET ativo = false WHERE codigo = 'BET-400'");
        $this->post('/locacoes', $dados)->assertSessionHasErrors(['locacao' => 'O produto Betoneira 400 L está inativo.']);
    }

    public function test_desconto_de_operador_precisa_da_senha_de_um_administrador(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador Teste', 'oper.teste', ?, 'operador')", [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $this->post('/sair');
        $this->entrarComo('alfa-locacoes', 'oper.teste', 'oper12345');

        $this->post('/locacoes', $this->dados(['desconto' => '10']))->assertSessionHasErrors(['locacao' => 'Desconto ou acréscimo precisa da senha de um administrador.']);
        $this->post('/locacoes', $this->dados(['desconto' => '10', 'senha_admin' => 'errada']))->assertSessionHasErrors('locacao');
        $this->assertArrayNotHasKey('senha_admin', session()->getOldInput(), 'a senha nunca volta para o formulário');

        $this->post('/locacoes', $this->dados(['desconto' => '10', 'senha_admin' => 'admin123']))->assertRedirect();
        $l = DB::selectOne("SELECT desconto, desconto_autorizado_por FROM locacoes WHERE local_uso = 'TESTE obra'");
        $admin = DB::selectOne("SELECT id FROM usuarios WHERE login = 'admin'")->id;
        $this->assertSame(['10.00', $admin], [$l->desconto, $l->desconto_autorizado_por]);
    }

    public function test_retirada_de_reserva_converte_e_usa_o_estoque_reservado(): void
    {
        $this->entrarComo();
        $bet = $this->produto('BET-400');
        $hoje = now()->toDateString();
        $fim = now()->addDays(2)->toDateString();
        // Reserva de todo o estoque livre hoje: sem ela, não sobraria nada para outra locação
        $livre = \App\Support\Estoque::disponivel($bet->id, $hoje, $fim);
        $r = DB::selectOne("INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, status) VALUES (proximo_numero('reserva'), ?, ?, ?, 'ativa') RETURNING id, numero",
                           [$this->cliente()->id, $hoje, $fim]);
        DB::insert('INSERT INTO reserva_itens (reserva_id, produto_id, quantidade) VALUES (?, ?, ?)', [$r->id, $bet->id, $livre]);

        $this->post('/locacoes', $this->dados(['quantidade' => [(string) $livre]]))->assertSessionHasErrors('locacao');   // sem a reserva: falta estoque
        $this->get('/locacoes/nova?reserva=' . $r->id)->assertOk()->assertSee('reserva nº ' . $r->numero, false);
        $this->post('/locacoes', $this->dados(['quantidade' => [(string) $livre], 'reserva_id' => $r->id]))->assertRedirect();
        $this->assertSame('convertida', DB::selectOne('SELECT status FROM reservas WHERE id = ?', [$r->id])->status);
        $this->post('/locacoes', $this->dados(['reserva_id' => $r->id]))->assertSessionHasErrors(['locacao' => 'A reserva não está mais ativa.']);
    }

    public function test_disponivel_responde_json_e_valida_entrada(): void
    {
        $this->entrarComo();
        $bet = $this->produto('BET-400');
        $hoje = now()->toDateString();
        $this->postJson('/locacoes/disponivel', ['produto' => $bet->id, 'inicio' => $hoje, 'fim' => $hoje])
            ->assertOk()->assertExactJson(['ok' => true, 'disponivel' => \App\Support\Estoque::disponivel($bet->id, $hoje, $hoje)]);
        $this->postJson('/locacoes/disponivel', ['produto' => 'x', 'inicio' => $hoje, 'fim' => $hoje])->assertStatus(422);
        $this->postJson('/locacoes/disponivel', ['produto' => $bet->id, 'inicio' => $hoje, 'fim' => now()->subDay()->toDateString()])->assertStatus(422);
    }

    public function test_beta_nao_usa_cliente_produto_nem_reserva_da_alfa(): void
    {
        $alfa = fn (string $sql) => $this->lerComoAdmin("$sql")[0];
        $prod = $alfa("SELECT p.id FROM produtos p JOIN tenants t ON t.id = p.tenant_id WHERE t.slug = 'alfa-locacoes' AND p.codigo = 'BET-400'");
        $cli = $alfa("SELECT c.nome, c.cpf_cnpj FROM clientes c JOIN tenants t ON t.id = c.tenant_id WHERE t.slug = 'alfa-locacoes' AND c.cpf_cnpj <> ''
                       AND NOT EXISTS (SELECT 1 FROM clientes b JOIN tenants tb ON tb.id = b.tenant_id WHERE tb.slug = 'beta-eventos' AND b.nome = c.nome) LIMIT 1");
        $this->entrarComo('beta-eventos');
        $clienteBeta = NovaLocacaoController::rotuloCliente(DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado LIMIT 1"));
        $prodBeta = DB::selectOne('SELECT id FROM produtos WHERE ativo LIMIT 1')->id;
        $hoje = now()->toDateString();
        $base = ['data_saida' => $hoje, 'data_prev_devolucao' => $hoje, 'quantidade' => ['1'], 'diaria' => [''], 'periodo' => ['diaria'], 'prev' => [$hoje]];

        $this->post('/locacoes', $base + ['cliente' => NovaLocacaoController::rotuloCliente($cli), 'produto_id' => [$prodBeta]])
            ->assertSessionHasErrors(['locacao' => 'Cliente não encontrado. Escolha um cliente da lista ou cadastre-o antes.']);
        $this->post('/locacoes', $base + ['cliente' => $clienteBeta, 'produto_id' => [$prod->id]])->assertSessionHasErrors(['locacao' => 'Produto não encontrado.']);
        $this->postJson('/locacoes/disponivel', ['produto' => $prod->id, 'inicio' => $hoje, 'fim' => $hoje])->assertExactJson(['ok' => true, 'disponivel' => 0]);
        $this->get('/locacoes/nova?copiar=' . $alfa("SELECT l.id FROM locacoes l JOIN tenants t ON t.id = l.tenant_id WHERE t.slug = 'alfa-locacoes' LIMIT 1")->id)->assertNotFound();
    }
}

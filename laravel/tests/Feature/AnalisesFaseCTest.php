<?php

namespace Tests\Feature;

use App\Http\Controllers\NovaLocacaoController;
use App\Support\Relatorios;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Análises da Fase C: DRE simples, ocupação da frota por mês e conversão de orçamentos e reservas. */
class AnalisesFaseCTest extends TestCase
{
    /** Locação de teste com saída há $diasAtras dias: 10 escoras de diária, identificada por $local. */
    private function locacao(int $diasAtras, string $local): void
    {
        $saida = now()->subDays($diasAtras)->toDateString();
        $prev = now()->addDays(2)->toDateString();
        $esc = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'ESC-300'")->id;
        $c = DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1");
        $this->post('/locacoes', ['cliente' => NovaLocacaoController::rotuloCliente($c), 'data_saida' => $saida, 'data_prev_devolucao' => $prev,
            'produto_id' => [$esc], 'quantidade' => ['10'], 'diaria' => ['1,20'], 'periodo' => ['diaria'],
            'prev' => [$prev], 'local_uso' => $local])->assertSessionHasNoErrors();
    }

    /** Linhas de um relatório do painel de análises para o período. */
    private function relatorio(string $chave, string $de, string $ate): array
    {
        return Relatorios::executar(Relatorios::definicoes()[$chave], $de, $ate);
    }

    /** Linha do mês atual (o relatório é mensal). */
    private function doMes(array $linhas): array
    {
        return collect($linhas)->firstWhere('mes', now()->format('m/Y'));
    }

    public function test_dre_separa_as_entradas_e_as_saidas_pela_origem(): void
    {
        $this->entrarComo();
        $de = now()->startOfMonth()->toDateString();
        $ate = now()->toDateString();
        $antes = $this->doMes($this->relatorio('dre', $de, $ate));

        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma) VALUES (CURRENT_DATE, 'E', 'Teste', 'TESTE dre entrada', 100, 'PIX')");
        $aluguel = DB::selectOne("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma)
                                  VALUES (CURRENT_DATE, 'S', 'Aluguel', 'TESTE dre aluguel', 40, 'PIX') RETURNING id")->id;
        DB::insert("INSERT INTO contas_pagar (descricao, categoria, valor, vencimento, pago_em, caixa_id)
                    VALUES ('TESTE dre aluguel', 'Aluguel', 40, CURRENT_DATE, CURRENT_DATE, ?)", [$aluguel]);
        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma) VALUES (CURRENT_DATE, 'S', 'Outros', 'TESTE dre outras', 30, 'PIX')");

        $depois = $this->doMes($this->relatorio('dre', $de, $ate));
        $this->assertEqualsWithDelta(100, $depois['outras_entradas'] - $antes['outras_entradas'], 0.001);
        $this->assertEqualsWithDelta(40, $depois['contas'] - $antes['contas'], 0.001, 'a saída paga por conta a pagar');
        $this->assertEqualsWithDelta(30, $depois['outras_saidas'] - $antes['outras_saidas'], 0.001);
        $this->assertEqualsWithDelta(70, $depois['saidas'] - $antes['saidas'], 0.001);
        $this->assertEqualsWithDelta(30, $depois['resultado'] - $antes['resultado'], 0.001, 'resultado = entradas − saídas');
    }

    public function test_ocupacao_conta_os_dias_do_equipamento_na_rua(): void
    {
        $this->entrarComo();
        $de = now()->subDays(3)->toDateString();
        $ate = now()->toDateString();
        $antes = array_sum(array_column($this->relatorio('ocupacao_mensal', $de, $ate), 'locado'));

        $this->locacao(3, 'TESTE ocupacao');   // 10 escoras saíram há 3 dias

        $depois = array_sum(array_column($this->relatorio('ocupacao_mensal', $de, $ate), 'locado'));
        $this->assertEqualsWithDelta(30, $depois - $antes, 0.001, '10 unidades × 3 dias');
    }

    public function test_conversao_conta_orcamentos_e_reservas_por_situacao(): void
    {
        $this->entrarComo();
        $de = now()->startOfMonth()->toDateString();
        $ate = now()->toDateString();
        $antes = $this->doMes($this->relatorio('conversao', $de, $ate));

        $cliente = DB::selectOne('SELECT id FROM clientes WHERE ativo LIMIT 1')->id;
        $hoje = now()->toDateString();
        $proximoOrcamento = fn () => (int) DB::selectOne('SELECT COALESCE(MAX(numero), 0) + 1 AS n FROM orcamentos')->n;
        $proximaReserva = fn () => (int) DB::selectOne('SELECT COALESCE(MAX(numero), 0) + 1 AS n FROM reservas')->n;

        // Orçamento convertido precisa apontar para a reserva (ou locação) que o originou
        $reservaConvertida = DB::selectOne("INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, status)
                                            VALUES (?, ?, ?, ?, 'convertida') RETURNING id", [$proximaReserva(), $cliente, $hoje, $hoje])->id;
        DB::insert("INSERT INTO orcamentos (numero, cliente_id, validade, data_saida, data_prev_devolucao, status, reserva_id)
                    VALUES (?, ?, ?, ?, ?, 'convertido', ?)", [$proximoOrcamento(), $cliente, now()->addDays(10)->toDateString(), $hoje, $hoje, $reservaConvertida]);
        DB::insert("INSERT INTO orcamentos (numero, cliente_id, validade, data_saida, data_prev_devolucao, status)
                    VALUES (?, ?, ?, ?, ?, 'aberto')", [$proximoOrcamento(), $cliente, now()->addDays(10)->toDateString(), $hoje, $hoje]);

        DB::insert("INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, status) VALUES (?, ?, ?, ?, 'cancelada')",
            [$proximaReserva(), $cliente, $hoje, $hoje]);
        DB::insert("INSERT INTO reservas (numero, cliente_id, data_inicio, data_fim, status) VALUES (?, ?, ?, ?, 'ativa')",
            [$proximaReserva(), $cliente, now()->subDays(5)->toDateString(), now()->subDays(2)->toDateString()]);

        $depois = $this->doMes($this->relatorio('conversao', $de, $ate));
        $this->assertSame(
            [2, 1, 1, 3, 1, 1, 1],
            [$depois['orc_emitidos'] - $antes['orc_emitidos'], $depois['orc_convertidos'] - $antes['orc_convertidos'],
                $depois['orc_vigentes'] - $antes['orc_vigentes'], $depois['res_criadas'] - $antes['res_criadas'],
                $depois['res_convertidas'] - $antes['res_convertidas'], $depois['res_canceladas'] - $antes['res_canceladas'],
                $depois['res_vencidas'] - $antes['res_vencidas']]
        );
    }

    public function test_as_tres_analises_abrem_pela_pagina_de_relatorios(): void
    {
        $this->entrarComo();
        $de = now()->startOfMonth()->subMonths(2)->toDateString();
        $ate = now()->toDateString();

        $this->get("/relatorios/dre?de=$de&ate=$ate")->assertOk()->assertSee('DRE simples por mês');
        $this->get("/relatorios/ocupacao_mensal?de=$de&ate=$ate")->assertOk()->assertSee('Ocupação da frota por mês');
        $this->get("/relatorios/conversao?de=$de&ate=$ate")->assertOk()->assertSee('Conversão de orçamentos e reservas');
    }

    public function test_a_dre_so_aparece_e_abre_para_o_administrador(): void
    {
        $this->entrarComo();
        DB::insert("INSERT INTO usuarios (nome, login, senha_hash, perfil) VALUES ('Operador DRE', 'oper.dre', ?, 'operador')",
            [password_hash('oper12345', PASSWORD_DEFAULT)]);
        $de = now()->startOfMonth()->toDateString();
        $ate = now()->toDateString();
        $this->get('/relatorios')->assertSee('DRE simples por mês');

        $this->post('/sair');
        $this->entrarComo('alfa-locacoes', 'oper.dre', 'oper12345');
        $this->get('/relatorios')->assertOk()->assertDontSee('DRE simples por mês')->assertSee('Faturamento por mês');
        $this->get("/relatorios/dre?de=$de&ate=$ate")->assertForbidden();
        $this->get("/relatorios/dre?de=$de&ate=$ate&csv=1")->assertForbidden();
        $this->get("/relatorios/dre?de=$de&ate=$ate&imprimir=1")->assertForbidden();
    }
}

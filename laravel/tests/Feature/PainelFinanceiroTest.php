<?php

namespace Tests\Feature;

use App\Http\Controllers\NovaLocacaoController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Resultado do mês no painel da locadora: faturado, recebido, idade do saldo e isolamento entre locadoras. */
class PainelFinanceiroTest extends TestCase
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

    /** Números do painel desta locadora. */
    private function fin(): array
    {
        return $this->get('/painel')->assertOk()->viewData('fin');
    }

    public function test_recebido_soma_as_entradas_do_caixa_no_mes(): void
    {
        $this->entrarComo();
        $antes = $this->fin()['mes']['recebido'];

        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma) VALUES (CURRENT_DATE, 'E', 'Teste', 'TESTE recebido', 250, 'PIX')");
        $saidas = $this->fin()['mes']['saidas'];

        $this->assertEqualsWithDelta($antes + 250, $this->fin()['mes']['recebido'], 0.001);
        $this->assertEqualsWithDelta($saidas, $this->fin()['mes']['saidas'], 0.001, 'entrada não mexe nas saídas');
    }

    public function test_faturado_conta_a_locacao_iniciada_no_mes_pelo_valor_previsto(): void
    {
        $this->entrarComo();
        $antes = $this->fin()['mes'];

        $this->locacao(0, 'TESTE faturado');

        $depois = $this->fin()['mes'];
        $previsto = (float) DB::selectOne("SELECT v.valor_previsto FROM locacoes l JOIN vw_locacao_valores v ON v.locacao_id = l.id
                                            WHERE l.local_uso = 'TESTE faturado'")->valor_previsto;
        $this->assertSame($antes['locacoes'] + 1, $depois['locacoes']);
        $this->assertEqualsWithDelta($antes['faturado'] + $previsto, $depois['faturado'], 0.001);
    }

    public function test_idade_do_saldo_coloca_a_locacao_na_faixa_certa(): void
    {
        $this->entrarComo();
        $antes = $this->fin()['idade'];

        $this->locacao(45, 'TESTE idade');   // saiu há 45 dias: faixa de 31 a 60 dias

        $depois = $this->fin()['idade'];
        $saldo = (float) DB::selectOne("SELECT GREATEST(v.valor_atual - v.valor_pago, 0) AS saldo FROM locacoes l
                                          JOIN vw_locacao_valores v ON v.locacao_id = l.id WHERE l.local_uso = 'TESTE idade'")->saldo;
        $this->assertEqualsWithDelta($antes['faixas'][0]['valor'], $depois['faixas'][0]['valor'], 0.001, 'até 30 dias não muda');
        $this->assertEqualsWithDelta($antes['faixas'][1]['valor'] + $saldo, $depois['faixas'][1]['valor'], 0.001);
        $this->assertEqualsWithDelta($antes['total'] + $saldo, $depois['total'], 0.001, 'o total é o "Total a receber" do painel');
    }

    public function test_pagamento_no_dia_da_saida_conta_prazo_zero_ou_mais(): void
    {
        $this->entrarComo();
        $this->locacao(0, 'TESTE prazo');
        $l = DB::selectOne("SELECT id, data_saida FROM locacoes WHERE local_uso = 'TESTE prazo'");
        DB::insert("INSERT INTO caixa (data, tipo, categoria, descricao, valor, forma, locacao_id) VALUES (?, 'E', 'Locação', 'TESTE prazo', 50, 'PIX', ?)",
            [$l->data_saida, $l->id]);

        $prazo = $this->fin()['prazoPagamento'];
        $this->assertNotNull($prazo);
        $this->assertGreaterThanOrEqual(0, $prazo);
    }

    public function test_cada_locadora_ve_so_os_proprios_numeros(): void
    {
        $this->entrarComo('beta-eventos');
        $antes = $this->fin()['mes']['locacoes'];
        $this->post('/sair');

        $this->entrarComo();
        $this->locacao(0, 'TESTE isolamento');
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->assertSame($antes, $this->fin()['mes']['locacoes'], 'a Beta não vê a locação da Alfa');
    }

    public function test_painel_mostra_a_secao_de_resultado(): void
    {
        $this->entrarComo();
        $this->get('/painel')->assertOk()
            ->assertSee('Resultado do mês')
            ->assertSee('Faturado e recebido, 12 meses')
            ->assertSee('Idade do saldo a receber');
    }
}

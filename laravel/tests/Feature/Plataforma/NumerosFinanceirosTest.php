<?php

namespace Tests\Feature\Plataforma;

use App\Support\NumerosFinanceiros;
use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Números financeiros da assinatura: MRR, inadimplência por idade, conversão do teste grátis e a tela. */
class NumerosFinanceirosTest extends PlataformaTestCase
{
    /** Cobrança do Asaas de uma locadora, vencida (ou não) a $diasAtras dias. */
    private function cobranca(string $locadoraId, int $diasAtras, float $valor, string $status = 'pendente'): void
    {
        DB::connection('plataforma')->insert(
            "INSERT INTO assinatura_cobrancas (locadora_id, vencimento, valor, status, link, criado_em, atualizado_em)
             VALUES (?, CURRENT_DATE - ?::int, ?, ?, '', now(), now())",
            [$locadoraId, $diasAtras, $valor, $status]
        );
    }

    private function pagamento(string $locadoraId, float $valor = 99.0): void
    {
        DB::connection('plataforma')->insert(
            'INSERT INTO assinatura_pagamentos (locadora_id, data, meses, valor, vencimento_anterior, vencimento_novo)
             VALUES (?, CURRENT_DATE, 1, ?, CURRENT_DATE, CURRENT_DATE + 30)', [$locadoraId, $valor]
        );
    }

    public function test_mrr_soma_so_as_locadoras_nao_canceladas_com_plano_pago(): void
    {
        $antes = NumerosFinanceiros::painel()['mrr'];

        $id = $this->criarLocadora(['plano' => $this->criarPlano(150.0)]);
        $this->criarLocadora(['plano' => $this->criarPlano(0.0)]);   // plano gratuito não entra
        $this->assertEqualsWithDelta($antes + 150, NumerosFinanceiros::painel()['mrr'], 0.001);
        $this->assertEqualsWithDelta(($antes + 150) * 12, NumerosFinanceiros::painel()['arr'], 0.001);

        DB::connection('plataforma')->update("UPDATE tenants SET status = 'cancelada', cancelada_em = now() WHERE id = ?", [$id]);
        $this->assertEqualsWithDelta($antes, NumerosFinanceiros::painel()['mrr'], 0.001, 'a cancelada sai do MRR');
    }

    public function test_cancelamento_do_mes_entra_no_churn_em_valor(): void
    {
        $antes = NumerosFinanceiros::painel();
        $id = $this->criarLocadora(['plano' => $this->criarPlano(200.0)]);
        DB::connection('plataforma')->update("UPDATE tenants SET status = 'cancelada', cancelada_em = now() WHERE id = ?", [$id]);

        $depois = NumerosFinanceiros::painel();
        $this->assertSame($antes['churnQuantidade'] + 1, $depois['churnQuantidade']);
        $this->assertEqualsWithDelta($antes['churnValor'] + 200, $depois['churnValor'], 0.001);
    }

    public function test_inadimplencia_separa_as_faixas_de_atraso_e_ignora_o_que_foi_pago(): void
    {
        $antes = NumerosFinanceiros::painel()['inadimplencia'];
        $id = $this->criarLocadora();
        $this->cobranca($id, 10, 100.0);            // 1 a 30 dias
        $this->cobranca($id, 45, 200.0);            // 31 a 60 dias
        $this->cobranca($id, 90, 300.0);            // mais de 60 dias
        $this->cobranca($id, 20, 999.0, 'paga');     // paga: fica de fora
        $this->cobranca($id, -5, 999.0);             // ainda não venceu: fica de fora

        $depois = NumerosFinanceiros::painel()['inadimplencia'];
        $deltas = array_map(fn ($a, $b) => $b['valor'] - $a['valor'], $antes['faixas'], $depois['faixas']);
        $this->assertEqualsWithDelta([100.0, 200.0, 300.0], $deltas, 0.001);
        $this->assertSame($antes['quantidade'] + 3, $depois['quantidade']);
        $this->assertEqualsWithDelta($antes['valor'] + 600, $depois['valor'], 0.001);
    }

    public function test_locadora_cancelada_nao_aparece_na_inadimplencia(): void
    {
        $antes = NumerosFinanceiros::painel()['inadimplencia']['valor'];
        $id = $this->criarLocadora();
        $this->cobranca($id, 15, 500.0);
        DB::connection('plataforma')->update("UPDATE tenants SET status = 'cancelada', cancelada_em = now() WHERE id = ?", [$id]);

        $this->assertEqualsWithDelta($antes, NumerosFinanceiros::painel()['inadimplencia']['valor'], 0.001);
    }

    public function test_conversao_conta_quem_pagou_depois_do_teste(): void
    {
        $antes = NumerosFinanceiros::painel()['teste'];
        $ontem = now()->subDay()->toDateString();

        $pagou = $this->criarLocadora(['teste_ate' => $ontem]);
        $this->pagamento($pagou);
        $this->criarLocadora(['teste_ate' => $ontem]);                 // acabou o teste e não pagou
        $this->criarLocadora(['teste_ate' => now()->addDays(5)->toDateString()]);   // ainda em teste

        $depois = NumerosFinanceiros::painel()['teste'];
        $this->assertSame([$antes['encerrados'] + 2, $antes['pagaram'] + 1, $antes['emTeste'] + 1],
            [$depois['encerrados'], $depois['pagaram'], $depois['emTeste']]);
    }

    public function test_numeros_aparecem_na_tela_da_plataforma(): void
    {
        $this->entrarNaPlataforma();
        $this->get(route('plataforma.numeros'))->assertOk()
            ->assertSee('Receita recorrente (MRR)')
            ->assertSee('Inadimplência por atraso')
            ->assertSee('Teste grátis que virou pagamento')
            ->assertSee('Maiores atrasos');
    }

    public function test_numeros_so_para_a_plataforma(): void
    {
        $this->get(route('plataforma.numeros'))->assertRedirect(route('plataforma.login'));
    }
}

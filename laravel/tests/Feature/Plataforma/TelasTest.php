<?php

namespace Tests\Feature\Plataforma;

use App\Support\NumerosNegocio;
use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Telas de leitura do painel: números do negócio, uso por locadora e auditoria do painel. */
class TelasTest extends PlataformaTestCase
{
    public function test_numeros_somam_as_mensalidades_recebidas_no_mes(): void
    {
        $this->entrarNaPlataforma();
        $antes = NumerosNegocio::painel()['receitaMes'];
        $id = $this->criarLocadora();
        DB::connection('plataforma')->insert(
            'INSERT INTO assinatura_pagamentos (locadora_id, data, meses, valor, vencimento_anterior, vencimento_novo)
             VALUES (?, CURRENT_DATE, 1, 150, CURRENT_DATE, CURRENT_DATE + 30)', [$id]
        );

        $this->assertEqualsWithDelta($antes + 150, NumerosNegocio::painel()['receitaMes'], 0.001);
        $this->get(route('plataforma.numeros'))->assertOk()->assertSee('Receita do mês')->assertSee('Ticket médio');
    }

    public function test_uso_lista_as_locadoras_sem_dados_de_clientes(): void
    {
        $this->entrarNaPlataforma();
        $this->criarLocadora(['nome' => 'Locadora Uso Teste']);
        $this->get(route('plataforma.uso'))->assertOk()->assertSee('Uso por locadora')->assertSee('Locadora Uso Teste');
    }

    public function test_auditoria_registra_o_que_o_painel_publica(): void
    {
        $this->entrarNaPlataforma();
        $this->post(route('plataforma.recados.criar'), [
            'tipo' => 'novidade', 'titulo' => 'Recado auditado', 'texto' => 'Texto do recado para auditoria.',
            'expira_em' => now()->addDays(3)->toDateString(),
        ]);

        $this->get(route('plataforma.auditoria', ['tabela' => 'recados']))->assertOk()
            ->assertSee('Recado')->assertSee('Recado auditado')->assertSee('incluiu');
    }
}

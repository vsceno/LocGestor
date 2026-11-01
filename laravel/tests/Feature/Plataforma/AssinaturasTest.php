<?php

namespace Tests\Feature\Plataforma;

use App\Support\Assinaturas;
use Tests\PlataformaTestCase;

/** Regras de mensalidade, alertas e filtros do painel: as mesmas contas de assinatura_situacao() e locadora_alertas(). */
class AssinaturasTest extends PlataformaTestCase
{
    /** Uma locadora em dia, com o que o teste quer mudar. */
    private function locadora(array $campos = []): object
    {
        return (object) ($campos + [
            'status' => 'ativa', 'motivo_suspensao' => '', 'vencimento' => null, 'carencia_dias' => 5,
            'ultimo_acesso' => now()->toDateString(), 'criado_em' => now()->toDateString(),
        ]);
    }

    private function dia(int $dias): string
    {
        return now()->addDays($dias)->toDateString();
    }

    public function test_situacao_da_mensalidade(): void
    {
        $this->assertSame(['sem cobrança', 'selo-cinza'], Assinaturas::situacao($this->locadora()));
        $this->assertSame(['em dia', 'selo-verde'], Assinaturas::situacao($this->locadora(['vencimento' => $this->dia(10)])));
        $this->assertSame(['vence em 3 dia(s)', 'selo-amarelo'], Assinaturas::situacao($this->locadora(['vencimento' => $this->dia(3)])));
        $this->assertSame(['vence hoje', 'selo-amarelo'], Assinaturas::situacao($this->locadora(['vencimento' => $this->dia(0)])));
        // Vencida, mas dentro da carência: ainda tem acesso
        $this->assertSame(['vencida há 2 dia(s)', 'selo-amarelo'], Assinaturas::situacao($this->locadora(['vencimento' => $this->dia(-2)])));
        // Passou da carência (5 dias): inadimplente
        $this->assertSame(['inadimplente', 'selo-vermelho'], Assinaturas::situacao($this->locadora(['vencimento' => $this->dia(-10)])));
    }

    public function test_dias_e_limite_de_pagamento(): void
    {
        $this->assertSame(3, Assinaturas::diasAte($this->dia(3)));
        $this->assertSame(-4, Assinaturas::diasAte($this->dia(-4)));
        $this->assertSame('2026-10-06', Assinaturas::limitePagamento($this->locadora(['vencimento' => '2026-10-01', 'carencia_dias' => 5])));
        $this->assertNull(Assinaturas::limitePagamento($this->locadora()));
    }

    public function test_alertas_avisam_o_que_pede_atencao(): void
    {
        $this->assertSame([], Assinaturas::alertas($this->locadora(['vencimento' => $this->dia(20)])));
        $this->assertSame(['suspensa por inadimplência'],
                          Assinaturas::alertas($this->locadora(['status' => 'suspensa', 'motivo_suspensao' => 'inadimplencia'])));
        $this->assertSame(['mensalidade vencida há 1 dia(s)'], Assinaturas::alertas($this->locadora(['vencimento' => $this->dia(-1)])));

        $ultimo = now()->subDays(10)->toDateString();
        $this->assertSame(['sem acesso desde ' . data_br($ultimo)], Assinaturas::alertas($this->locadora(['ultimo_acesso' => $ultimo])));
        $this->assertSame(['nunca acessou'],
                          Assinaturas::alertas($this->locadora(['ultimo_acesso' => null, 'criado_em' => now()->subDays(10)->toDateString()])));
    }

    public function test_filtros_da_lista(): void
    {
        $vencida = $this->locadora(['vencimento' => $this->dia(-2)]);
        $vencida->alertas = Assinaturas::alertas($vencida);

        $this->assertTrue(Assinaturas::passaFiltro($vencida, 'vencidas'));
        $this->assertTrue(Assinaturas::passaFiltro($vencida, 'atencao'));
        $this->assertFalse(Assinaturas::passaFiltro($vencida, 'sem_cobranca'));
        $this->assertFalse(Assinaturas::passaFiltro($vencida, 'suspensas'));
    }

    public function test_formulario_do_plano_e_da_carencia(): void
    {
        $r = Assinaturas::formulario(['plano_id' => 'nao-e-uuid', 'vencimento' => '2026-02-31', 'carencia_dias' => '99']);
        $this->assertSame(['Escolha o plano.', 'Data de vencimento inválida.', 'A carência deve ser de 0 a 60 dias.'], $r['erros']);

        $ok = Assinaturas::formulario(['plano_id' => $this->criarPlano()->id, 'vencimento' => '', 'carencia_dias' => '0']);
        $this->assertSame([], $ok['erros']);
        $this->assertNull($ok['vencimento']);
        $this->assertSame(0, $ok['carencia']);
    }
}

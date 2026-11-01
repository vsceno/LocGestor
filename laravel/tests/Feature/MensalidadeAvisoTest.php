<?php

namespace Tests\Feature;

use App\Support\Mensalidade;
use Tests\TestCase;

/** Aviso da mensalidade na tela da locadora: quando aparece e o texto, como no layout do sistema antigo. */
class MensalidadeAvisoTest extends TestCase
{
    private function locadora(string $vencimento = '2026-11-10', int $carencia = 5): object
    {
        return (object) ['vencimento' => $vencimento, 'carencia_dias' => $carencia, 'plano' => 'Profissional'];
    }

    public function test_longe_do_vencimento_e_sem_fatura_nao_mostra_nada(): void
    {
        $this->assertNull(Mensalidade::montar($this->locadora(), 10, '', ''));
    }

    public function test_perto_do_vencimento_avisa_o_prazo(): void
    {
        $this->assertSame(
            ['tipo' => 'aviso', 'link' => '', 'texto' => 'Sua mensalidade do LocGestor (plano Profissional) vence em 3 dias (10/11/2026).'],
            Mensalidade::montar($this->locadora(), 3, '', '')
        );
        $this->assertStringContainsString('vence hoje', Mensalidade::montar($this->locadora(), 0, '', '')['texto']);
        $this->assertStringContainsString('vence amanhã', Mensalidade::montar($this->locadora(), 1, '', '')['texto']);
    }

    public function test_vencida_avisa_ate_quando_regularizar_e_o_contato(): void
    {
        $aviso = Mensalidade::montar($this->locadora('2026-10-20', 5), -10, '', 'contato@locgestor.test');

        $this->assertSame('erro', $aviso['tipo']);
        $this->assertSame('Mensalidade do LocGestor vencida desde 20/10/2026. Regularize até 25/10/2026 para evitar a suspensão do acesso. Contato: contato@locgestor.test.',
                          $aviso['texto']);
    }

    public function test_fatura_em_aberto_mostra_o_link_mesmo_longe_do_vencimento(): void
    {
        $aviso = Mensalidade::montar($this->locadora(), 20, 'https://asaas.teste/fatura/1', '');

        $this->assertSame(['aviso', 'https://asaas.teste/fatura/1'], [$aviso['tipo'], $aviso['link']]);
    }
}

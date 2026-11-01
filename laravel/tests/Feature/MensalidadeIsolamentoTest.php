<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fatura em aberto na tela da locadora: o botão de pagamento aparece só para a própria locadora (como o sistema antigo). */
class MensalidadeIsolamentoTest extends TestCase
{
    /** Cobrança gravada confirmada por este teste. A plataforma e a locadora usam conexões diferentes: o teste grava pela de superusuário. */
    private ?string $cobrancaGravada = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Roda depois do rollback da transação do teste
        $this->beforeApplicationDestroyed(function () {
            if ($this->cobrancaGravada !== null) {
                DB::connection('teste_admin')->delete('DELETE FROM assinatura_cobrancas WHERE id = ?', [$this->cobrancaGravada]);
            }
        });
    }

    public function test_fatura_em_aberto_aparece_so_para_a_propria_locadora(): void
    {
        $alfa = $this->idLocadora('alfa-locacoes');
        $this->cobrancaGravada = DB::connection('teste_admin')->selectOne(
            "INSERT INTO assinatura_cobrancas (locadora_id, vencimento, valor, status, gateway_id, link)
             VALUES (?, ?, 99, 'pendente', 'pay_ISO1', 'https://asaas.teste/fatura/iso-alfa') RETURNING id",
            [$alfa, now()->addDays(400)->toDateString()]
        )->id;

        $this->entrarComo('alfa-locacoes')->get('/painel')->assertOk()
            ->assertSee('Pagar agora')->assertSee('https://asaas.teste/fatura/iso-alfa', false);

        $this->entrarComo('beta-eventos')->get('/painel')->assertOk()
            ->assertDontSee('iso-alfa');
    }
}

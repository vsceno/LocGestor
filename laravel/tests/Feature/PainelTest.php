<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Painel da locadora: locações em aberto e total a receber, só com os números da própria locadora (como o sistema antigo). */
class PainelTest extends TestCase
{
    public function test_painel_mostra_os_numeros_so_da_propria_locadora(): void
    {
        foreach (['alfa-locacoes', 'beta-eventos'] as $slug) {
            $this->entrarComo($slug);
            $abertas = (int) DB::selectOne("SELECT count(*) AS n FROM locacoes WHERE status = 'aberta'")->n;
            // Saldo a receber sem valor negativo (um pagamento a mais não diminui o que as outras locações devem)
            $receber = (float) DB::selectOne("SELECT COALESCE(sum(greatest(v.valor_atual - v.valor_pago, 0)), 0) AS s
                                                FROM vw_locacao_valores v JOIN locacoes l ON l.id = v.locacao_id
                                               WHERE l.status <> 'cancelada'")->s;

            $this->get('/painel')->assertOk()
                ->assertSee('Locações em aberto')->assertSee(numero($abertas))
                ->assertSee('Total a receber')->assertSee(moeda($receber));
        }
    }
}

<?php

namespace Tests\Feature\Plataforma;

use Illuminate\Support\Facades\DB;
use Tests\PlataformaTestCase;

/** Recados para as locadoras: vigência de até 1 ano, a partir de hoje, e retirada a qualquer momento. */
class RecadosTest extends PlataformaTestCase
{
    private function recado(array $sobre = []): array
    {
        return $sobre + [
            'tipo' => 'aviso', 'titulo' => 'Manutenção amanhã', 'texto' => 'O sistema fica fora do ar das 22h às 23h.',
            'expira_em' => now()->addDays(7)->toDateString(),
        ];
    }

    public function test_publica_e_retira_um_recado(): void
    {
        $this->entrarNaPlataforma();
        $this->post(route('plataforma.recados.criar'), $this->recado())->assertRedirect(route('plataforma.recados.index'));

        $r = DB::connection('plataforma')->selectOne("SELECT id, ativo, usuario FROM recados WHERE titulo = 'Manutenção amanhã'");
        $this->assertSame([true, 'suporte'], [$r->ativo, $r->usuario]);

        $this->post(route('plataforma.recados.desativar', $r->id))->assertRedirect(route('plataforma.recados.index'));
        $this->assertFalse(DB::connection('plataforma')->selectOne('SELECT ativo FROM recados WHERE id = ?', [$r->id])->ativo);
    }

    public function test_validade_no_passado_ou_longa_demais_e_recusada(): void
    {
        $this->entrarNaPlataforma();
        $this->post(route('plataforma.recados.criar'), $this->recado(['expira_em' => now()->subDay()->toDateString()]))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'no máximo 1 ano'));
        $this->post(route('plataforma.recados.criar'), $this->recado(['expira_em' => now()->addYears(2)->toDateString()]))
            ->assertSessionHas('flash', fn ($f) => str_contains($f[0]['msg'], 'no máximo 1 ano'));
        $this->assertNull(DB::connection('plataforma')->selectOne("SELECT id FROM recados WHERE titulo = 'Manutenção amanhã'"));
    }
}

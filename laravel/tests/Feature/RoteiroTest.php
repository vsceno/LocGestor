<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Roteiro do motorista: regras de roteiro_paradas(), roteiro_marcar() e roteiro_desmarcar() do sistema antigo. */
class RoteiroTest extends TestCase
{
    /** Locação com saída já passada e sem entrega registrada no dia (ou null se a demonstração não tiver). */
    private function entregaSemRegistro(): ?object
    {
        return DB::selectOne("SELECT l.id, l.numero, l.data_saida FROM locacoes l
                               WHERE l.status <> 'cancelada' AND l.data_saida <= CURRENT_DATE
                                 AND NOT EXISTS (SELECT 1 FROM entregas e WHERE e.locacao_id = l.id AND e.tipo = 'entrega' AND e.data = l.data_saida)
                               ORDER BY l.data_saida DESC LIMIT 1");
    }

    public function test_marca_e_desfaz_a_entrega_do_dia(): void
    {
        $this->entrarComo();
        $l = $this->entregaSemRegistro();
        if ($l === null) {
            $this->markTestSkipped('Sem entrega pendente nos dados de demonstração.');
        }
        $this->get("/roteiro?data={$l->data_saida}")->assertOk()->assertSee("nº {$l->numero}")->assertSee('pendente');

        $this->post('/roteiro/marcar', ['id' => $l->id, 'tipo' => 'entrega', 'data' => $l->data_saida, 'obs' => 'TESTE entregue'])
            ->assertRedirect(route('roteiro.index', ['data' => $l->data_saida]));
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM entregas WHERE locacao_id = ? AND tipo = 'entrega' AND data = ?",
                                                 [$l->id, $l->data_saida])->n);
        $this->get("/roteiro?data={$l->data_saida}")->assertOk()->assertSee('TESTE entregue');

        $this->post('/roteiro/desmarcar', ['id' => $l->id, 'tipo' => 'entrega', 'data' => $l->data_saida])
            ->assertRedirect(route('roteiro.index', ['data' => $l->data_saida]));
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM entregas WHERE locacao_id = ? AND tipo = 'entrega' AND data = ?",
                                                 [$l->id, $l->data_saida])->n);
    }

    public function test_recusa_parada_que_nao_esta_no_roteiro_do_dia(): void
    {
        $this->entrarComo();
        $hoje = now()->toDateString();
        $antes = (int) DB::selectOne('SELECT count(*) AS n FROM entregas')->n;
        $this->post('/roteiro/marcar', ['id' => (string) Str::uuid(), 'tipo' => 'entrega', 'data' => $hoje])
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Esta parada não está no roteiro do dia.']]);
        $this->post('/roteiro/marcar', ['id' => 'nao-e-uuid', 'tipo' => 'entrega', 'data' => $hoje])
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Parada inválida.']]);
        $this->assertSame($antes, (int) DB::selectOne('SELECT count(*) AS n FROM entregas')->n, 'recusa não pode registrar entrega');
    }

    public function test_beta_nao_marca_parada_da_alfa(): void
    {
        $this->entrarComo();
        $l = $this->entregaSemRegistro();
        if ($l === null) {
            $this->markTestSkipped('Sem entrega pendente nos dados de demonstração.');
        }
        $this->post('/sair');

        $this->entrarComo('beta-eventos');
        $this->post('/roteiro/marcar', ['id' => $l->id, 'tipo' => 'entrega', 'data' => $l->data_saida])
            ->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Esta parada não está no roteiro do dia.']]);
        $this->get("/roteiro?data={$l->data_saida}")->assertOk();
    }
}

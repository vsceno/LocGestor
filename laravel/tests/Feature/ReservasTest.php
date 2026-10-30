<?php

namespace Tests\Feature;

use App\Support\Formulario;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Reservas: regras de reserva_salvar() e reserva_cancelar() do sistema antigo. */
class ReservasTest extends TestCase
{
    private function dados(array $mudar = []): array
    {
        $c = DB::selectOne("SELECT nome, cpf_cnpj FROM clientes WHERE ativo AND NOT bloqueado AND cpf_cnpj <> '' ORDER BY nome LIMIT 1");
        return $mudar + ['cliente' => Formulario::rotuloCliente($c), 'data_inicio' => now()->addDays(10)->toDateString(), 'data_fim' => now()->addDays(12)->toDateString(),
                         'produto_id' => [DB::selectOne("SELECT id FROM produtos WHERE codigo = 'ESC-300'")->id], 'quantidade' => ['5'], 'periodo' => ['semanal'], 'obs' => 'TESTE reserva'];
    }

    public function test_cria_edita_e_cancela(): void
    {
        $this->entrarComo();
        $this->post('/reservas', $this->dados())->assertSessionHasNoErrors();
        $r = DB::selectOne("SELECT * FROM reservas WHERE obs = 'TESTE reserva'");
        $this->assertSame('ativa', $r->status);
        $this->assertSame(['5', 'semanal'], [(string) DB::selectOne('SELECT quantidade FROM reserva_itens WHERE reserva_id = ?', [$r->id])->quantidade,
                                             DB::selectOne('SELECT periodo FROM reserva_itens WHERE reserva_id = ?', [$r->id])->periodo]);
        $this->get("/reservas/{$r->numero}")->assertOk()->assertSee('Reserva nº ' . $r->numero);

        // Na edição, a própria reserva não conta contra o estoque: pedir todo o disponível deve passar
        $esc = DB::selectOne("SELECT id FROM produtos WHERE codigo = 'ESC-300'")->id;
        $livre = \App\Support\Estoque::disponivel($esc, $r->data_inicio, $r->data_fim, $r->id);
        $this->put("/reservas/{$r->numero}", $this->dados(['quantidade' => [(string) $livre]]))->assertSessionHasNoErrors();
        $this->put("/reservas/{$r->numero}", $this->dados(['quantidade' => [(string) ($livre + 1)]]))->assertSessionHasErrors('reserva');

        $this->post("/reservas/{$r->numero}/cancelar")->assertRedirect(route('reservas.show', $r->numero));
        $this->assertSame('cancelada', DB::selectOne('SELECT status FROM reservas WHERE id = ?', [$r->id])->status);
        $this->post("/reservas/{$r->numero}/cancelar")->assertSessionHas('flash', [['tipo' => 'erro', 'msg' => 'Só é possível cancelar reserva ativa.']]);
        $this->get("/reservas/{$r->numero}/editar")->assertRedirect(route('reservas.show', $r->numero));
        $this->put("/reservas/{$r->numero}", $this->dados())->assertSessionHasErrors(['reserva' => 'Só é possível alterar reserva ativa.']);
    }

    public function test_recusas(): void
    {
        $this->entrarComo();
        foreach ([
            'deve começar hoje ou depois'         => ['data_inicio' => now()->subDay()->toDateString()],
            'não pode ser antes da retirada'      => ['data_fim' => now()->addDays(5)->toDateString()],
            'Inclua pelo menos um item na reserva.' => ['produto_id' => ['']],
            'Estoque insuficiente'                => ['quantidade' => ['99999']],
            'Cliente não encontrado'              => ['cliente' => 'Fulano Inexistente'],
        ] as $msg => $mudar) {
            $this->post('/reservas', $this->dados($mudar))->assertSessionHasErrors('reserva');
            $this->assertStringContainsString($msg, session('errors')->first('reserva'), "caso: $msg");
        }
        DB::update("UPDATE produtos SET ativo = false WHERE codigo = 'ESC-300'");
        $this->post('/reservas', $this->dados())->assertSessionHasErrors(['reserva' => 'Produto não encontrado ou inativo.']);
        $this->assertNull(DB::selectOne("SELECT id FROM reservas WHERE obs = 'TESTE reserva'"));
    }

    public function test_beta_nao_ve_nem_mexe_em_reserva_da_alfa(): void
    {
        $r = $this->lerComoAdmin("SELECT r.numero, r.id FROM reservas r JOIN tenants t ON t.id = r.tenant_id WHERE t.slug = 'alfa-locacoes' AND r.status = 'ativa'
                                   AND NOT EXISTS (SELECT 1 FROM reservas b JOIN tenants tb ON tb.id = b.tenant_id WHERE tb.slug = 'beta-eventos' AND b.numero = r.numero) LIMIT 1")[0] ?? null;
        if ($r === null) {
            $this->markTestSkipped('Sem reserva da Alfa com número que a Beta não tenha.');
        }
        $this->entrarComo('beta-eventos');
        $this->get("/reservas/{$r->numero}")->assertNotFound();
        $this->get("/reservas/{$r->numero}/editar")->assertNotFound();
        $this->post("/reservas/{$r->numero}/cancelar")->assertNotFound();
        $this->get('/locacoes/nova?reserva=' . $r->id)->assertNotFound();
        $this->assertSame('ativa', $this->lerComoAdmin('SELECT status FROM reservas WHERE id = ?', [$r->id])[0]->status);
    }
}
